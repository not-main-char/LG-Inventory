<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Kreait\Firebase\Contract\Firestore;
use Illuminate\Support\Facades\Cache; 
use Carbon\Carbon;
use App\Support\HarvestStock;
use Google\Cloud\Firestore\Transaction;
use Illuminate\Support\Facades\Log;


class CachedIncomeDocument
{
    protected $id;
    protected $data;        

    public function __construct($id, $data)
    {
        $this->id = $id;
        $this->data = $this->sanitizeData($data);
    }

    public function id() { return $this->id; }
    public function data() { return $this->data; }

    protected function sanitizeData($data)
    {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->sanitizeData($value);
            }
        } elseif (is_object($data) && method_exists($data, 'toDateTime')) {
            return $data->toDateTime();
        }
        return $data;
    }
}

class IncomeController extends Controller
{
    protected $firestore;
    
    public function __construct(Firestore $firestore)
    {
        $this->firestore = $firestore->database();
    }
    
    public function index(Request $request, HarvestStock $harvestStock)
    {
        $role = $request->attributes->get('firebase_role');

        $availableStock = $harvestStock->availableProducts();

        //Cache transaction data locally
        $sales = Cache::remember('income_list', 300, function () {
            // Simplified query to avoid composite index issues
            $documents = $this->firestore->collection('sales')->orderBy('date', 'desc')->documents();
            $cachedList = [];
            foreach ($documents as $doc) {
                $data = $doc->data();
                if (isset($data['status']) && $data['status'] === 'archived') continue;
                $cachedList[] = new CachedIncomeDocument($doc->id(), $data);
            }
            return $cachedList;
        });

        $totalIncome = 0;
        $monthly = [];

        foreach ($sales as $sale) {
            $data = $sale->data();
            
            $amount = (float)($data['saleAmount'] ?? 0);
            $totalIncome += $amount;

            $rawDate = $data['date'] ?? null;
            $dateTime = null;
            
            //Firestore Timestamp
            if ($rawDate instanceof \Google\Cloud\Core\Timestamp) {
                try {
                    $dateTime = $rawDate->get();
                } catch (\Throwable $e) {
                    continue;
                }
            } elseif ($rawDate instanceof \DateTimeInterface) {
                $dateTime = $rawDate;
            } elseif (is_object($rawDate) && method_exists($rawDate, 'get')) {
                try {
                    $dateTime = $rawDate->get();
                } catch (\Throwable $e) {
                    continue;
                }
            }
            
            if ($dateTime instanceof \DateTimeInterface) {
                $key = $dateTime->format('Y-m');
                $label = $dateTime->format('M Y');
                if (!isset($monthly[$key])) {
                    $monthly[$key] = ['label' => $label, 'fish' => 0, 'plant' => 0];
                }
                
                if (($data['type'] ?? '') === 'fish') {
                    $monthly[$key]['fish'] += $amount;
                } else {
                    $monthly[$key]['plant'] += $amount;
                }
            }
        }

        //Get current month's data
        $currentMonthKey = Carbon::now('Asia/Manila')->format('Y-m');
        $currentMonthFish = $monthly[$currentMonthKey]['fish'] ?? 0;
        $currentMonthPlant = $monthly[$currentMonthKey]['plant'] ?? 0;

        //Get last month's data
        $lastMonthKey = Carbon::now('Asia/Manila')->subMonth()->format('Y-m');
        $lastMonthFish = $monthly[$lastMonthKey]['fish'] ?? 0;
        $lastMonthPlant = $monthly[$lastMonthKey]['plant'] ?? 0;

        //  Find highest month for fish and plant
        $highestFishMonth = ['label' => '—', 'amount' => 0];
        $highestPlantMonth = ['label' => '—', 'amount' => 0];
        
        foreach ($monthly as $monthKey => $monthData) {
            // Check fish
            if ($monthData['fish'] > $highestFishMonth['amount']) {
                $highestFishMonth = [
                    'label' => $monthData['label'],
                    'amount' => $monthData['fish']
                ];
            }
            // Check plant
            if ($monthData['plant'] > $highestPlantMonth['amount']) {
                $highestPlantMonth = [
                    'label' => $monthData['label'],
                    'amount' => $monthData['plant']
                ];
            }
        }

        return view('income.index', compact(
            'sales', 'role', 'totalIncome', 'currentMonthFish', 'currentMonthPlant', 'lastMonthFish', 'lastMonthPlant', 'highestFishMonth', 'highestPlantMonth', 'availableStock'
        ));
    }
    
    /**
     * Record a sale from harvest stock.
     *
     * The page sends the product (from the available harvest stock), the
     * quantity, wholesale/retail, the base price per unit and a discount %.
     * The final price and total are computed here, never trusted from the
     * browser. Stock is taken from the oldest harvest first, inside a
     * Firestore transaction so two sales can't take the same kilos.
     */
    public function store(Request $request, HarvestStock $harvestStock)
    {
        $v = $request->validate([
            'stockKey'        => 'required|string',
            'quantitySold'    => 'required|numeric|min:0.01',
            'saleType'        => 'required|in:wholesale,retail',
            'unitPrice'       => 'required|numeric|min:0',
            'suggestedPrice'  => 'nullable|numeric|min:0',
            'discountPercent' => 'required|numeric|min:0|max:100',
            'date'            => 'required|date',
            'notes'           => 'nullable|string|max:500',
        ]);

        [$product, $unit] = array_pad(explode('|', $v['stockKey'], 2), 2, '');
        if ($product === '' || !in_array($unit, ['kilos', 'pcs'], true)) {
            return back()->withErrors(['stockKey' => 'Choose a product from the harvest stock.'])->withInput();
        }

        $qty            = round((float) $v['quantitySold'], 4);
        $unitPrice      = round((float) $v['unitPrice'], 2);
        $discount       = round((float) $v['discountPercent'], 2);
        $finalUnitPrice = round($unitPrice * (1 - $discount / 100), 2);
        $total          = round($finalUnitPrice * $qty, 2);

        if ($total <= 0) {
            return back()->withErrors(['unitPrice' => 'The total must be more than ₱0. Check the price and discount.'])->withInput();
        }

        // Oldest harvest first (activeHarvests() is sorted by harvest date).
        $candidateIds = [];
        foreach ($harvestStock->activeHarvests() as $h) {
            if ($h['product'] === $product && $h['unit'] === $unit && $h['remaining'] > 0.00001) {
                $candidateIds[] = $h['id'];
            }
        }
        if (!$candidateIds) {
            return back()->withErrors(['stockKey' => 'There is no stock left for that product.'])->withInput();
        }

        $date = Carbon::parse($v['date']);
        $user = $request->attributes->get('firebase_user');

        try {
            $this->firestore->runTransaction(function (Transaction $t) use ($candidateIds, $qty, $product, $unit, $unitPrice, $discount, $finalUnitPrice, $total, $v, $date, $user) {
                // Firestore transactions need every read before any write.
                $batches = [];
                foreach ($candidateIds as $hid) {
                    $ref = $this->firestore->collection('harvests')->document($hid);
                    $batches[$hid] = [$ref, $t->snapshot($ref)];
                }

                $need = $qty;
                $allocations = [];
                $updates = [];
                $sources = [];
                $kind = null;

                foreach ($batches as $hid => [$ref, $snap]) {
                    if (!$snap->exists()) {
                        continue;
                    }
                    $h = $snap->data();
                    if (($h['status'] ?? 'active') === 'archived') {
                        continue;
                    }
                    $remaining = (float) ($h['remainingQuantity'] ?? 0);
                    if ($remaining <= 0.00001) {
                        continue;
                    }

                    $take = min($remaining, $need);
                    $allocations[] = ['harvestId' => $hid, 'quantity' => round($take, 4)];
                    $updates[] = [$ref, round($remaining - $take, 4)];
                    $kind ??= ($h['kind'] ?? 'plant');
                    if (!empty($h['source'])) {
                        $sources[$h['source']] = true;
                    }

                    $need = round($need - $take, 4);
                    if ($need <= 0.00001) {
                        break;
                    }
                }

                if ($need > 0.00001) {
                    $available = round($qty - $need, 4);
                    throw new \RuntimeException("Not enough stock. Only {$available} {$unit} of {$product} is available.");
                }

                foreach ($updates as [$ref, $newRemaining]) {
                    $t->set($ref, ['remainingQuantity' => $newRemaining], ['merge' => true]);
                }

                $t->create($this->firestore->collection('sales')->newDocument(), [
                    // These first fields are what the dashboard, charts and reports already read.
                    'itemName'        => $product,
                    'type'            => $kind ?? 'plant',
                    'quantitySold'    => $qty,
                    'unit'            => $unit,
                    'saleAmount'      => $total,
                    'date'            => $date,
                    'season'          => $date->format('F Y'),
                    'notes'           => $v['notes'] ?? '',
                    // Harvest-based sale details.
                    'saleType'        => $v['saleType'],
                    'unitPrice'       => $unitPrice,
                    'suggestedPrice'  => isset($v['suggestedPrice']) ? round((float) $v['suggestedPrice'], 2) : null,
                    'discountPercent' => $discount,
                    'finalUnitPrice'  => $finalUnitPrice,
                    'allocations'     => $allocations,
                    'sources'         => array_keys($sources),
                    'createdBy'       => $user,
                    'createdAt'       => Carbon::now('Asia/Manila'),
                ]);
            });
        } catch (\Throwable $e) {
            return $this->fail($e);
        }

        $this->forgetSalesCaches();

        return redirect()->route('income.index')->with('success', 'Sale recorded');
    }

    /** Archiving a sale puts its quantity back into the harvest batches it came from. */
    public function archive(string $id)
    {
        try {
            $this->firestore->runTransaction(function (Transaction $t) use ($id) {
                $saleRef  = $this->firestore->collection('sales')->document($id);
                $saleSnap = $t->snapshot($saleRef);
                if (!$saleSnap->exists()) {
                    throw new \RuntimeException('Sale record not found.');
                }

                $sale = $saleSnap->data();
                if (($sale['status'] ?? 'active') === 'archived') {
                    return;
                }

                $batches = [];
                foreach (($sale['allocations'] ?? []) as $a) {
                    $ref = $this->firestore->collection('harvests')->document($a['harvestId']);
                    $batches[] = [$ref, $t->snapshot($ref), (float) ($a['quantity'] ?? 0)];
                }

                foreach ($batches as [$ref, $snap, $quantity]) {
                    if ($snap->exists()) {
                        $remaining = (float) ($snap->data()['remainingQuantity'] ?? 0);
                        $t->set($ref, ['remainingQuantity' => round($remaining + $quantity, 4)], ['merge' => true]);
                    }
                }

                $t->set($saleRef, ['status' => 'archived', 'archivedAt' => Carbon::now('Asia/Manila')], ['merge' => true]);
            });
        } catch (\Throwable $e) {
            return $this->fail($e);
        }

        $this->forgetSalesCaches();

        return redirect()->route('income.index')->with('success', 'Sale archived');
    }

    /** Restoring a sale takes its quantity out of the harvest again, if there is still enough. */
    public function restore(string $id)
    {
        try {
            $this->firestore->runTransaction(function (Transaction $t) use ($id) {
                $saleRef  = $this->firestore->collection('sales')->document($id);
                $saleSnap = $t->snapshot($saleRef);
                if (!$saleSnap->exists()) {
                    throw new \RuntimeException('Sale record not found.');
                }

                $sale = $saleSnap->data();
                if (($sale['status'] ?? 'active') !== 'archived') {
                    return;
                }

                $batches = [];
                foreach (($sale['allocations'] ?? []) as $a) {
                    $ref = $this->firestore->collection('harvests')->document($a['harvestId']);
                    $batches[] = [$ref, $t->snapshot($ref), (float) ($a['quantity'] ?? 0)];
                }

                $updates = [];
                foreach ($batches as [$ref, $snap, $quantity]) {
                    if (!$snap->exists() || (($snap->data()['status'] ?? 'active') === 'archived')) {
                        throw new \RuntimeException('This sale cannot be restored because its harvest was archived.');
                    }
                    $remaining = (float) ($snap->data()['remainingQuantity'] ?? 0);
                    if ($quantity > $remaining + 0.00001) {
                        throw new \RuntimeException('This sale cannot be restored because that harvest no longer has enough stock.');
                    }
                    $updates[] = [$ref, round($remaining - $quantity, 4)];
                }

                foreach ($updates as [$ref, $newRemaining]) {
                    $t->set($ref, ['remainingQuantity' => $newRemaining], ['merge' => true]);
                }

                $t->set($saleRef, ['status' => 'active', 'restoredAt' => Carbon::now('Asia/Manila')], ['merge' => true]);
            });
        } catch (\Throwable $e) {
            return $this->fail($e);
        }

        $this->forgetSalesCaches();

        return redirect()->route('income.archived')->with('success', 'Sale restored');
    }

    protected function forgetSalesCaches(): void
    {
        Cache::forget('income_list');
        Cache::forget('income_archived_list');
        Cache::forget('income_chart_data_' . date('Y'));
        Cache::forget('income_chart_data');
        Cache::forget('dashboard_stats');
        Cache::forget('sales_chart_data');
    }

    protected function fail(\Throwable $e)
    {
        if ($e instanceof \RuntimeException) {
            return back()->withErrors(['error' => $e->getMessage()])->withInput();
        }

        Log::error('Sale action failed: ' . $e->getMessage());

        return back()->withErrors(['error' => 'Something went wrong. Please try again.']);
    }

    public function archived(Request $request)
    {
        return redirect()->route('archives.index', ['tab' => 'sales']);
    }
    
    // AJAX endpoint for chart data
    public function chartData(Request $request)
    {
        $year = $request->query('year', date('Y'));
        
        $cacheKey = 'income_chart_data_' . $year;
        
        $monthlyData = Cache::remember($cacheKey, 300, function () use ($year) {
            $documents = $this->firestore->collection('sales')->documents();
            $dataRows = [];

            foreach ($documents as $doc) {
                $data = $doc->data();
                
                $rawDate = $data['date'] ?? null;
                $dateTime = null;
                
                // Parse date
                if ($rawDate instanceof \Google\Cloud\Core\Timestamp) {
                    try {
                        $dateTime = $rawDate->get();
                    } catch (\Throwable $e) {
                        continue;
                    }
                } elseif (is_object($rawDate) && method_exists($rawDate, 'get')) {
                    try {
                        $dateTime = $rawDate->get();
                    } catch (\Throwable $e) {
                        continue;
                    }
                } elseif ($rawDate instanceof \DateTimeInterface) {
                    $dateTime = $rawDate;
                } else {
                    try {
                        $dateTime = new \DateTime($rawDate);
                    } catch (\Throwable $e) {
                        continue;
                    }
                }
                
                $dateTime = Carbon::instance($dateTime)->setTimezone('Asia/Manila');
                
                // Only include sales from selected year
                if ($dateTime->year != $year) {
                    continue;
                }

                // Skip archived sales
                if (isset($data['status']) && $data['status'] === 'archived') {
                    continue;
                }
                
                $key = $dateTime->format('Y-m');
                if (!isset($dataRows[$key])) {
                    $dataRows[$key] = ['fish' => 0, 'plant' => 0, 'label' => $dateTime->format('M')];
                }
                
                $amount = (float)($data['saleAmount'] ?? 0);

                if (($data['type'] ?? '') === 'fish') {
                    $dataRows[$key]['fish'] += $amount;
                } else {
                    $dataRows[$key]['plant'] += $amount;
                }
            }
            
            $currentYear = date('Y');
            $currentMonth = date('n');

            // Fill in missing months with 0
            for ($month = 1; $month <= 12; $month++) {
                $monthKey = sprintf('%04d-%02d', $year, $month);
                if (!isset($dataRows[$monthKey])) {
                    $date = Carbon::createFromFormat('Y-m', $monthKey);
                    $isFuture = ($year == $currentYear && $month > $currentMonth);
                    $dataRows[$monthKey] = [
                        'fish' => $isFuture ? null : 0,
                        'plant' => $isFuture ? null : 0,
                        'label' => $date->format('M')
                    ];
                }
            }
            
            ksort($dataRows);
            return $dataRows;
        });

        return response()->json([
            'labels' => array_column($monthlyData, 'label'),
            'fish' => array_column($monthlyData, 'fish'),
            'plant' => array_column($monthlyData, 'plant'),
        ]);
    }
}
