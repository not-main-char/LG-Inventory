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
                // Same name in any casing is the same product: device records can
                // arrive as "hito" while the farm recorded "HITO".
                'product'     => strtoupper(preg_replace('/\s+/', ' ', trim((string) ($d['product'] ?? '')))),
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
                'growthStage'  => (string) ($d['growthStage'] ?? ''),
                'batch'        => (string) ($d['batch'] ?? ''),
                'fishCount'    => (int) ($d['fishCount'] ?? 0),
                'deadCount'    => (int) ($d['deadCount'] ?? 0),
                'stockedCount' => (int) ($d['stockedCount'] ?? 0),
                // Fish are stocked by count but sold by weight, so the count that
                // is left follows the weight that is left.
                'remainingFish' => $this->remainingFish(
                    (int) ($d['fishCount'] ?? 0),
                    (float) ($d['quantity'] ?? 0),
                    (float) ($d['remainingQuantity'] ?? 0)
                ),
                'createdSort'  => $this->createdSort($d['createdAt'] ?? null),
            ];
        }

        // Harvest date first, then the moment it was recorded: two harvests on
        // the same day used to fall back to the random document id, which put
        // the newest one anywhere in the list.
        usort($rows, fn ($a, $b) => [$a['harvestDate'], $a['createdSort'], $a['id']]
            <=> [$b['harvestDate'], $b['createdSort'], $b['id']]);

        return $rows;
    }

    /** How many of a batch's fish are still there, from the weight left. */
    protected function remainingFish(int $fishCount, float $quantity, float $remaining): int
    {
        if ($fishCount <= 0 || $quantity <= 0.00001) {
            return 0;
        }

        return max(0, (int) round($fishCount * ($remaining / $quantity)));
    }

    /** Sortable timestamp for a harvest, whatever shape it was stored in. */
    protected function createdSort($value): string
    {
        try {
            if ($value instanceof \Google\Cloud\Core\Timestamp) {
                return $value->get()->format('Y-m-d H:i:s');
            }
            if (is_object($value) && method_exists($value, 'toDateTime')) {
                return $value->toDateTime()->format('Y-m-d H:i:s');
            }
            if ($value instanceof \DateTimeInterface) {
                return $value->format('Y-m-d H:i:s');
            }
            if (is_string($value) && trim($value) !== '') {
                return \Carbon\Carbon::parse($value)->format('Y-m-d H:i:s');
            }
        } catch (\Throwable $e) {
            return '';
        }

        return '';
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
            $rows[$id] = $row + [
                'estimated' => false,
                'saved'     => true,
                'stage'     => '',
                'seed'      => false,
                'kind'      => $this->kindOf($row['product'], $harvests),
            ];
        }

        // Every fish stage now has its own price, so the list follows the same
        // rows the sale form offers instead of one row per product name.
        foreach ($this->availableProducts($harvests, $prices) as $a) {
            $id = self::priceId($a['product'], $a['unit']);
            if ($a['stage'] !== '') {
                $id .= '|' . $a['stage'];
            }
            if (isset($rows[$id])) {
                continue; // an admin price already applies
            }
            if ($a['price'] === null) {
                continue; // nobody publishes it and nothing was saved
            }

            $rows[$id] = [
                'id'             => $id,
                'product'        => $a['product'],
                'stage'          => $a['stage'],
                'unit'           => $a['unit'],
                'retailPrice'    => $a['price'],
                'wholesalePrice' => $a['wholesale'],
                'floorPrice'     => $a['floor'],
                'ceilingPrice'   => $a['ceiling'],
                'piecesPerKg'    => $a['piecesPerKg'],
                'note'           => $a['priceNote'],
                'updatedOn'      => $a['priceUpdatedOn'] ?? '',
                'estimated'      => $a['priceEstimated'],
                'own'            => !empty($a['priceOwnSource']),
                'seed'           => !empty($a['priceSeed']),
                'kind'           => $a['kind'],
                'saved'          => false,
            ];
        }

        uasort($rows, fn ($a, $b) => strcmp($a['product'] . $a['unit'] . ($a['stage'] ?? ''), $b['product'] . $b['unit'] . ($b['stage'] ?? '')));

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
            // Fish are stocked and priced by growth stage (fry, juvenile, adult),
            // so each stage is its own sellable row. Plants have no stage.
            $isFish = ($h['kind'] ?? 'plant') === 'fish';
            $stage  = $isFish ? trim((string) ($h['growthStage'] ?? '')) : '';
            $key    = $h['product'] . '|' . $h['unit'] . '|' . $stage;

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'key'       => $key,
                    'product'   => $h['product'],
                    'unit'      => $h['unit'],
                    'kind'      => $h['kind'] ?? 'plant',
                    'stage'     => $stage,
                    'remaining' => 0.0,
                    'remainingFish' => 0,
                    'deadFish'  => 0,
                    'stockedFish' => 0,
                ];
            }
            $groups[$key]['remaining'] = round($groups[$key]['remaining'] + $h['remaining'], 4);
            $groups[$key]['remainingFish'] += (int) ($h['remainingFish'] ?? 0);
            $groups[$key]['deadFish'] += (int) ($h['deadCount'] ?? 0);
            $groups[$key]['stockedFish'] += (int) ($h['stockedCount'] ?? 0);
        }

        foreach ($groups as $key => $g) {
            $ref = $this->referenceFor($g['product'], $g['unit'], $prices, $g['stage']);

            // Nursery fish (fry, fingerlings, juveniles) are sold by the piece at
            // the published hatchery rate, so the sale form can offer both units.
            $piecePrice = null;
            $pieceNote  = '';
            if (($g['kind'] ?? '') === 'fish' && $g['stage'] !== '') {
                $piece = $this->referenceFor($g['product'], 'pcs', $prices, $g['stage']);
                if ($piece['price'] !== null && $piece['priceSeed']) {
                    $piecePrice = $piece['price'];
                    $pieceNote  = $piece['priceNote'];
                } elseif ($ref['price'] !== null && !empty($g['remainingFish']) && $g['remaining'] > 0) {
                    // Market-size fish has no hatchery rate, but a piece price still
                    // follows from its kilo price and the batch's own average weight.
                    $avgKg = $g['remaining'] / $g['remainingFish'];
                    $piecePrice = round($ref['price'] * $avgKg, 2);
                    $pieceNote  = '₱' . number_format($ref['price'], 2) . '/kg × ' . number_format($avgKg * 1000, 0)
                        . ' g average = ₱' . number_format($piecePrice, 2) . ' per piece.';
                }
            }

            $groups[$key] = $ref + $g + [
                'piecePrice' => $piecePrice,
                'pieceNote'  => $pieceNote,
            ];
        }
        ksort($groups);

        return array_values($groups);
    }

    /**
     * Whether a product is fish or plant stock, so the price list can be split
     * the same way the Harvest page is. Harvest records answer first; a product
     * with no stock left is placed by the class the price book files it under.
     */
    public function kindOf(string $product, ?array $harvests = null): string
    {
        foreach ($harvests ?? $this->activeHarvests() as $h) {
            if (strcasecmp((string) $h['product'], $product) === 0 && ($h['kind'] ?? '') !== '') {
                return $h['kind'];
            }
        }

        return in_array($this->book->classOf($product), ['fish_fresh', 'fish_marine'], true) ? 'fish' : 'plant';
    }

    /**
     * "PRODUCT|unit" => the price a sale would start from, for products that
     * have not been harvested yet. Used by the Add Harvest form to show the
     * price while the harvest is still being entered.
     */
    public function priceHints(array $products, ?array $prices = null, array $units = ['kilos', 'pcs']): array
    {
        $prices ??= $this->priceReferences();

        $out = [];
        foreach ($products as $product) {
            $product = strtoupper(trim((string) $product));
            if ($product === '') {
                continue;
            }
            foreach ($units as $unit) {
                $ref = $this->referenceFor($product, $unit, $prices);
                if ($ref['price'] === null) {
                    continue;
                }
                $out[$product . '|' . $unit] = [
                    'price'     => $ref['price'],
                    'on'        => $ref['priceUpdatedOn'] ?? '',
                    'estimated' => (bool) $ref['priceEstimated'],
                ];
            }
        }

        return $out;
    }

    /**
     * Where the price for this product starts from.
     *
     * A price saved on the Harvest page always wins. Otherwise the price book
     * answers from the DA Region I list, and for a crop nobody publishes it
     * returns a clearly-marked estimate instead of leaving the box empty.
     */
    protected function referenceFor(string $product, string $unit, array $prices, string $stage = ''): array
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
            'priceOwnSource' => false,
            'priceSeed'      => false,
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
                'priceOwnSource' => false,
                'priceSeed'      => false,
            ];
        }

        $ref = $this->book->resolve($product, $unit, $stage);
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
            'priceOwnSource' => !empty($ref['ownSource']),
            'priceSeed'      => !empty($ref['seed']),
        ];
    }
}
