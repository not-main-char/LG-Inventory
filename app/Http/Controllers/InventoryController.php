<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Kreait\Firebase\Contract\Firestore;
use Illuminate\Support\Facades\Cache; 
use Carbon\Carbon;
use App\Support\InventoryAlerts;

if (!class_exists(__NAMESPACE__ . '\\CachedFirestoreTimestamp')) {
    class CachedFirestoreTimestamp implements \JsonSerializable
    {
        protected $dateString;
        protected $timezone;

        public function __construct(\DateTimeInterface $dateTime)
        {
            $this->dateString = $dateTime->format('Y-m-d H:i:s.u');
            $this->timezone = $dateTime->getTimezone()->getName();
        }

        public function toDateTime()
        {
            return new \DateTime($this->dateString, new \DateTimeZone($this->timezone));
        }

        public function get()
        {
            return $this->toDateTime();
        }

        public function format($format)
        {
            return $this->toDateTime()->format($format);
        }

        public function __toString()
        {
            return $this->toDateTime()->format('Y-m-d H:i:s');
        }

        public function jsonSerialize(): mixed
        {
            return $this->dateString;
        }
    }
}

if (!class_exists(__NAMESPACE__ . '\\CachedFirestoreDocument')) {
    class CachedFirestoreDocument
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

        private function sanitizeData($data)
        {
            if (is_array($data)) {
                foreach ($data as $key => $value) {
                    $data[$key] = $this->sanitizeData($value);
                }
            } elseif ($data instanceof \Google\Cloud\Core\Timestamp) {
                return new CachedFirestoreTimestamp($data->get());
            } elseif (is_object($data) && method_exists($data, 'toDateTime')) {
                return new CachedFirestoreTimestamp($data->toDateTime());
            } elseif (is_object($data) && method_exists($data, 'get')) {
                try {
                    $resolved = $data->get();
                    if ($resolved instanceof \DateTimeInterface) {
                        return new CachedFirestoreTimestamp($resolved);
                    }
                } catch (\Throwable $e) {}
            }
            return $data;
        }
    }
}


class InventoryController extends Controller
{
    protected $firestore;

    protected const KNOWN_CONVERSIONS = [
        'fish feed' => ['unit' => 'sack', 'consumptionUnit' => 'cup', 'conversionRate' => 125],
        'feed' => ['unit' => 'sack', 'consumptionUnit' => 'cup', 'conversionRate' => 125],
        'vitamins' => ['unit' => 'sack', 'consumptionUnit' => 'cup', 'conversionRate' => 125],
    ];

    public function __construct(Firestore $firestore)
    {
        $this->firestore = $firestore->database();
    }

    public function index(Request $request)
    {
        $role = $request->attributes->get('firebase_role');

        // Trigger auto-deduction on page load so user doesn't need to run terminal commands
        // ONLY allow Admin or Manager to trigger the deduction to prevent duplicate triggers from Viewers
        if (in_array($role, ['admin', 'manager', 'CAC MANAGER'])) {
            try {
                $this->runDailyConsumption();
            } catch (\Exception $e) {
                // Silently fail if something goes wrong to not block the page load
                \Log::error('Auto-deduction failed on page load: ' . $e->getMessage());
            }
        }

        $items = Cache::remember('inventory_list', 300, function () {
            // Simplified query: Fetch all and filter in PHP to avoid composite index requirements
            // and handle items where 'status' field might be missing.
            $documents = $this->firestore->collection('inventory_items')->orderBy('name')->documents();

            $cachedList = [];
            foreach ($documents as $doc) {
                $data = $doc->data();
                if (isset($data['status']) && $data['status'] === 'archived') {
                    continue;
                }
                $cachedList[] = new CachedFirestoreDocument($doc->id(), $data);
            }
            return $cachedList;
        });

        return view('inventory.index', compact('items', 'role'));
    }

    protected function buildItemData($validated)
    {
        $unit = $validated['unit'] ?? 'pcs';
        if ($unit === 'other' && !empty($validated['unitOther'])) {
            $unit = $validated['unitOther'];
        }

        // Simplified: no automatic seasonal calculation
        $itemData = [
            'name' => $validated['name'],
            'type' => $validated['type'], // fish, plant, supplies
            'currentStock' => (float)$validated['currentStock'],
            'unit' => $unit,
            'usageFrequency' => $validated['usageFrequency'] ?? 'manual',
            'procurementSource' => $validated['procurementSource'],
            'procurementCost' => $validated['procurementSource'] === 'Farm Purchase' ? (float) ($validated['procurementCost'] ?? 0) : null,
            'createdAt' => Carbon::now('Asia/Manila'),
        ];

        // Daily consumption fields
        if ($validated['usageFrequency'] === 'daily') {
            $itemData['dailyConsumptionAmount'] = (float)($validated['dailyConsumptionAmount'] ?? 0);
            $itemData['consumptionUnit'] = $validated['consumptionUnit'] ?? 'cup';
            $itemData['conversionRate'] = (float)($validated['conversionRate'] ?? 125);
        }

        // Seasonal consumption fields
        if ($validated['usageFrequency'] === 'seasonal') {
            $itemData['seedsPerCycle'] = (float)($validated['seedsPerCycle'] ?? 0);
            $itemData['daysToMaturity'] = (int)($validated['daysToMaturity'] ?? 0);
        }

        // Stock history
        $itemData['stockHistory'] = [
            [
                'action' => 'Initial stock',
                'quantity' => $validated['currentStock'] . ' ' . ($validated['unit'] ?? 'pcs'),
                'date' => (Carbon::now('Asia/Manila'))->format('M d, Y H:i'),
            ]
        ];

        $itemData['lastStockUpdate'] = Carbon::now('Asia/Manila');

        return $itemData;
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:fish,plant,supplies',
            'currentStock' => 'required|numeric|min:0',
            'unit' => 'required|string|max:50',
            'unitOther' => 'nullable|string|max:50',
            'usageFrequency' => 'required|in:daily,seasonal,manual',
            'dailyConsumptionAmount' => 'nullable|numeric|min:0',
            'consumptionUnit' => 'nullable|string|max:50',
            'conversionRate' => 'nullable|numeric|min:0.01',
            'seedsPerCycle' => 'nullable|numeric|min:0',
            'daysToMaturity' => 'nullable|integer|min:0',
            'procurementSource' => 'required|in:DA,Farm Purchase',
            'procurementCost' => 'nullable|numeric|min:0',
        ]);

        $itemData = $this->buildItemData($validated);

        // Check if item with same name already exists (avoid duplicates)
        $existing = $this->firestore->collection('inventory_items')
            ->where('name', '=', $validated['name'])
            ->limit(1)
            ->documents();

        $existingCount = 0;
        foreach ($existing as $doc) {
            $existingCount++;
            break; // Only need to know if one exists
        }

        if ($existingCount > 0) {
            return back()->withErrors(['name' => 'An item with this name already exists. Edit it instead.']);
        }

        $this->firestore->collection('inventory_items')->newDocument()->set($itemData);

        Cache::forget('inventory_list');
        Cache::forget('dashboard_stats');

        return redirect()->route('inventory.index')->with('success', 'Item added successfully');
    }


    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:fish,plant,supplies',
            'currentStock' => 'required|numeric|min:0',
            'unit' => 'required|string|max:50',
            'unitOther' => 'nullable|string|max:50',
            'usageFrequency' => 'required|in:daily,seasonal,manual',
            'dailyConsumptionAmount' => 'nullable|numeric|min:0',
            'consumptionUnit' => 'nullable|string|max:50',
            'conversionRate' => 'nullable|numeric|min:0.01',
            'seedsPerCycle' => 'nullable|numeric|min:0',
            'daysToMaturity' => 'nullable|integer|min:0',
            'procurementSource' => 'required|in:DA,Farm Purchase',
            'procurementCost' => 'nullable|numeric|min:0',
        ]);

        $unit = $validated['unit'] ?? 'pcs';
        if ($unit === 'other' && !empty($validated['unitOther'])) {
            $unit = $validated['unitOther'];
        }

        $itemDoc = $this->firestore->collection('inventory_items')->document($id)->snapshot();
        if (!$itemDoc->exists()) {
            return back()->withErrors(['error' => 'Item not found']);
        }

        $oldData = $itemDoc->data();
        $newStock = (float)$validated['currentStock'];
        $oldStock = (float)($oldData['currentStock'] ?? 0);

        $updateData = [
            'name' => $validated['name'],
            'type' => $validated['type'],
            'unit' => $unit,
            'usageFrequency' => $validated['usageFrequency'],
            'procurementSource' => $validated['procurementSource'],
            'procurementCost' => $validated['procurementSource'] === 'Farm Purchase' ? (float) ($validated['procurementCost'] ?? 0) : null,
        ];

        // Update consumption fields based on frequency
        if ($validated['usageFrequency'] === 'daily') {
            $updateData['dailyConsumptionAmount'] = (float)($validated['dailyConsumptionAmount'] ?? 0);
            $updateData['consumptionUnit'] = $validated['consumptionUnit'] ?? 'cup';
            $updateData['conversionRate'] = (float)($validated['conversionRate'] ?? 125);
        }

        if ($validated['usageFrequency'] === 'seasonal') {
            $updateData['seedsPerCycle'] = (float)($validated['seedsPerCycle'] ?? 0);
            $updateData['daysToMaturity'] = (int)($validated['daysToMaturity'] ?? 0);
        }

        // Update stock and history if stock changed
        if ($newStock !== $oldStock) {
            $history = $oldData['stockHistory'] ?? [];
            $diff = $newStock - $oldStock;
            $action = $diff > 0 ? 'Stock adjusted (+' . $diff . ')' : 'Stock adjusted (' . $diff . ')';
            
            $history[] = [
                'action' => $action,
                'quantity' => ($diff > 0 ? '+' : '') . $diff . ' ' . $validated['unit'],
                'date' => (Carbon::now('Asia/Manila'))->format('M d, Y H:i'),
            ];

            $updateData['currentStock'] = $newStock;
            $updateData['stockHistory'] = $history;
            $updateData['lastStockUpdate'] = Carbon::now('Asia/Manila');
        }

        $this->firestore->collection('inventory_items')->document($id)->set($updateData, ['merge' => true]);

        Cache::forget('inventory_list');
        Cache::forget('dashboard_stats');

        return redirect()->route('inventory.index')->with('success', 'Item updated successfully');
    }

    public function archive(string $id)
    {
        $this->firestore->collection('inventory_items')->document($id)->set([
            'status' => 'archived',
            'archivedAt' => Carbon::now('Asia/Manila')
        ], ['merge' => true]);

        Cache::forget('inventory_list');
        Cache::forget('inventory_archived_list');
        Cache::forget('dashboard_stats');

        return redirect()->route('inventory.index')->with('success', 'Item archived');
    }

    public function restore(string $id)
    {
        $this->firestore->collection('inventory_items')->document($id)->set([
            'status' => 'active',
            'restoredAt' => Carbon::now('Asia/Manila')
        ], ['merge' => true]);

        Cache::forget('inventory_list');
        Cache::forget('inventory_archived_list');
        Cache::forget('dashboard_stats');

        return redirect()->route('inventory.archived')->with('success', 'Item restored');
    }

    public function archived(Request $request)
    {
        return redirect()->route('archives.index', ['tab' => 'inventory']);
    }

    public function unifiedArchives(Request $request)
    {
        $role = $request->attributes->get('firebase_role');
        $tab = $request->query('tab', 'inventory');

        $inventoryItems = Cache::remember('inventory_archived_list', 300, function () {
            $documents = $this->firestore->collection('inventory_items')
                ->where('status', '==', 'archived')
                ->documents();
            $cachedList = [];
            foreach ($documents as $doc) {
                $cachedList[] = new CachedFirestoreDocument($doc->id(), $doc->data());
            }
            return $cachedList;
        });

        $salesItems = Cache::remember('income_archived_list', 300, function () use ($request) {
            $documents = $this->firestore->collection('sales')
                ->where('status', '==', 'archived')
                ->documents();
            $cachedList = [];
            // We need CachedIncomeDocument here, but it's defined in IncomeController.
            // For simplicity in this unified view, we can just use the data or a generic wrapper.
            foreach ($documents as $doc) {
                $cachedList[] = (object)['id' => $doc->id(), 'data' => $doc->data()];
            }
            return $cachedList;
        });

        return view('archives.index', compact('inventoryItems', 'salesItems', 'role', 'tab'));
    }

    public function stockHistory(string $id)
    {
        $item = $this->firestore->collection('inventory_items')->document($id)->snapshot()->data();
        $history = $item['stockHistory'] ?? [];
        return response()->json(array_reverse($history));
    }

        public function runDailyConsumption()
    {
        $now = Carbon::now('Asia/Manila');
        $todayStr = $now->format('Y-m-d');
        
        // GLOBAL LOCK: Check if any deduction was already processed today by ANY user
        $systemRef = $this->firestore->collection('system_settings')->document('inventory_automation');
        $systemSnap = $systemRef->snapshot();
        
        if ($systemSnap->exists()) {
            $data = $systemSnap->data();
            $lastGlobalRun = $data['last_deduction_date'] ?? '';
            if ($lastGlobalRun === $todayStr) {
                return; // Already done for today, stop immediately
            }
        }

        $items = $this->firestore->collection('inventory_items')
            ->where('usageFrequency', '=', 'daily')
            ->documents();

        $batch = $this->firestore->bulkWriter();

        foreach ($items as $doc) {
            $item = $doc->data();
            $docRef = $this->firestore->collection('inventory_items')->document($doc->id());

            // Figure out how many days actually passed since the last deduction.
            if (isset($item['lastConsumptionAt'])) {
                $rawLastRun = $item['lastConsumptionAt'];
                if ($rawLastRun instanceof \Google\Cloud\Core\Timestamp) {
                    try {
                        $lastRun = $rawLastRun->get();
                    } catch (\Throwable $e) {
                        $lastRun = $now;
                    }
                } elseif (is_object($rawLastRun) && method_exists($rawLastRun, 'get')) {
                    try {
                        $lastRun = $rawLastRun->get();
                    } catch (\Throwable $e) {
                        $lastRun = $now;
                    }
                } elseif (is_object($rawLastRun) && method_exists($rawLastRun, 'toDateTime')) {
                    try {
                        $lastRun = $rawLastRun->toDateTime();
                    } catch (\Throwable $e) {
                        $lastRun = $now;
                    }
                } elseif ($rawLastRun instanceof \DateTimeInterface) {
                    $lastRun = $rawLastRun;
                } else {
                    try {
                        $lastRun = new \DateTime($rawLastRun);
                    } catch (\Throwable $e) {
                        $lastRun = $now;
                    }
                }
            } else {
                $lastRun = $now;
            }

            $lastRun = Carbon::instance($lastRun)->setTimezone('Asia/Manila');
            
            // Compare dates only (ignoring time)
            $lastRunDate = $lastRun->clone()->startOfDay();
            $nowDate = $now->clone()->startOfDay();
            
            $daysElapsed = $lastRunDate->diffInDays($nowDate);

            // If we already deducted today, skip it
            if (isset($item['lastConsumptionAt']) && $daysElapsed < 1) {
                continue;
            }
            
            // If it's the first time ever, just do 1 day
            if (!isset($item['lastConsumptionAt'])) {
                $daysElapsed = 1;
            }

            // Safety cap so a machine left off for months doesn't wipe stock to 0 in one shot.
            $daysElapsed = min($daysElapsed, 30);

            // Use dailyConsumptionAmount instead of consumptionRate
            $dailyAmount = $item['dailyConsumptionAmount'] ?? 0;
            $conversionRate = $item['conversionRate'] ?? 1;
            
            // Convert daily consumption amount to base unit
            $rateInBaseUnit = $conversionRate > 0 ? ($dailyAmount / $conversionRate) : 0;
            
            $totalDeduction = $rateInBaseUnit * $daysElapsed;

            $newStock = round($item['currentStock'] - $totalDeduction, 4);
            if ($newStock < 0) $newStock = 0;

            $history = $item['stockHistory'] ?? [];
            $history[] = [
                'action' => $daysElapsed > 1
                    ? "Auto daily consumption (catch-up x{$daysElapsed} days)"
                    : 'Auto daily consumption',
                'quantity' => '-' . round($totalDeduction, 4) . ' ' . ($item['unit'] ?? 'pcs'),
                'date' => $now->format('M d, Y H:i'),
            ];

            $batch->set($docRef, [
                'currentStock' => $newStock,
                'stockHistory' => $history,
                'lastConsumptionAt' => $now,
            ], ['merge' => true]);

            $logRef = $this->firestore->collection('inventory_consumption_logs')->newDocument();
            $batch->set($logRef, [
                'itemId' => $doc->id(),
                'quantityDeducted' => $totalDeduction,
                'daysCovered' => $daysElapsed,
                'date' => $now,
                'method' => 'auto-daily'
            ]);

            if (InventoryAlerts::isLow(array_merge($item, ['currentStock' => $newStock]))) {
                $notifRef = $this->firestore->collection('notifications')->newDocument();
                $batch->set($notifRef, [
                    'userId' => 'all',
                    'title' => 'Low Stock Alert',
                    'message' => "{$item['name']} is low ({$newStock} {$item['unit']} left)",
                    'read' => false,
                    'createdAt' => $now,
                    'type' => 'low_stock'
                ]);
            }
        }

        // Update the global lock date
        $systemRef->set(['last_deduction_date' => $todayStr], ['merge' => true]);

        $batch->flush();
        Cache::forget('inventory_list');
        Cache::forget('dashboard_stats');

        return true;
    }

    /**
     * Manual stock deduction with custom unit support
     */
    public function manualDeduction(Request $request)
    {
        $validated = $request->validate([
            'itemId' => 'required|string',
            'quantity' => 'required|numeric|min:0.01',
            'deductUnit' => 'required|string',
            'deductUnitOther' => 'nullable|string|max:100',
            'reason' => 'required|string',
        ]);

        try {
            $docRef = $this->firestore->collection('inventory_items')->document($validated['itemId']);
            $itemDoc = $docRef->snapshot();

            if (!$itemDoc->exists()) {
                return back()->withErrors(['itemId' => 'Item not found']);
            }

            $item = $itemDoc->data();
            $deductAmount = (float)$validated['quantity'];
            $itemUnit = $item['unit'] ?? 'pcs';
            $deductUnit = $validated['deductUnit'] === 'other' ? $validated['deductUnitOther'] : $validated['deductUnit'];

            // Perform conversion if deduction unit is different from item's base unit
            if ($itemUnit !== $deductUnit) {
                $conversionRate = 1;
                // Check for known conversions
                $itemNameLower = strtolower($item['name']);
                foreach (self::KNOWN_CONVERSIONS as $keyword => $conversion) {
                    if (str_contains($itemNameLower, $keyword) && $conversion['unit'] === $itemUnit && $conversion['consumptionUnit'] === $deductUnit) {
                        $conversionRate = $conversion['conversionRate'];
                        break;
                    }
                }
                
                // Priority 1: Use the Admin-defined conversion rate saved for this specific item
                if (isset($item['conversionRate']) && (float)$item['conversionRate'] > 0 && ($item['consumptionUnit'] ?? '') === $deductUnit) {
                    $conversionRate = (float)$item['conversionRate'];
                } 
                // Priority 2: Use the Global Known Conversions (fallback)
                else {
                    $itemNameLower = strtolower($item['name']);
                    foreach (self::KNOWN_CONVERSIONS as $keyword => $conversion) {
                        if (str_contains($itemNameLower, $keyword) && $conversion['unit'] === $itemUnit && $conversion['consumptionUnit'] === $deductUnit) {
                            $conversionRate = $conversion['conversionRate'];
                            break;
                        }
                    }
                }
                
                // Priority 3: Generic fallback for Sacks to Kilos if nothing else is defined
                if ($conversionRate === 1 && $itemUnit === 'sack' && $deductUnit === 'kg') {
                    $conversionRate = 25;
                }

                if ($conversionRate > 0) {
                    $deductAmount = $deductAmount / $conversionRate;
                } else {
                    //if no conversion rate, prevent deduction with different units
                    return back()->withErrors(['deductUnit' => "Cannot deduct in {$deductUnit}. No conversion rate defined for {$itemUnit} to {$deductUnit}."]);
                }
            }
            $currentStock = (float)($item['currentStock'] ?? 0);

            if ($deductAmount > $currentStock) {
                return back()->withErrors(['quantity' => "Cannot deduct {$deductAmount}. Only {$currentStock} {$item['unit']} available."]);
            }

            $newStock = $currentStock - $deductAmount;

            //use the deduction unit (not the item's original unit)
            $displayUnit = $itemUnit; // Display unit should be the item's base unit after conversion

            $history = $item['stockHistory'] ?? [];
            $history[] = [
                'action' => 'Manual deduction',
                'quantity' => '-' . $deductAmount . ' ' . $displayUnit,
                'date' => (Carbon::now('Asia/Manila'))->format('M d, Y H:i'),
                'notes' => $validated['reason'],
            ];

            $docRef->set([
                'currentStock' => $newStock,
                'stockHistory' => $history,
                'lastStockUpdate' => Carbon::now('Asia/Manila'),
            ], ['merge' => true]);

            Cache::forget('inventory_list');
            Cache::forget('dashboard_stats');

            return redirect()->route('inventory.index')->with('success', "Deducted {$deductAmount} {$displayUnit}");
        } catch (\Exception $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }

        /**
     * Restock an existing item - quick add without editing
     */
    public function restock(Request $request)
    {
        $validated = $request->validate([
            'itemId' => 'required|string',
            'quantity' => 'required|numeric|min:0.01',
            'notes' => 'nullable|string|max:255',
        ]);

        try {
            $docRef = $this->firestore->collection('inventory_items')->document($validated['itemId']);
            $itemDoc = $docRef->snapshot();

            if (!$itemDoc->exists()) {
                return back()->withErrors(['itemId' => 'Item not found']);
            }

            $item = $itemDoc->data();
            $newStock = $item['currentStock'] + $validated['quantity'];

            $history = $item['stockHistory'] ?? [];
            $history[] = [
                'action' => 'Restock',
                'quantity' => '+' . $validated['quantity'] . ' ' . ($item['unit'] ?? ''),
                'date' => (Carbon::now('Asia/Manila'))->format('M d, Y H:i'),
                'notes' => $validated['notes'] ?? '',
            ];

            $docRef->set([
                'currentStock' => $newStock,
                'stockHistory' => $history,
                'lastStockUpdate' => Carbon::now('Asia/Manila'),
            ], ['merge' => true]);

            Cache::forget('inventory_list');
            Cache::forget('dashboard_stats');

            return redirect()->route('inventory.index')->with('success', 'Stock added successfully');
        } catch (\Exception $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    public function processConsumption()
    {
        $this->runDailyConsumption();
        return back()->with('success', 'Daily consumption processed.');
    }

    public function knownConversion(Request $request)
    {
        $name = strtolower(trim($request->query('name', '')));
        foreach (self::KNOWN_CONVERSIONS as $keyword => $conversion) {
            if ($name !== '' && str_contains($name, $keyword)) {
                return response()->json($conversion);
            }
        }
        return response()->json(null);
    }
}
