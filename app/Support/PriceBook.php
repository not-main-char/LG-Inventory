<?php

namespace App\Support;

use Carbon\Carbon;
use Kreait\Firebase\Contract\Firestore;

/**
 * The price book: what a kilo or a piece of something should sell for, and
 * where that number came from.
 *
 * The Department of Agriculture publishes Region I (Ilocos) prevailing retail
 * prices every day through the Bantay Presyo tables. Those are the safe
 * default for this farm, so a sale never starts from an empty price box. A
 * price the team saved on the Harvest page always wins over the feed.
 *
 * Crops the DA does not list anywhere (ube, for one) still get a price: the
 * average of the closest class of crops in the same Region I list. Those are
 * marked as estimates so nobody mistakes them for an official figure.
 */
class PriceBook
{
    /** Bantay Presyo region code for Region I (Ilocos). */
    public const REGION_I  = '010000000';
    public const REGION_NAME = 'Region I (Ilocos)';
    public const SOURCE    = 'DA Bantay Presyo';

    /** Where the last imported price list is kept. */
    public const COLLECTION = 'price_sources';
    public const DOCUMENT   = 'region1';

    /** The commodity select values used by bantaypresyo.da.gov.ph. */
    public const CATEGORIES = [
        'lowland'  => '7',
        'highland' => '6',
        'fish'     => '4',
        'fruits'   => '5',
        'legumes'  => '3',
        'meat'     => '8',
        'spices'   => '9,10',
    ];

    protected const BASE = 'http://www.bantaypresyo.da.gov.ph';

    /** How long an imported list is trusted before we refetch it. */
    protected const FRESH_FOR_MINUTES = 720;

    /**
     * Words that put a crop the DA does not list into a class of crops that it
     * does list, so it can still be given a starting price.
     */
    protected const CLASS_WORDS = [
        'rootcrop'    => ['UBE', 'CAMOTE', 'KAMOTE', 'CASSAVA', 'KAMOTENG', 'TARO', 'GABI', 'POTATO', 'CARROT', 'SINGKAMAS', 'RADISH'],
        'leafy'       => ['KANGKONG', 'MALUNGGAY', 'MUSTARD', 'PECHAY', 'LETTUCE', 'SPINACH', 'BOKCHOY', 'BOK CHOY', 'CHINESE CABBAGE', 'CELERY', 'PARSLEY'],
        'fruit_veg'   => ['TOMATO', 'KAMATIS', 'EGGPLANT', 'TALONG', 'OKRA', 'AMPALAYA', 'SQUASH', 'PUMPKIN', 'CUCUMBER', 'PEPPER', 'CHILI', 'CHILLI', 'SITAO', 'BEANS', 'UPO', 'PATOLA', 'SALUYOT'],
        'fruit'       => ['BANANA', 'SAGING', 'MANGO', 'PAPAYA', 'WATERMELON', 'MELON', 'CALAMANSI', 'POMELO', 'AVOCADO', 'GUAVA', 'PINEAPPLE'],
        'spice'       => ['GARLIC', 'BAWANG', 'ONION', 'SIBUYAS', 'GINGER', 'LUYA', 'BASIL', 'MINT', 'LEMONGRASS'],
        'fish_fresh'  => ['TILAPIA', 'BANGUS', 'MILKFISH', 'HITO', 'CATFISH', 'CARP', 'DALAGANG BUKID'],
        'fish_marine' => ['GALUNGGONG', 'TAMBAKOL', 'TUNA', 'SARDINES', 'TAMBAN', 'ALUMAHAN', 'MACKEREL', 'SQUID', 'PUSIT', 'BONITO', 'TILAPIA'],
        'meat'        => ['CHICKEN', 'MANOK', 'PORK', 'BABOY', 'BEEF', 'BAKA', 'EGG', 'ITLOG'],
    ];

    /**
     * The Region I commodities whose price stands in for a whole class when a
     * crop is missing from the DA list entirely. Names must match the feed.
     */
    protected const CLASS_ANCHORS = [
        'rootcrop'    => ['White Potato', 'Carrots'],
        'leafy'       => ['Pechay (Native)', 'Pechay (Baguio)', 'Celery'],
        'fruit_veg'   => ['Ampalaya', 'Squash', 'Eggplant', 'Tomato'],
        'fruit'       => ['Banana (Lakatan)', 'Papaya', 'Watermelon', 'Calamansi'],
        'spice'       => ['Ginger', 'Garlic(Native)'],
        'fish_fresh'  => ['Tilapia', 'Bangus (Medium)'],
        'fish_marine' => ['Galunggong(Local)', 'Sardines (Tamban)', 'Yellow-Fin Tuna (Tambakol)'],
        'meat'        => ['Whole Chicken'],
    ];

    /** Pieces per kilo assumed when a crop is sold by piece and the DA spec does not say. */
    protected const CLASS_PIECES_PER_KG = [
        'rootcrop'    => 1.0,
        'leafy'       => 3.5,
        'fruit_veg'   => 4.5,
        'fruit'       => 8.0,
        'spice'       => 14.0,
        'fish_fresh'  => 5.0,
        'fish_marine' => 13.0,
        'meat'        => 1.0,
    ];

    protected $db;

    /** Cached copy of the stored price list for this request. */
    protected static ?array $memo = null;

    public function __construct(Firestore $firestore)
    {
        $this->db = $firestore->database();
    }

    // ---------------------------------------------------------------------
    // Importing the Region I feed
    // ---------------------------------------------------------------------

    /** The date Bantay Presyo currently reports for Region I. */
    public function latestDate(): ?string
    {
        $body = $this->post(self::BASE . '/tbl_others.php', [
            'action'    => 'get_latest_date',
            'region'    => self::REGION_I,
            'commodity' => self::CATEGORIES['lowland'],
        ]);

        $date = $body !== null ? trim($body) : '';

        return $date !== '' ? $date : null;
    }

    /**
     * Read every Region I commodity group and turn the per-market columns into
     * one average, with the lowest and highest market price kept as the
     * usual range.
     *
     * @return array{asOf: ?string, source: string, region: string, fetchedAt: string, items: array}
     */
    public function fetchRegionI(): array
    {
        $items = [];

        foreach (self::CATEGORIES as $group => $id) {
            $html = $this->post(self::BASE . '/tbl_price_get_comm_others.php', [
                'commodity' => $id,
                'region'    => self::REGION_I,
            ]);

            if ($html === null || $html === '') {
                continue;
            }

            foreach ($this->parseRows($html, $group) as $item) {
                $items[] = $item;
            }
        }

        return [
            'asOf'      => $this->latestDate() ?? Carbon::now('Asia/Manila')->format('F j, Y'),
            'source'    => self::SOURCE,
            'region'    => self::REGION_NAME,
            'fetchedAt' => Carbon::now('Asia/Manila')->toIso8601String(),
            'items'     => $items,
        ];
    }

    /** Save an imported list so the app does not call the DA on every page load. */
    public function store(array $book): void
    {
        $this->db->collection(self::COLLECTION)->document(self::DOCUMENT)->set($book);
        static::$memo = $book;
    }

    // ---------------------------------------------------------------------
    // Reading the price book
    // ---------------------------------------------------------------------

    /**
     * The stored Region I list, refetched when it is missing or stale.
     *
     * A fetch failure is never fatal: the last stored list is used instead, so
     * a sale still gets a price when the DA site is down.
     */
    public function load(bool $refreshIfStale = false): ?array
    {
        if (static::$memo !== null && !$refreshIfStale) {
            return static::$memo;
        }

        $book = null;
        $doc  = $this->db->collection(self::COLLECTION)->document(self::DOCUMENT);
        if ($doc->snapshot()->exists()) {
            $book = $doc->snapshot()->data();
        }

        $stale = $book === null || $this->isStale($book);

        if ($book === null || ($refreshIfStale && $stale)) {
            $fresh = $this->fetchRegionI();
            if (!empty($fresh['items'])) {
                $this->store($fresh);
                $book = $fresh;
            }
        }

        static::$memo = $book;

        return $book;
    }

    protected function isStale(array $book): bool
    {
        $fetchedAt = $book['fetchedAt'] ?? null;
        if (!$fetchedAt) {
            return true;
        }

        try {
            return Carbon::parse($fetchedAt)->diffInMinutes(Carbon::now('Asia/Manila')) > self::FRESH_FOR_MINUTES;
        } catch (\Throwable $e) {
            return true;
        }
    }

    // ---------------------------------------------------------------------
    // Answering "what should this sell for?"
    // ---------------------------------------------------------------------

    /**
     * A price reference for one product and unit, or null when nothing can be
     * said about it.
     *
     * Shape matches a saved price_reference row, plus "estimated" and the
     * note that explains where the number came from.
     */
    public function resolve(string $product, string $unit): ?array
    {
        $book = $this->load();
        if (empty($book['items'])) {
            return null;
        }

        $matches = $this->matches($product, $book['items']);

        return $matches
            ? $this->fromListed($product, $unit, $matches, $book)
            : $this->estimate($product, $unit, $book);
    }

    /** Region I rows whose commodity name lines up with this product. */
    protected function matches(string $product, array $items): array
    {
        $needle = $this->normalise($product);
        if ($needle === '') {
            return [];
        }

        $exact = [];
        $loose = [];
        foreach ($items as $item) {
            $name = $this->normalise($item['name'] ?? '');
            if ($name === '') {
                continue;
            }
            if ($name === $needle) {
                $exact[] = $item;
            } elseif (str_contains($name, $needle) || str_contains($needle, $name)) {
                $loose[] = $item;
            }
        }

        return $exact ?: $loose;
    }

    /** Build the reference from official Region I rows. */
    protected function fromListed(string $product, string $unit, array $matches, array $book): ?array
    {
        $perKg = $this->average(array_column($matches, 'perKg'));
        if ($perKg === null || $perKg <= 0) {
            return null;
        }

        $specs = $this->average(array_column($matches, 'piecesPerKg'));
        $names = implode(' / ', array_slice(array_unique(array_column($matches, 'name')), 0, 3));
        $spec  = trim(implode(' / ', array_slice(array_unique(array_filter(array_column($matches, 'spec'))), 0, 2)));

        return $this->build($product, $unit, $perKg, [
            'low'    => $this->min(array_column($matches, 'low')),
            'high'   => $this->max(array_column($matches, 'high')),
            'pieces' => $specs,
            'asOf'   => $book['asOf'] ?? null,
            'basis'  => self::SOURCE . ' ' . self::REGION_NAME . ' — ' . $names . ($spec !== '' ? ' (' . $spec . ')' : '') . ' ₱' . number_format($perKg, 2) . '/kg',
        ]);
    }

    /**
     * A starting price for a crop the DA does not list, taken from the average
     * of the closest class of crops it does list.
     */
    protected function estimate(string $product, string $unit, array $book): ?array
    {
        $class   = $this->classOf($product);
        $anchors = array_map([$this, 'normalise'], self::CLASS_ANCHORS[$class] ?? []);

        $rows = array_values(array_filter($book['items'], function ($item) use ($anchors, $class) {
            $name = $this->normalise($item['name'] ?? '');

            return $name !== '' && ($anchors === [] || in_array($name, $anchors, true)) && ($item['perKg'] ?? 0) > 0;
        }));

        // No anchor matched the feed: fall back to every vegetable row we have.
        if (!$rows) {
            $rows = array_values(array_filter($book['items'], fn ($item) => ($item['perKg'] ?? 0) > 0 && in_array($item['group'] ?? '', ['lowland', 'highland'], true)));
        }

        $perKg = $this->average(array_column($rows, 'perKg'));
        if ($perKg === null || $perKg <= 0) {
            return null;
        }

        $anchored = implode(', ', array_map(
            fn ($row) => $row['name'] . ' ₱' . number_format($row['perKg'], 2) . '/kg',
            array_slice($rows, 0, 3)
        ));
        $detail = $anchored !== '' ? ' (based on ' . $anchored . ')' : '';

        return $this->build($product, $unit, $perKg, [
            'low'       => null,
            'high'      => null,
            'pieces'    => null,
            'asOf'      => $book['asOf'] ?? null,
            'estimated' => true,
            'basis'     => 'Estimate — ' . strtoupper($product) . ' is not in the ' . self::SOURCE . ' ' . self::REGION_NAME
                . ' list. Based on the ' . $class . ' average ₱' . number_format($perKg, 2) . '/kg' . $detail . '.',
        ]);
    }

    /** Convert a per-kilo price into the unit the farm actually sells. */
    protected function build(string $product, string $unit, float $perKg, array $opt): ?array
    {
        $pieceUnit = $this->isPieceUnit($unit);
        $pieces    = $opt['pieces'] ?? null;
        $assumed   = false;

        if ($pieceUnit) {
            if ($pieces === null || $pieces <= 0) {
                $pieces  = self::CLASS_PIECES_PER_KG[$this->classOf($product)] ?? 1.0;
                $assumed = true;
            }
            $price = $perKg / $pieces;
            $low   = isset($opt['low']) && $opt['low'] ? $opt['low'] / $pieces : null;
            $high  = isset($opt['high']) && $opt['high'] ? $opt['high'] / $pieces : null;
        } else {
            $price = $perKg;
            $low   = $opt['low'] ?? null;
            $high  = $opt['high'] ?? null;
        }

        $price = round($price, 2);
        if ($price <= 0) {
            return null;
        }

        $note = $opt['basis'];
        if ($pieceUnit) {
            $word  = $this->pieceWord($unit);
            $count = rtrim(rtrim(number_format($pieces, 2), '0'), '.');
            $note .= ' ÷ ' . $count . ' ' . $word . '/kg = ₱' . number_format($price, 2) . ' per ' . $word
                . ($assumed ? ' (pieces per kilo assumed — check this one).' : '.');
        }
        if (!empty($opt['estimated'])) {
            $note .= ' Save a price here to replace the estimate.';
        }

        return [
            'price'          => $price,
            'wholesale'      => null,
            'floor'          => $low && $low > 0 ? round($low, 2) : round($price * 0.5, 2),
            'ceiling'        => $high && $high > 0 ? round($high, 2) : round($price * 2, 2),
            'piecesPerKg'    => $pieceUnit ? round((float) $pieces, 2) : null,
            'note'           => trim($note),
            'updatedOn'      => $opt['asOf'] ?? null,
            'estimated'      => !empty($opt['estimated']),
        ];
    }

    // ---------------------------------------------------------------------
    // Small helpers
    // ---------------------------------------------------------------------

    /** Which priced class of crops this product belongs to. */
    public function classOf(string $product): string
    {
        $name = $this->normalise($product);

        foreach (self::CLASS_WORDS as $class => $words) {
            foreach ($words as $word) {
                if ($name !== '' && str_contains($name, $word)) {
                    return $class;
                }
            }
        }

        return 'fruit_veg';
    }

    /** True when the farm sells this product by piece rather than by weight. */
    protected function isPieceUnit(string $unit): bool
    {
        return (bool) preg_match('/pc|piece|bundle|bunch|head|stick|pack/i', $unit);
    }

    protected function pieceWord(string $unit): string
    {
        return preg_match('/bundle|bunch/i', $unit) ? 'bundle' : 'pc';
    }

    protected function normalise(string $value): string
    {
        $value = strtoupper($value);
        $value = preg_replace('/\([^)]*\)/', ' ', $value);
        $value = preg_replace('/[^A-Z0-9]+/', ' ', $value);

        return trim(preg_replace('/\s+/', ' ', $value));
    }

    protected function average(array $values): ?float
    {
        $values = array_values(array_filter($values, fn ($v) => $v !== null && $v !== '' && (float) $v > 0));

        return $values ? array_sum($values) / count($values) : null;
    }

    protected function min(array $values): ?float
    {
        $values = array_values(array_filter($values, fn ($v) => $v !== null && $v !== '' && (float) $v > 0));

        return $values ? (float) min($values) : null;
    }

    protected function max(array $values): ?float
    {
        $values = array_values(array_filter($values, fn ($v) => $v !== null && $v !== '' && (float) $v > 0));

        return $values ? (float) max($values) : null;
    }

    /** Turn the Bantay Presyo rows of one commodity group into price items. */
    protected function parseRows(string $html, string $group): array
    {
        $items = [];
        $dom   = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);

        foreach ($dom->getElementsByTagName('tr') as $tr) {
            $cells = [];
            foreach ($tr->getElementsByTagName('td') as $td) {
                $cells[] = trim(preg_replace('/\s+/', ' ', $td->textContent));
            }
            if (count($cells) < 3) {
                continue;
            }

            $name = array_shift($cells);
            $spec = array_shift($cells);
            if ($name === '') {
                continue;
            }

            $prices = [];
            foreach ($cells as $cell) {
                $clean = str_replace([',', ' '], '', $cell);
                // "N/A" and blanks mean the commodity was not sold in that market.
                if ($clean !== '' && is_numeric($clean) && (float) $clean > 0) {
                    $prices[] = (float) $clean;
                }
            }
            if (!$prices) {
                continue;
            }

            // Cooking oil and rice come in bottles and bags, not kilos.
            if (preg_match('/bottle|pack|sack|bag|litter|liter|ml\b/i', $spec)) {
                continue;
            }

            // Eggs are priced per piece, which would read as a per-kilo price here.
            if (stripos($name, 'egg') !== false) {
                continue;
            }

            $items[] = [
                'name'       => $name,
                'spec'       => $spec,
                'group'      => $group,
                'perKg'      => round(array_sum($prices) / count($prices), 2),
                'low'        => min($prices),
                'high'       => max($prices),
                'markets'    => count($prices),
                'piecesPerKg' => $this->specPiecesPerKg($spec),
            ];
        }

        return $items;
    }

    /**
     * Read "pieces per kilo" from a DA specification.
     *
     * "3-4 SMALL BUNDLES/KG" -> 3.5, "8-10PCS/KG" -> 9, "151-250GM/PC" -> 5
     * (from the weight of one piece). Returns null when the spec says nothing
     * usable, and the caller then assumes a class default.
     */
    protected function specPiecesPerKg(string $spec): ?float
    {
        $spec = strtoupper($spec);

        if (preg_match('/(\d+)\s*-\s*(\d+)\s*(?:PCS|PIECES|BUNDLES|SMALL BUNDLES|HEADS?|STICKS?)/', $spec, $m)) {
            return round(((float) $m[1] + (float) $m[2]) / 2, 2);
        }

        // A weight per piece tells us how many pieces make a kilo.
        if (preg_match('/(\d+)\s*-\s*(\d+)\s*GM?\s*\/?\s*(?:PC|PIECE)/', $spec, $m)) {
            $grams = ((float) $m[1] + (float) $m[2]) / 2;

            return $grams > 0 ? round(1000 / $grams, 2) : null;
        }

        return null;
    }

    /** POST to the DA price tables, returning the body or null on failure. */
    protected function post(string $url, array $data): ?string
    {
        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query($data),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_USERAGENT      => 'LG-Inventory price book',
            ]);
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return ($body !== false && $code < 400) ? (string) $body : null;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }
}
