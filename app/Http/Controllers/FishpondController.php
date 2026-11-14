<?php

namespace App\Http\Controllers;

use App\Support\FishStock;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Kreait\Firebase\Contract\Database;

class FishpondController extends Controller
{
    protected Database $database;

    public function __construct(Database $database)
    {
        $this->database = $database;
    }

    /**
     * Devices that actually have ponds, so a unit that only sends sensor data
     * (no fishpond_records) does not show an empty pond tab.
     *
     * @return array<string, string>
     */
    protected function pondDevices(FishStock $stock): array
    {
        $found = [];

        foreach ($stock->devices() as $id => $label) {
            try {
                $raw = $this->database->getReference("devices/{$id}/fishpond_records")->getValue();
            } catch (\Throwable $e) {
                $raw = null;
            }

            if (is_array($raw) && $raw !== []) {
                $found[$id] = $label;
            }
        }

        return $found !== [] ? $found : $stock->devices();
    }

    public function index(string $device = 'HYDROLG001')
    {
        $devices = $this->pondDevices(app(FishStock::class));

        if (!array_key_exists($device, $devices)) {
            $device = 'HYDROLG001';
        }

        return view('fishpond.index', [
            'devices'      => $devices,
            'activeDevice' => $device,
        ]);
    }

    /**
     * Pond overview: one card per aquaponics pond with its batch count, species
     * and biomass, plus a way into the full Fish Monitoring page.
     */
    public function ponds(FishStock $fishStock)
    {
        $read = $fishStock->batches();
        $devices = $this->pondDevices($fishStock);

        $ponds = [];
        foreach ($devices as $id => $label) {
            $rows = array_values(array_filter($read['batches'], fn ($b) => $b['deviceLabel'] === $label));

            $species = [];
            $stages = [];
            $biomass = 0.0;
            $fish = 0;

            foreach ($rows as $row) {
                $species[strtoupper($row['species'])] = true;
                if ($row['growthStage'] !== '') {
                    $stages[$row['growthStage']] = true;
                }
                $biomass += $row['biomass'];
                $fish += $row['fishCount'];
            }

            $species = array_keys($species);
            sort($species);
            $stages = array_keys($stages);
            sort($stages);

            $ponds[] = [
                'device'       => $id,
                'label'        => $label,
                'batches'      => count($rows),
                'species'      => $species,
                'speciesCount' => count($species),
                'stages'       => $stages,
                'biomass'      => round($biomass, 2),
                'fish'         => $fish,
            ];
        }

        return view('fishpond.ponds', [
            'ponds'  => $ponds,
            'error'  => $read['error'],
            'devices' => $devices,
        ]);
    }

    /**
     * AJAX endpoint: /fishpond-records?device=HYDROLG001
     *
     * One entry of devices/{device}/fishpond_records is one stocked batch, so
     * the number of records is the number of fish groups being monitored.
     * The page polls this every few seconds; the fingerprint lets it tell a
     * real change from an unchanged response.
     */
    public function records(Request $request)
    {
        $device  = (string) $request->get('device', 'HYDROLG001');
        $devices = $this->pondDevices(app(FishStock::class));
        if (!array_key_exists($device, $devices)) {
            $device = 'HYDROLG001';
        }

        $now = Carbon::now('Asia/Manila');

        try {
            $raw = $this->database
                ->getReference("devices/{$device}/fishpond_records")
                ->getValue();
        } catch (\Throwable $e) {
            return response()->json([
                'device'    => $device,
                'error'     => 'Could not read fishpond_records from the Realtime Database.',
                'detail'    => $e->getMessage(),
                'records'   => [],
                'fetchedAt' => $now->toIso8601String(),
            ], 200)->header('Cache-Control', 'no-store');
        }

        $records = [];
        foreach ((array) $raw as $key => $row) {
            if (!is_array($row)) {
                continue;
            }
            $records[] = $this->normalizeRecord((string) $key, $row, $now);
        }

        // One card per batch. A batch can have several records — its creation and
        // later mortality or sampling events — so the newest record is today's
        // stock, and the dead fish are carried along as a tally to show.
        $batches = [];
        foreach ($records as $rec) {
            $id = $rec['batchId'] !== '' ? $rec['batchId'] : $rec['key'];
            if (!isset($batches[$id])) {
                $batches[$id] = ['latest' => $rec, 'dead' => 0, 'reasons' => []];
            }
            if ($rec['recordType'] === 'mortality') {
                $batches[$id]['dead'] += $rec['mortalityCount'];
                if ($rec['mortalityReason'] !== '') {
                    $batches[$id]['reasons'][$rec['mortalityReason']] = true;
                }
            }
            if (strcmp((string) $rec['createdSort'], (string) $batches[$id]['latest']['createdSort']) >= 0) {
                $batches[$id]['latest'] = $rec;
            }
        }

        $records = [];
        foreach ($batches as $group) {
            $row = $group['latest'];
            $row['deadCount'] = $group['dead'];
            $row['mortalityReason'] = implode(', ', array_keys($group['reasons']));
            $records[] = $row;
        }

        // Newest batch first; fall back to the push key when dates are missing.
        usort($records, function ($a, $b) {
            $byDate = strcmp((string) $b['createdSort'], (string) $a['createdSort']);
            return $byDate !== 0 ? $byDate : strcmp($a['batchId'], $b['batchId']);
        });

        $fingerprint = md5(json_encode(array_map(
            fn ($r) => [$r['batchId'], $r['fishCount'], $r['averageWeight'], $r['biomass'], $r['growthStage'], $r['updatedMs']],
            $records
        )));

        return response()->json([
            'device'      => $device,
            'deviceLabel' => $devices[$device],
            'fetchedAt'   => $now->toIso8601String(),
            'fingerprint' => $fingerprint,
            'summary'     => [
                // One batch can have several event records (mortality, sampling),
                // so count distinct batches rather than rows.
                'batches'      => count(array_unique(array_map(fn ($r) => $r['batchId'], $records))),
                'fish'         => (int) array_sum(array_column($records, 'fishCount')),
                'biomassKg'    => round(array_sum(array_column($records, 'biomass')), 2),
                'species'      => count(array_unique(array_column($records, 'species'))),
                'harvestDue'   => count(array_filter($records, fn ($r) => $r['daysToHarvest'] !== null && $r['daysToHarvest'] <= 0)),
            ],
            'records' => $records,
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * Turn one RTDB batch record into the shape the page renders. Every field
     * is read defensively: the mobile app writes numbers, but RTDB hands back
     * strings after certain edits, and old records may miss fields entirely.
     */
    protected function normalizeRecord(string $key, array $row, Carbon $now): array
    {
        $batchId = trim((string) ($row['batchId'] ?? '')) ?: $key;
        // Species is stored the way the mobile app sent it, so "hito" and "Hito"
        // can both exist. Everything is shown uppercase so the same spelling in
        // any casing reads — and counts — as one species.
        $species = strtoupper(preg_replace('/\s+/', ' ', trim((string) ($row['species'] ?? '')))) ?: 'Unspecified';
        $growthStage = trim((string) ($row['growthStage'] ?? '')) ?: 'Unknown';
        $recordType = trim((string) ($row['recordType'] ?? '')) ?: 'record';
        $mortalityCount = max(0, (int) round($this->number($row['mortalityCount'] ?? null)));
        $mortalityReason = trim((string) ($row['mortalityReason'] ?? ''));

        $fishCount = max(0, (int) round($this->number($row['fishCount'] ?? null)));
        $averageWeight = round($this->number($row['averageWeight'] ?? null), 2); // grams
        $biomass = round($this->number($row['biomass'] ?? null), 3);              // kilograms

        // Biomass is stored, but derive it when the record has none.
        if ($biomass <= 0 && $fishCount > 0 && $averageWeight > 0) {
            $biomass = round(($fishCount * $averageWeight) / 1000, 3);
        }

        $expectedGrowthDays = max(0, (int) round($this->number($row['expectedGrowthDays'] ?? null)));

        $stocking = $this->toDate($row['stockingDate'] ?? null);
        $created  = $this->toDate($row['createdAt'] ?? null) ?? $this->toDate($row['timestamp'] ?? null);
        $updated  = $this->toDate($row['updatedAt'] ?? null);
        $harvest  = $this->toDate($row['expectedHarvest'] ?? null);

        if (!$harvest && $stocking && $expectedGrowthDays > 0) {
            $harvest = $stocking->copy()->addDays($expectedGrowthDays);
        }

        $ageDays = $stocking
            ? (int) round($stocking->copy()->startOfDay()->diffInDays($now->copy()->startOfDay()))
            : null;

        $daysToHarvest = $harvest
            ? (int) round($now->copy()->startOfDay()->diffInDays($harvest->copy()->startOfDay(), false))
            : null;

        $progress = ($ageDays !== null && $expectedGrowthDays > 0)
            ? (int) max(0, min(100, round(($ageDays / $expectedGrowthDays) * 100)))
            : null;

        return [
            // RTDB push key: unique per record, even when two records share a batchId.
            'key'                => $key,
            'batchId'            => $batchId,
            'species'            => $species,
            'growthStage'        => $growthStage,
            'recordType'         => $recordType,
            'mortalityCount'     => $mortalityCount,
            'mortalityReason'    => $mortalityReason,
            'fishCount'          => $fishCount,
            'averageWeight'      => $averageWeight,
            'biomass'            => $biomass,
            'ageDays'            => $ageDays,
            'expectedGrowthDays' => $expectedGrowthDays,
            'progress'           => $progress,
            'daysToHarvest'      => $daysToHarvest,
            'stockingDate'       => $stocking?->toIso8601String(),
            'expectedHarvest'    => $harvest?->toIso8601String(),
            'createdAt'          => $created?->toIso8601String(),
            'createdSort'        => $created?->format('Y-m-d H:i:s') ?? '',
            'updatedAt'          => $updated?->toIso8601String(),
            'updatedMs'          => $updated?->valueOf(),
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
        if ($value === null || $value === '' || is_bool($value)) {
            return null;
        }

        try {
            if (is_array($value)) {
                $value = reset($value);
            }

            if (is_numeric($value)) {
                $number = (float) $value;

                if ($number >= 1e12) { // milliseconds
                    return Carbon::createFromTimestampMs((int) $number, 'Asia/Manila');
                }
                if ($number >= 1e9) { // seconds
                    return Carbon::createFromTimestamp((int) $number, 'Asia/Manila');
                }

                return null;
            }

            return Carbon::parse((string) $value, 'Asia/Manila');
        } catch (\Throwable $e) {
            return null;
        }
    }
}
