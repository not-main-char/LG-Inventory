<?php

namespace App\Support;

use Carbon\Carbon;
use Kreait\Firebase\Contract\Database;

/**
 * Reads devices/{device}/planting_events from the Realtime Database and keeps
 * only the events the mobile app recorded as "harvested".
 *
 * planting_events holds the whole life of a batch: "planted" events are still
 * in the ground, so they are not harvests and are deliberately skipped. Each
 * harvested event carries the fields the device writes (batchId, date,
 * description, harvestedQuantity, remainingQuantity, notes, status, type).
 *
 * The Realtime Database is the device's own book; selling still happens in
 * Firestore. importId() gives each event a stable Firestore document id so an
 * event can only ever be added to sale stock once.
 */
class DeviceHarvests
{
    protected Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * All harvested events across all devices, newest first.
     *
     * @param  array  $stockIds  Firestore harvest document ids already in sale stock.
     * @return array{rows: array, summary: array, error: ?string}
     */
    public function harvests(array $stockIds = []): array
    {
        $stockIds = array_flip(array_map('strval', $stockIds));
        $rows     = [];

        try {
            foreach ($this->deviceIds() as $device) {
                $batches = $this->db->getReference("devices/{$device}/planting_events")->getValue();
                if (!is_array($batches)) {
                    continue;
                }

                foreach ($batches as $batchId => $events) {
                    if (!is_array($events)) {
                        continue;
                    }
                    foreach ($events as $eventKey => $event) {
                        if (!is_array($event) || !$this->isHarvested($event)) {
                            continue;
                        }
                        $rows[] = $this->normalize($device, (string) $batchId, (string) $eventKey, $event, $stockIds);
                    }
                }
            }
        } catch (\Throwable $e) {
            return [
                'rows'    => [],
                'summary' => $this->summary([]),
                'error'   => 'Could not read planting events from the Realtime Database: ' . $e->getMessage(),
            ];
        }

        usort($rows, fn ($a, $b) => strcmp($b['sortKey'], $a['sortKey']));

        return [
            'rows'    => $rows,
            'summary' => $this->summary($rows),
            'error'   => null,
        ];
    }

    /**
     * One harvested event straight from the device, used before importing so the
     * saved stock can be checked against what the device actually wrote.
     */
    public function find(string $device, string $eventKey): ?array
    {
        try {
            $batches = $this->db->getReference("devices/{$device}/planting_events")->getValue();
        } catch (\Throwable $e) {
            return null;
        }

        if (!is_array($batches)) {
            return null;
        }

        foreach ($batches as $batchId => $events) {
            if (!is_array($events) || !isset($events[$eventKey]) || !is_array($events[$eventKey])) {
                continue;
            }
            if (!$this->isHarvested($events[$eventKey])) {
                return null;
            }

            return $this->normalize($device, (string) $batchId, $eventKey, $events[$eventKey], []);
        }

        return null;
    }

    /** Stable Firestore document id for one device harvest event. */
    public static function importId(string $device, string $eventKey): string
    {
        $slug = preg_replace('/[^A-Za-z0-9]+/', '-', $device . '-' . $eventKey);

        return 'dev-' . substr(trim($slug, '-'), 0, 200);
    }

    protected function isHarvested(array $event): bool
    {
        return strtolower(trim((string) ($event['type'] ?? ''))) === 'harvested';
    }

    protected function normalize(string $device, string $batchId, string $eventKey, array $e, array $stockIds): array
    {
        $harvested = $this->number($e['harvestedQuantity'] ?? $e['quantity'] ?? null);
        $remaining = $this->number($e['remainingQuantity'] ?? null);
        $date      = $this->toDate($e['date'] ?? $e['createdAt'] ?? null);
        $stockId   = self::importId($device, $eventKey);

        return [
            'deviceId'    => $device,
            'batchId'     => trim((string) ($e['batchId'] ?? '')) ?: $batchId,
            'eventKey'    => $eventKey,
            'description' => trim((string) ($e['description'] ?? '')),
            'product'     => $this->cropName($e),
            'harvested'   => $harvested,
            'remaining'   => $remaining,
            'status'      => trim((string) ($e['status'] ?? '')) ?: '—',
            'notes'       => trim((string) ($e['notes'] ?? '')),
            'harvestDate' => $date?->format('Y-m-d') ?? '',
            'dateLabel'   => $date?->format('M d, Y') ?? '—',
            'sortKey'     => $date?->format('Y-m-d H:i:s') ?? '',
            'stockId'     => $stockId,
            'inStock'     => isset($stockIds[$stockId]),
        ];
    }

    protected function summary(array $rows): array
    {
        return [
            'events'    => count($rows),
            'harvested' => round(array_sum(array_column($rows, 'harvested')), 4),
            'remaining' => round(array_sum(array_column($rows, 'remaining')), 4),
            'pending'   => count(array_filter($rows, fn ($r) => !$r['inStock'])),
            'devices'   => count(array_unique(array_column($rows, 'deviceId'))),
        ];
    }

    /**
     * The crop name. The app writes free text ("Completed harvest of Pechay")
     * and has no dedicated crop field, so a product/crop field wins when one
     * exists and the description is parsed as a fallback.
     */
    protected function cropName(array $e): string
    {
        foreach (['product', 'crop', 'cropName'] as $field) {
            $value = trim((string) ($e[$field] ?? ''));
            if ($value !== '') {
                return strtoupper($value);
            }
        }

        $description = trim((string) ($e['description'] ?? ''));
        if ($description !== '' && preg_match('/\bof\s+(?:[\d.,]+\s+)?(.+)$/i', $description, $m)) {
            $name = trim(preg_replace('/\s+/', ' ', $m[1]));
            if ($name !== '') {
                return strtoupper($name);
            }
        }

        return 'UNSPECIFIED';
    }

    protected function deviceIds(): array
    {
        try {
            $ids = $this->db->getReference('devices')->getChildKeys();
        } catch (\Throwable $e) {
            $ids = [];
        }

        if (!$ids) {
            $ids = ['HYDROLG001', 'HYDROLG002'];
        }

        return array_values(array_filter(array_map('strval', $ids)));
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
