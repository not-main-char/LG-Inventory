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

    protected PriceBook $book;

    public function __construct(Firestore $firestore, PriceBook $book)
    {
        $this->db   = $firestore->database();
        $this->book = $book;
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
                'notes'       => (string) ($d['notes'] ?? ''),
                'seedItemId'  => $d['seedItemId'] ?? null,
                'deviceId'      => $d['deviceId'] ?? null,
                'deviceEventKey' => $d['deviceEventKey'] ?? null,
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
                'id'             => $doc->id(),
                'product'        => $d['product'] ?? '',
                'unit'           => $d['unit'] ?? 'kilos',
                'retailPrice'    => (float) ($d['retailPrice'] ?? 0),
                'wholesalePrice' => isset($d['wholesalePrice']) ? (float) $d['wholesalePrice'] : null,
                'floorPrice'     => isset($d['floorPrice']) ? (float) $d['floorPrice'] : null,
                'ceilingPrice'   => isset($d['ceilingPrice']) ? (float) $d['ceilingPrice'] : null,
                'piecesPerKg'    => isset($d['piecesPerKg']) ? (float) $d['piecesPerKg'] : null,
                'note'           => $d['note'] ?? '',
                'updatedOn'      => $d['updatedOn'] ?? '',
            ];
        }
        uasort($rows, fn ($a, $b) => strcmp($a['product'] . $a['unit'], $b['product'] . $b['unit']));

        return $rows;
    }

    /**
     * Every price in force, keyed by product + unit: the rows saved in the
     * price book, plus a row for each crop currently in stock that is priced
     * from the DA Region I feed or from an estimate. So the Price Reference tab
     * shows what a sale would actually use, not just what an admin typed.
     */
    public function priceList(?array $harvests = null, ?array $prices = null): array
    {
        $prices   ??= $this->priceReferences();
        $harvests ??= $this->activeHarvests();

        $rows = [];
        foreach ($prices as $id => $row) {
            $rows[$id] = $row + ['estimated' => false, 'saved' => true];
        }

        foreach ($harvests as $h) {
            if ($h['remaining'] <= 0.00001) {
                continue;
            }

            $id = self::priceId($h['product'], $h['unit']);
            if (isset($rows[$id])) {
                continue; // an admin price already applies
            }

            $ref = $this->referenceFor($h['product'], $h['unit'], $prices);
            if ($ref['price'] === null) {
                continue; // nobody publishes it and nothing was saved
            }

            $rows[$id] = [
                'id'             => $id,
                'product'        => $h['product'],
                'unit'           => $h['unit'],
                'retailPrice'    => $ref['price'],
                'wholesalePrice' => $ref['wholesale'],
                'floorPrice'     => $ref['floor'],
                'ceilingPrice'   => $ref['ceiling'],
                'piecesPerKg'    => $ref['piecesPerKg'],
                'note'           => $ref['priceNote'],
                'updatedOn'      => $ref['priceUpdatedOn'] ?? '',
                'estimated'      => $ref['priceEstimated'],
                'saved'          => false,
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
                $groups[$key] = $this->referenceFor($h['product'], $h['unit'], $prices) + [
                    'key'       => $key,
                    'product'   => $h['product'],
                    'unit'      => $h['unit'],
                    'kind'      => $h['kind'],
                    'remaining' => 0.0,
                ];
            }
            $groups[$key]['remaining'] = round($groups[$key]['remaining'] + $h['remaining'], 4);
        }
        ksort($groups);

        return array_values($groups);
    }

    /**
     * Where the price for this product starts from.
     *
     * A price saved on the Harvest page always wins. Otherwise the price book
     * answers from the DA Region I list, and for a crop nobody publishes it
     * returns a clearly-marked estimate instead of leaving the box empty.
     */
    protected function referenceFor(string $product, string $unit, array $prices): array
    {
        $empty = [
            'price'          => null,
            'wholesale'      => null,
            'floor'          => null,
            'ceiling'        => null,
            'piecesPerKg'    => null,
            'priceNote'      => '',
            'priceUpdatedOn' => null,
            'priceEstimated' => false,
        ];

        $saved = $prices[self::priceId($product, $unit)] ?? null;
        if ($saved && ($saved['retailPrice'] ?? 0) > 0) {
            return [
                'price'          => $saved['retailPrice'],
                'wholesale'      => $saved['wholesalePrice'],
                'floor'          => $saved['floorPrice'],
                'ceiling'        => $saved['ceilingPrice'],
                'piecesPerKg'    => $saved['piecesPerKg'],
                'priceNote'      => $saved['note'],
                'priceUpdatedOn' => $saved['updatedOn'],
                'priceEstimated' => false,
            ];
        }

        $ref = $this->book->resolve($product, $unit);
        if (!$ref) {
            return $empty;
        }

        return [
            'price'          => $ref['price'],
            'wholesale'      => $ref['wholesale'],
            'floor'          => $ref['floor'],
            'ceiling'        => $ref['ceiling'],
            'piecesPerKg'    => $ref['piecesPerKg'],
            'priceNote'      => $ref['note'],
            'priceUpdatedOn' => $ref['updatedOn'],
            'priceEstimated' => !empty($ref['estimated']),
        ];
    }
}
