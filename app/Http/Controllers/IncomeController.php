<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Kreait\Firebase\Contract\Firestore;
use Illuminate\Support\Facades\Cache; 
use Carbon\Carbon;


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
    
    public function index(Request $request)
    {
        $role = $request->attributes->get('firebase_role');

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
            'sales', 'role', 'totalIncome', 'currentMonthFish', 'currentMonthPlant', 'lastMonthFish', 'lastMonthPlant', 'highestFishMonth', 'highestPlantMonth'
        ));
    }
    
        public function store(Request $request)
    {
        $validated = $request->validate([
            'itemName' => 'required|string',
            'type' => 'required|in:fish,plant',
            'quantitySold' => 'required|numeric|min:0.01',
            'unit' => 'required|in:kilos,pcs',
            'saleAmount' => 'required|numeric|min:0.01',
            'date' => 'required|date',
            'notes' => 'nullable|string'
        ]);
        
        $date = Carbon::parse($validated['date']);
        $season = $date->format('F Y'); 
        
        $saleData = [
            'itemName' => $validated['itemName'],
            'type' => $validated['type'],
            'quantitySold' => $validated['quantitySold'],
            'unit' => $validated['unit'],
            'saleAmount' => $validated['saleAmount'],
            'date' => $date,
            'season' => $season,
            'notes' => $validated['notes'] ?? '',
            'createdBy' => request()->attributes->get('firebase_user'),
            'createdAt' => Carbon::now('Asia/Manila')
        ];
        
        $this->firestore->collection('sales')->newDocument()->set($saleData);

        // Wipe out the sales and dashboard caches so calculations reset immediately
        Cache::forget('income_list');
        Cache::forget('income_chart_data_' . date('Y'));
        Cache::forget('income_chart_data');
        Cache::forget('dashboard_stats');
        Cache::forget('sales_chart_data');

        return redirect()->route('income.index')->with('success', 'Sale recorded');
    }
    
    public function archive(string $id)
    {
        $this->firestore->collection('sales')->document($id)->set([
            'status' => 'archived',
            'archivedAt' => Carbon::now('Asia/Manila')
        ], ['merge' => true]);

        Cache::forget('income_list');
        Cache::forget('income_archived_list');
        Cache::forget('income_chart_data_' . date('Y'));
        Cache::forget('income_chart_data');
        Cache::forget('dashboard_stats');
        Cache::forget('sales_chart_data');

        return redirect()->route('income.index')->with('success', 'Sale archived');
    }

    public function restore(string $id)
    {
        $this->firestore->collection('sales')->document($id)->set([
            'status' => 'active',
            'restoredAt' => Carbon::now('Asia/Manila')
        ], ['merge' => true]);

        Cache::forget('income_list');
        Cache::forget('income_archived_list');
        Cache::forget('income_chart_data_' . date('Y'));
        Cache::forget('income_chart_data');
        Cache::forget('dashboard_stats');
        Cache::forget('sales_chart_data');

        return redirect()->route('income.archived')->with('success', 'Sale restored');
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
