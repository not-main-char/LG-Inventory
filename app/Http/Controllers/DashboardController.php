<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Kreait\Firebase\Contract\Firestore;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;
use App\Support\InventoryAlerts;

class DashboardController extends Controller
{
    protected $firestore;
    
    public function __construct(Firestore $firestore)
    {
        $this->firestore = $firestore->database();
    }
    
    public function index()
    {
        $stats = Cache::remember('dashboard_stats', 300, function () {
            $now = Carbon::now('Asia/Manila');
            $currentMonthStart = $now->clone()->startOfMonth();
            $currentMonthEnd = $now->clone()->endOfMonth();
            $lastMonthStart = $now->clone()->subMonth()->startOfMonth();
            $lastMonthEnd = $now->clone()->subMonth()->endOfMonth();
            
            //fetch all sales once
            $salesDocs = $this->firestore->collection('sales')->documents();
            
            $totalIncome = 0;
            $currentMonthIncome = 0;
            $lastMonthIncome = 0;
            $lastMonthLabel = $now->clone()->subMonth()->format('F Y');
            
            foreach ($salesDocs as $doc) {
                $data = $doc->data();
                if (isset($data['status']) && $data['status'] === 'archived') continue;
                $amount = (float)($data['saleAmount'] ?? 0);
                $totalIncome += $amount;
                
                $rawDate = $data['date'] ?? null;
                $dateTime = null;
                
                //parse date
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
                
                // Current month
                if ($dateTime->between($currentMonthStart, $currentMonthEnd)) {
                    $currentMonthIncome += $amount;
                }
                
                //last month
                if ($dateTime->between($lastMonthStart, $lastMonthEnd)) {
                    $lastMonthIncome += $amount;
                }
            }
            
            //low stock alerts count
            $items = $this->firestore->collection('inventory_items')->documents();
            $lowStockCount = 0;
            foreach ($items as $item) {
                $data = $item->data();
                if (isset($data['status']) && $data['status'] === 'archived') continue;
                if (InventoryAlerts::isLow($data)) {
                    $lowStockCount++;
                }
            }

            return [
                'currentMonthIncome' => $currentMonthIncome,
                'currentMonthLabel' => $now->format('F Y'),
                'lastMonthIncome' => $lastMonthIncome,
                'lastMonthLabel' => $lastMonthLabel,
                'lowStockCount' => $lowStockCount
            ];
        });
        
        return view('dashboard', $stats);
    }
}
