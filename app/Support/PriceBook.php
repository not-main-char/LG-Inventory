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
 * Crops the DA does not list anywhere (ube, for one) are first looked up in
 * OWN_PRICES, which holds their own published figure and its source. Only when
 * a crop has neither a DA row nor an own price does the class average step in,
 * and that one is marked as an estimate.
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

    /**
     * Crops the Region I list does not carry, with their own published price.
     * These beat the class estimate and lose to a price saved on the Harvest
     * page. Keep the source and the period, because the note shows both.
     */
    public const OWN_PRICES = [
        'UBE' => [
            'perKg'  => 80.11,
            'low'    => 80.00,
            'high'   => 100.00,
            'source' => 'PSA national retail average for ube (2025)',
            'asOf'   => '2025-12-31',
        ],
    ];

    /**
     * Hatchery seedstock rates for fish, published per piece (BFAR / SEAFDEC).
     *
     * The DA Region I list only carries market-size fish by the kilo, so fry,
     * fingerlings and juveniles would all inherit the adult price. These are the
     * published per-piece rates for the nursery stages, with the size class they
     * were quoted for (grams per fish) so they can be converted to a per-kilo
     * figure for the sale form.
     */
    public const SEED_PRICES = [
        'TILAPIA' => [
            'Fry'        => ['perPiece' => 0.25, 'low' => 0.20, 'high' => 0.30, 'grams' => 0.5, 'grade' => 'mixed-sex fry (size 24-22, up to 0.6 in)'],
            'Fingerling' => ['perPiece' => 0.45, 'low' => 0.30, 'high' => 0.60, 'grams' => 1.5, 'grade' => 'mixed-sex fingerling (size 20-14, 0.5-5 g)'],
            'Juvenile'   => ['perPiece' => 0.65, 'low' => 0.60, 'high' => 0.70, 'grams' => 6.0, 'grade' => 'juvenile / post-fingerling (about 2.4 in)'],
        ],
        'RED TILAPIA' => [
            'Fry'        => ['perPiece' => 1.00, 'low' => 1.00, 'high' => 1.00, 'grams' => 0.5, 'grade' => 'red tilapia fry'],
            'Fingerling' => ['perPiece' => 3.00, 'low' => 2.00, 'high' => 4.00, 'grams' => 1.5, 'grade' => 'red tilapia fingerling (medium to large)'],
            'Juvenile'   => ['perPiece' => 5.00, 'low' => 5.00, 'high' => 5.00, 'grams' => 6.0, 'grade' => 'red tilapia juvenile'],
        ],
        'CARP' => [
            'Fry'        => ['perPiece' => 0.17, 'low' => 0.17, 'high' => 0.17, 'grams' => 0.5, 'grade' => 'common carp fry (size 24)'],
            'Fingerling' => ['perPiece' => 0.30, 'low' => 0.20, 'high' => 0.50, 'grams' => 1.5, 'grade' => 'common carp medium fingerling (size 17)'],
            'Juvenile'   => ['perPiece' => 4.00, 'low' => 3.00, 'high' => 5.00, 'grams' => 6.0, 'grade' => 'carp juvenile (2-3 in, private hatchery)'],
        ],
        'HITO' => [
            'Fry'        => ['perPiece' => 2.50, 'low' => 1.00, 'high' => 4.00, 'grams' => 0.5, 'grade' => 'hito fingerling'],
            'Fingerling' => ['perPiece' => 2.50, 'low' => 1.00, 'high' => 4.00, 'grams' => 1.5, 'grade' => 'hito fingerling'],
            'Juvenile'   => ['perPiece' => 2.50, 'low' => 1.00, 'high' => 4.00, 'grams' => 6.0, 'grade' => 'hito juvenile'],
        ],
    ];

    /** Where the per-piece seedstock rates come from. */
    public const SEED_SOURCE = 'BFAR / SEAFDEC hatchery rate';

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

    /** Plain words for a class, used when an estimate has to name it. */
    protected const CLASS_LABELS = [
        'rootcrop'    => 'rootcrops',
        'leafy'       => 'leafy vegetables',
        'fruit_veg'   => 'vegetables',
        'fruit'       => 'fruits',
        'spice'       => 'spices',
        'fish_fresh'  => 'fresh fish',
        'fish_marine' => 'fish',
        'meat'        => 'meat',
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
        $doc  = $this->db->collection(self::COLLECTION)->document(self::DOCUMENT);
        $snap = $doc->snapshot();

        // Capture what the feed said before this update replaced it, so the
        // Price Reference history can show the previous figure.
        if ($snap->exists()) {
            try {
                app(PriceHistory::class)->recordFeedChanges(
                    $snap->data()['items'] ?? [],
                    $book['items'] ?? [],
                    $book['asOf'] ?? null
                );
            } catch (\Throwable $e) {
                \Log::warning('Price history capture failed: ' . $e->getMessage());
            }
        }

        $doc->set($book);
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
    public function resolve(string $product, string $unit, string $stage = ''): ?array
    {
        // Nursery fish are quoted per piece, not per kilo, so their rate comes
        // from the seedstock table before any market-size fish price applies.
        $seed = $this->seedPrice($product, $stage);
        if ($seed) {
            $fromSeed = $this->fromSeed($unit, $seed);
            if ($fromSeed) {
                return $fromSeed;
            }
        }

        $book = $this->load();
        if (empty($book['items'])) {
            return null;
        }

        $matches = $this->matches($product, $book['items']);
        if ($matches) {
            return $this->fromListed($product, $unit, $matches, $book);
        }

        // Not on the DA list: use the crop's own published figure when we have
        // one, because a rootcrop average is not a ube price.
        $own = $this->ownPrice($product);
        if ($own) {
            return $this->fromOwn($product, $unit, $own);
        }

        return $this->estimate($product, $unit, $book);
    }

    /** The seedstock rate for one fish stage, if we have one. */
    public function seedPrice(string $product, string $stage): ?array
    {
        $stageKey = $this->stageKey($stage);
        $name     = $this->normalise($product);
        if ($stageKey === null || $name === '') {
            return null;
        }

        $loose = null;
        foreach (self::SEED_PRICES as $fish => $stages) {
            if (!isset($stages[$stageKey])) {
                continue;
            }
            $row = $stages[$stageKey] + ['stage' => $stageKey, 'fish' => $fish];
            $key = $this->normalise($fish);

            if ($key === $name) {
                return $row;
            }
            if ($loose === null && (str_contains($name, $key) || str_contains($key, $name))) {
                $loose = $row;
            }
        }

        return $loose;
    }

    /** Which nursery stage a growth-stage label names. Adult fish return null. */
    protected function stageKey(string $stage): ?string
    {
        $s = $this->normalise($stage);
        if ($s === '') {
            return null;
        }
        if (str_contains($s, 'FRY')) {
            return 'Fry';
        }
        if (str_contains($s, 'FINGERLING')) {
            return 'Fingerling';
        }
        if (str_contains($s, 'JUVENILE')) {
            return 'Juvenile';
        }

        return null;
    }

    /** A per-piece seedstock rate turned into the unit the farm sells. */
    protected function fromSeed(string $unit, array $seed): ?array
    {
        $perPiece = (float) ($seed['perPiece'] ?? 0);
        if ($perPiece <= 0) {
            return null;
        }

        $pieces = null;
        if ($this->isPieceUnit($unit)) {
            $price = $perPiece;
            $low   = $seed['low'] ?? null;
            $high  = $seed['high'] ?? null;
        } else {
            // The rate is quoted for a size class, so that class's weight is what
            // converts it: counting a 6 g juvenile as 91 g would underprice it.
            $g = (float) ($seed['grams'] ?? 0);
            if ($g <= 0) {
                return null;
            }
            $pieces = 1000 / $g;
            $price  = $perPiece * $pieces;
            $low    = isset($seed['low']) ? (float) $seed['low'] * $pieces : null;
            $high   = isset($seed['high']) ? (float) $seed['high'] * $pieces : null;
        }

        $price = round($price, 2);
        if ($price <= 0) {
            return null;
        }

        $note = self::SEED_SOURCE . ' of ₱' . number_format($perPiece, 2) . ' per piece for '
            . $seed['grade'] . '.';
        if ($pieces) {
            $note .= ' At ' . rtrim(rtrim(number_format((float) $seed['grams'], 2, '.', ''), '0'), '.')
                . ' g per fish that is ' . number_format($pieces, 0) . ' pcs/kg, so the same price works out to ₱'
                . number_format($price, 2) . ' per kilo.';
        }

        return [
            'price'          => $price,
            'wholesale'      => null,
            'floor'          => $low !== null ? round($low, 2) : round($price * 0.5, 2),
            'ceiling'        => $high !== null ? round($high, 2) : round($price * 2, 2),
            'piecesPerKg'    => $pieces !== null ? round($pieces, 2) : null,
            'note'           => $note,
            'updatedOn'      => null,
            'estimated'      => false,
            'ownSource'      => false,
            'seed'           => true,
        ];
    }

    /** A crop's own published price, matched by name. */
    protected function ownPrice(string $product): ?array
    {
        $needle = $this->normalise($product);
        if ($needle === '') {
            return null;
        }

        foreach (self::OWN_PRICES as $name => $row) {
            if ($this->normalise($name) === $needle) {
                return $row;
            }
        }

        return null;
    }

    /** Price built from a crop's own figure instead of a class average. */
    protected function fromOwn(string $product, string $unit, array $own): ?array
    {
        return $this->build($product, $unit, (float) $own['perKg'], [
            'low'    => $own['low'] ?? null,
            'high'   => $own['high'] ?? null,
            'pieces' => null,
            'asOf'   => $own['asOf'] ?? null,
            'own'    => true,
            'basis'  => 'Price from the ' . $own['source'] . '.',
        ]);
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

        return $this->build($product, $unit, $perKg, [
            'low'    => $this->min(array_column($matches, 'low')),
            'high'   => $this->max(array_column($matches, 'high')),
            'pieces' => $specs,
            'asOf'   => $book['asOf'] ?? null,
            'basis'  => 'This price is from the ' . self::SOURCE . ' ' . self::REGION_NAME . ' list.',
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

        // Name the crops it leaned on, so the note answers "where is this from".
        $anchored = implode(', ', array_map(
            fn ($row) => $row['name'],
            array_slice($rows, 0, 2)
        ));
        $label = self::CLASS_LABELS[$class] ?? 'similar crops';

        return $this->build($product, $unit, $perKg, [
            'low'       => null,
            'high'      => null,
            'pieces'    => null,
            'asOf'      => $book['asOf'] ?? null,
            'estimated' => true,
            'basis'     => 'Price reference is based on the ' . self::SOURCE . ' ' . self::REGION_NAME
                . ' list for similar ' . $label . ($anchored !== '' ? ' (' . $anchored . ')' : '')
                . '. ' . strtoupper($product) . ' is not on the list.',
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

        $note = rtrim(rtrim($opt['basis'], " ."), " .");
        // A one-to-one unit needs no explanation; only real conversions do.
        if ($pieceUnit && round((float) $pieces, 4) != 1.0) {
            $note .= ', converted to per ' . $this->pieceWord($unit);
            if ($assumed) {
                $note .= ' — pieces per kilo assumed, check this one';
            }
        }
        $note .= '.';

        return [
            'price'          => $price,
            'wholesale'      => null,
            'floor'          => $low && $low > 0 ? round($low, 2) : round($price * 0.5, 2),
            'ceiling'        => $high && $high > 0 ? round($high, 2) : round($price * 2, 2),
            'piecesPerKg'    => $pieceUnit ? round((float) $pieces, 2) : null,
            'note'           => trim($note),
            'updatedOn'      => $opt['asOf'] ?? null,
            'estimated'      => !empty($opt['estimated']),
            'ownSource'      => !empty($opt['own']),
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
