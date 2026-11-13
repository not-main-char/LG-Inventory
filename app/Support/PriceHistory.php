<?php

namespace App\Support;

use Carbon\Carbon;
use Kreait\Firebase\Contract\Firestore;

/**
 * Append-only record of price changes, used by the Price Reference history tab.
 *
 * Two things get recorded:
 *  - a farm price set or cleared by hand (from PriceReferenceController), and
 *  - a Region I feed update, captured in PriceBook::store() before the feed
 *    document is overwritten.
 */
class PriceHistory
{
    public const COLLECTION = 'price_history';

    protected $db;

    public function __construct(Firestore $firestore)
    {
        $this->db = $firestore->database();
    }

    /** Add one entry. Rows are never updated or deleted. */
    public function record(array $entry): void
    {
        $this->db->collection(self::COLLECTION)->newDocument()->set([
            'product'   => strtoupper(trim((string) ($entry['product'] ?? ''))),
            'unit'      => $entry['unit'] ?? 'kilos',
            'oldPrice'  => $entry['oldPrice'] !== null ? round((float) $entry['oldPrice'], 2) : null,
            'newPrice'  => $entry['newPrice'] !== null ? round((float) $entry['newPrice'], 2) : null,
            'source'    => (string) ($entry['source'] ?? ''),
            'reason'    => (string) ($entry['reason'] ?? ''),
            'changedBy' => (string) ($entry['changedBy'] ?? ''),
            'asOf'      => $entry['asOf'] ?? null,
            'changedAt' => Carbon::now('Asia/Manila'),
        ]);
    }

    /**
     * Compare the stored Region I items with a freshly fetched list and record
     * every per-kilo change, so the price that was active before an update is
     * still visible afterwards.
     */
    public function recordFeedChanges(array $oldItems, array $newItems, ?string $asOf = null): int
    {
        $before = [];
        foreach ($oldItems as $item) {
            $key = $this->nameKey($item['name'] ?? '');
            if ($key !== '') {
                $before[$key] = $item;
            }
        }

        $recorded = 0;
        foreach ($newItems as $item) {
            $key = $this->nameKey($item['name'] ?? '');
            if ($key === '' || !isset($before[$key])) {
                continue;
            }

            $old = (float) ($before[$key]['perKg'] ?? 0);
            $new = (float) ($item['perKg'] ?? 0);
            if ($old <= 0 || $new <= 0 || abs($new - $old) < 0.005) {
                continue;
            }

            $this->record([
                'product'  => $item['name'] ?? '',
                'unit'     => 'kilos',
                'oldPrice' => $old,
                'newPrice' => $new,
                'source'   => 'DA Region I',
                'reason'   => 'DA/PSA update',
                'asOf'     => $asOf,
            ]);
            $recorded++;
        }

        return $recorded;
    }

    /** Every entry, newest first. */
    public function all(int $limit = 500): array
    {
        $rows = [];

        foreach ($this->db->collection(self::COLLECTION)->documents() as $doc) {
            if (!$doc->exists()) {
                continue;
            }

            $d = $doc->data();
            $when = $this->toDateTime($d['changedAt'] ?? null);

            $rows[] = [
                'id'        => $doc->id(),
                'product'   => (string) ($d['product'] ?? ''),
                'unit'      => (string) ($d['unit'] ?? 'kilos'),
                'oldPrice'  => isset($d['oldPrice']) ? (float) $d['oldPrice'] : null,
                'newPrice'  => isset($d['newPrice']) ? (float) $d['newPrice'] : null,
                'source'    => (string) ($d['source'] ?? ''),
                'reason'    => (string) ($d['reason'] ?? ''),
                'changedBy' => (string) ($d['changedBy'] ?? ''),
                'asOf'      => $d['asOf'] ?? null,
                'changedAt' => $when ? $when->format('Y-m-d H:i:s') : '',
                '_sort'     => $when ? $when->getTimestamp() : 0,
            ];
        }

        usort($rows, fn ($a, $b) => $b['_sort'] <=> $a['_sort']);
        $rows = array_slice($rows, 0, $limit);

        foreach ($rows as &$row) {
            unset($row['_sort']);
        }

        return $rows;
    }

    protected function toDateTime($value): ?\DateTimeInterface
    {
        try {
            if ($value instanceof \Google\Cloud\Core\Timestamp) {
                return $value->get();
            }
            if (is_object($value) && method_exists($value, 'toDateTime')) {
                return $value->toDateTime();
            }
            if (is_object($value) && method_exists($value, 'get')) {
                return $value->get();
            }
            if ($value instanceof \DateTimeInterface) {
                return $value;
            }
            if (is_string($value) && $value !== '') {
                return new \DateTime($value);
            }
        } catch (\Throwable $e) {
            return null;
        }

        return null;
    }

    /** Feed names differ only in spacing and punctuation; compare them plainly. */
    protected function nameKey(string $name): string
    {
        $key = preg_replace('/[^A-Z0-9]+/', ' ', strtoupper(trim($name)));

        return trim((string) $key);
    }
}
