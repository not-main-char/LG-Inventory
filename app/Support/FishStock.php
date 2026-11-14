<?php

namespace App\Support;

use Carbon\Carbon;
use Kreait\Firebase\Contract\Database;
use Kreait\Firebase\Contract\Firestore;

/**
 * Fish stock that comes straight from the ponds.
 *
 * The mobile app writes one Realtime Database record per stocked batch under
 * devices/{device}/fishpond_records. Sales deduct from Firestore harvest
 * documents, so each pond batch is mirrored into a harvest document with
 * kind=fish: that keeps one selling path for plants and fish.
 *
 * Quantities are kilograms of biomass (species, count and average weight are
 * kept alongside). A sale lowers `remainingQuantity`; the next sync recomputes
 * what is left as biomass minus everything sold so far, so selling never
 * resurrects stock when the pond reports new weights.
 */
class FishStock
{
    /**
     * Names for the devices we know. Used as the label fallback; the list of
     * devices itself is read from `devices` so a new unit needs no code change.
     */
    public const DEVICES = [
        'HYDROLG001' => 'Aquaponics 1',
        'HYDROLG002' => 'Aquaponics 2',
    ];

    /** How long the discovered device list is cached, in seconds. */
    protected const DEVICE_CACHE_SECONDS = 120;

    protected $db;
    protected $rtdb;

    public function __construct(Firestore $firestore, Database $rtdb)
    {
        $this->db = $firestore->database();
        $this->rtdb = $rtdb;
    }

    /**
     * Every batch the ponds currently report.
     *
     * @return array{batches: array, error: ?string}
     */
    /**
     * Every device that has written to `devices`, with the name it reports.
     *
     * A new HydroSolar unit shows up here as soon as it writes anything, which
     * is why the pond page, Sensor History and the sensor report all use this
     * instead of a typed-in list.
     *
     * @return array<string, string> device id => label
     */
    public function devices(bool $fresh = false): array
    {
        $read = function () {
            $devices = [];

            try {
                $ids = $this->rtdb->getReference('devices')->getChildKeys();
            } catch (\Throwable $e) {
                \Log::warning('Device list could not be read: ' . $e->getMessage());

                return self::DEVICES;
            }

            foreach ($ids as $id) {
                $label = self::DEVICES[$id] ?? '';

                if ($label === '') {
                    $label = $this->reportedName($id);
                }

                $devices[$id] = $label;
            }

            ksort($devices);

            return $devices !== [] ? $devices : self::DEVICES;
        };

        if ($fresh) {
            return $read();
        }

        return \Illuminate\Support\Facades\Cache::remember('rtdb_devices', self::DEVICE_CACHE_SECONDS, $read);
    }

    /** The name a device calls itself, or a readable fallback from its id. */
    protected function reportedName(string $id): string
    {
        foreach (["devices/{$id}", "devices/{$id}/sensor"] as $path) {
            try {
                $node = $this->rtdb->getReference($path)->getValue();
            } catch (\Throwable $e) {
                $node = null;
            }

            if (is_array($node) && !empty($node['deviceName'])) {
                return (string) $node['deviceName'];
            }
        }

        // HYDROLG003 -> Aquaponics 3, so a new unit still reads properly.
        if (preg_match('/(\d+)$/', $id, $m)) {
            return 'Aquaponics ' . (int) $m[1];
        }

        return $id;
    }

    public function batches(): array
    {
        $batches = [];

        foreach ($this->devices() as $device => $label) {
            try {
                $raw = $this->rtdb->getReference("devices/{$device}/fishpond_records")->getValue();
            } catch (\Throwable $e) {
                \Log::warning("Fish pond {$device} could not be read: " . $e->getMessage());

                return ['batches' => $batches, 'error' => 'Could not read the fish ponds from Firebase.'];
            }

            // A batch has one creation record plus later mortality or sampling
            // events. They are one batch: the newest record is the stock that is
            // left today, and dead fish are counted alongside it, never as stock.
            $groups = [];
            foreach ((array) $raw as $key => $row) {
                if (!is_array($row)) {
                    continue;
                }
                $rec = $this->normalize((string) $key, $row, $device, $label);
                if ($rec === null) {
                    continue;
                }
                $id = $rec['batchId'] !== '' ? $rec['batchId'] : (string) $key;
                if (!isset($groups[$id])) {
                    $groups[$id] = ['latest' => $rec, 'dead' => 0];
                }
                if ($rec['recordType'] === 'mortality') {
                    $groups[$id]['dead'] += $rec['mortalityCount'];
                }
                if (strcmp($rec['sortKey'], $groups[$id]['latest']['sortKey']) >= 0) {
                    $groups[$id]['latest'] = $rec;
                }
            }

            foreach ($groups as $group) {
                $batch = $group['latest'];
                $batch['deadCount'] = $group['dead'];
                $batches[] = $batch;
            }
        }

        usort($batches, fn ($a, $b) => strcmp($b['sortKey'], $a['sortKey']));

        return ['batches' => $batches, 'error' => null];
    }

    /**
     * Mirror the pond batches into harvest documents so they can be sold.
     *
     * @return array{created: int, updated: int, unchanged: int, error: ?string}
     */
    public function sync(): array
    {
        $result = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'error' => null];
        $read = $this->batches();
        $result['error'] = $read['error'];

        foreach ($read['batches'] as $batch) {
            if ($batch['species'] === '') {
                continue;
            }

            try {
                $ref = $this->db->collection('harvests')->document($batch['docId']);
                $snap = $ref->snapshot();
                $prev = $snap->exists() ? $snap->data() : null;

                // An archived fish record stays archived; the pond must not
                // bring it back on the next page load.
                if ($prev !== null && ($prev['status'] ?? 'active') === 'archived') {
                    $result['unchanged']++;
                    continue;
                }

                // Quantity is biomass at the last sync, so the gap between it and
                // what is left is exactly what was sold since then.
                $soldBefore = $prev !== null ? (float) ($prev['soldQuantity'] ?? 0) : 0.0;
                $soldSince = $prev !== null
                    ? max(0.0, (float) ($prev['quantity'] ?? 0) - (float) ($prev['remainingQuantity'] ?? 0))
                    : 0.0;
                $soldTotal = round($soldBefore + $soldSince, 3);
                $remaining = round(max(0.0, $batch['biomass'] - $soldTotal), 3);

                $product = strtoupper($batch['species']);

                if ($prev !== null
                    && (string) ($prev['product'] ?? '') === $product
                    && abs((float) ($prev['quantity'] ?? 0) - $batch['biomass']) < 0.001
                    && abs((float) ($prev['remainingQuantity'] ?? 0) - $remaining) < 0.001
                    && (string) ($prev['growthStage'] ?? '') === $batch['growthStage']
                    && (string) ($prev['batch'] ?? '') === $batch['batch']
                    && (int) ($prev['fishCount'] ?? 0) === $batch['fishCount']
                    && (int) ($prev['deadCount'] ?? 0) === (int) $batch['deadCount']) {
                    $result['unchanged']++;
                    continue;
                }

                $ref->set([
                    'product'           => $product,
                    'kind'              => 'fish',
                    'source'            => 'aquaculture',
                    'unit'              => 'kilos',
                    'quantity'          => $batch['biomass'],
                    'remainingQuantity' => $remaining,
                    'soldQuantity'      => $soldTotal,
                    'harvestDate'       => $batch['harvestDate'],
                    'growthStage'       => $batch['growthStage'],
                    'batch'             => $batch['batch'],
                    'batchId'           => $batch['batchId'],
                    'deviceKey'         => $batch['deviceKey'],
                    'deviceLabel'       => $batch['deviceLabel'],
                    'fishCount'         => $batch['fishCount'],
                    // Dead fish are recorded for display only: they are never stock.
                    'deadCount'         => (int) $batch['deadCount'],
                    'stockedCount'      => (int) $batch['fishCount'] + (int) $batch['deadCount'],
                    'averageWeight'     => $batch['averageWeight'],
                    'notes'             => (string) ($prev['notes'] ?? ''),
                    'status'            => 'active',
                    'createdAt'         => $prev['createdAt'] ?? Carbon::now('Asia/Manila'),
                    'updatedAt'         => Carbon::now('Asia/Manila'),
                ], ['merge' => true]);

                $prev === null ? $result['created']++ : $result['updated']++;
            } catch (\Throwable $e) {
                \Log::warning('Fish batch sync failed for ' . $batch['docId'] . ': ' . $e->getMessage());
                $result['error'] = 'Some fish batches could not be saved.';
            }
        }

        return $result;
    }

    /** One RTDB record, read defensively: numbers arrive as strings after edits. */
    protected function normalize(string $key, array $row, string $device, string $label): ?array
    {
        $species = trim((string) ($row['species'] ?? ''));
        if ($species === '') {
            return null;
        }

        // RTDB keeps one record per event. batch_created, mortality and sampling
        // rows all describe the same batch, so the caller groups them and keeps
        // the newest as the stock that is left.
        $recordType = trim((string) ($row['recordType'] ?? '')) ?: 'record';

        $growthStage = trim((string) ($row['growthStage'] ?? ''));
        $batchId = trim((string) ($row['batchId'] ?? '')) ?: $key;

        $fishCount = max(0, (int) round($this->number($row['fishCount'] ?? null)));
        $averageWeight = round($this->number($row['averageWeight'] ?? null), 2); // grams
        $biomass = round($this->number($row['biomass'] ?? null), 3);              // kilograms
        if ($biomass <= 0 && $fishCount > 0 && $averageWeight > 0) {
            $biomass = round(($fishCount * $averageWeight) / 1000, 3);
        }

        $stocking = $this->toDate($row['stockingDate'] ?? null);
        $created  = $this->toDate($row['createdAt'] ?? null)
            ?? $this->toDate($row['timestamp'] ?? null);
        $updated  = $this->toDate($row['updatedAt'] ?? null);
        $eventAt  = $updated ?? $created ?? $stocking ?? Carbon::now('Asia/Manila');

        return [
            // Stable per batch, not per record: a mortality event must update the
            // same harvest document its batch already has.
            'docId'        => 'fish-' . substr(sha1($device . '|' . $batchId), 0, 20),
            'recordType'   => $recordType,
            'mortalityCount' => max(0, (int) round($this->number($row['mortalityCount'] ?? null))),
            'deviceKey'    => $device . ':' . $key,
            'deviceLabel'  => $label,
            'species'      => $species,
            'growthStage'  => $growthStage !== '' ? $growthStage : 'Unknown',
            'batchId'      => $batchId,
            'batch'        => $label,
            'fishCount'    => $fishCount,
            'averageWeight' => $averageWeight,
            'biomass'      => $biomass,
            'harvestDate'  => ($stocking ?? $created ?? Carbon::now('Asia/Manila'))->format('Y-m-d'),
            'sortKey'      => $eventAt->format('Y-m-d H:i:s'),
        ];
    }

    protected function number($value): float
    {
        if (is_array($value)) {
            $value = reset($value);
        }

        return is_numeric($value) ? (float) $value : 0.0;
    }

    protected function toDate($value): ?Carbon
    {
        if ($value instanceof \Google\Cloud\Core\Timestamp) {
            return Carbon::instance($value->get());
        }

        try {
            if (is_numeric($value) && (float) $value > 1000000000) {
                return Carbon::createFromTimestampMs((int) $value, 'Asia/Manila');
            }
            if (is_string($value) && trim($value) !== '') {
                return Carbon::parse($value, 'Asia/Manila');
            }
        } catch (\Throwable $e) {
            return null;
        }

        return null;
    }
}
