<?php

namespace App\Support;

use Illuminate\Support\Str;
use Kreait\Firebase\Contract\Firestore;

/**
 * Reads harvest batches and the price reference table from Firestore and
 * answers "what can be sold right now?". Shared by the Harvest page and the
 * Sales (Income) page so both always agree on the available stock.
 *
 * A harvest batch's remaining quantity is only ever changed inside Firestore
 * transactions (sales and disposals), so two people can't sell the same kilos.
 */
class HarvestStock
{
    protected $db;

    public function __construct(Firestore $firestore)
    {
        $this->db = $firestore->database();
    }

    /** Document id used for a product's row in the price_reference collection. */
    public static function priceId(string $product, string $unit): string
    {
        return Str::slug($product . ' ' . $unit);
    }

    /** Active (not archived) harvest batches, oldest harvest first. */
    public function activeHarvests(): array
    {
        $rows = [];
        foreach ($this->db->collection('harvests')->documents() as $doc) {
            if (!$doc->exists()) {
                continue;
            }
            $d = $doc->data();
            if (($d['status'] ?? 'active') === 'archived') {
                continue;
            }
            $rows[] = [
                'id'          => $doc->id(),
                'product'     => $d['product'] ?? '',
                'kind'        => $d['kind'] ?? 'plant',
                'source'      => $d['source'] ?? '',
                'unit'        => $d['unit'] ?? 'kilos',
                'quantity'    => (float) ($d['quantity'] ?? 0),
                'remaining'   => (float) ($d['remainingQuantity'] ?? 0),
                'harvestDate' => $d['harvestDate'] ?? '',
                'notes'       => $d['notes'] ?? '',
                'seedItemId'  => $d['seedItemId'] ?? null,
            ];
        }
        usort($rows, fn ($a, $b) => [$a['harvestDate'], $a['id']] <=> [$b['harvestDate'], $b['id']]);

        return $rows;
    }

    /** Price reference rows keyed by their document id. */
    public function priceReferences(): array
    {
        $rows = [];
        foreach ($this->db->collection('price_reference')->documents() as $doc) {
            if (!$doc->exists()) {
                continue;
            }
            $d = $doc->data();
            $rows[$doc->id()] = [
                'id'          => $doc->id(),
                'product'     => $d['product'] ?? '',
                'unit'        => $d['unit'] ?? 'kilos',
                'retailPrice' => (float) ($d['retailPrice'] ?? 0),
                'note'        => $d['note'] ?? '',
                'updatedOn'   => $d['updatedOn'] ?? '',
            ];
        }
        uasort($rows, fn ($a, $b) => strcmp($a['product'] . $a['unit'], $b['product'] . $b['unit']));

        return $rows;
    }

    /**
     * Sellable stock grouped by product + unit (all batches added together),
     * each with its reference retail price when one has been set.
     */
    public function availableProducts(?array $harvests = null, ?array $prices = null): array
    {
        $harvests ??= $this->activeHarvests();
        $prices   ??= $this->priceReferences();

        $groups = [];
        foreach ($harvests as $h) {
            if ($h['remaining'] <= 0.00001) {
                continue;
            }
            $key = $h['product'] . '|' . $h['unit'];
            if (!isset($groups[$key])) {
                $price = $prices[self::priceId($h['product'], $h['unit'])] ?? null;
                $groups[$key] = [
                    'key'            => $key,
                    'product'        => $h['product'],
                    'unit'           => $h['unit'],
                    'kind'           => $h['kind'],
                    'remaining'      => 0.0,
                    'price'          => $price['retailPrice'] ?? null,
                    'priceUpdatedOn' => $price['updatedOn'] ?? null,
                ];
            }
            $groups[$key]['remaining'] = round($groups[$key]['remaining'] + $h['remaining'], 4);
        }
        ksort($groups);

        return array_values($groups);
    }
}
