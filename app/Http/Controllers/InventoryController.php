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

    // Every farm input that's stocked in sacks and used a little at a time.
    // The system automatically offers kg and cup as deduction choices for these,
    // computed from the constants below — the CAC Manager never has to configure
    // or calculate a conversion themselves.
    protected const SACK_ITEM_KEYWORDS = ['fish feed', 'feed', 'vitamins'];

    // 1 kg of floating fish feed pellets ≈ 8 standard (240 mL) cups, based on the
    // typical bulk density of floating pellets (0.45-0.55 kg/L). This is a computed
    // estimate; the farm can recalibrate it by weighing one actual cup of their feed.
    protected const CUPS_PER_KG = 8;

    // Standard commercial fish feed sack size sold in the Philippines (e.g. B-MEG,
    // Vitarich). Used only as a fallback when an item doesn't specify its own sack
    // weight (see 'sackWeightKg' on the item itself, which takes priority).
    protected const DEFAULT_SACK_WEIGHT_KG = 25;

    public function __construct(Firestore $firestore)
    {
        $this->firestore = $firestore->database();
    }

    /**
     * The sack weight (in kg) to use for a given item: the item's own saved
     * value if the admin set one, otherwise the standard 25kg default.
     */
    protected function resolveSackWeightKg($item)
    {
        $weight = (float)($item['sackWeightKg'] ?? 0);
        return $weight > 0 ? $weight : self::DEFAULT_SACK_WEIGHT_KG;
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
            'createdAt' => Carbon::now('Asia/Manila'),
        ];

        // Sack weight: how many kg are in one sack of this item (25kg, 50kg, or a
        // custom size). Only relevant for sack-stocked items; used to correctly
        // scale the kg/cup deduction conversions to the sack's actual size.
        if ($unit === 'sack') {
            $itemData['sackWeightKg'] = (float)($validated['sackWeightKg'] ?? self::DEFAULT_SACK_WEIGHT_KG);
        }

        // Daily consumption fields
        if ($validated['usageFrequency'] === 'daily') {
            $itemData['dailyConsumptionAmount'] = (float)($validated['dailyConsumptionAmount'] ?? 0);
            $itemData['consumptionUnit'] = $validated['consumptionUnit'] ?? 'cup';

            if (!empty($validated['conversionRate'])) {
                // Admin explicitly set their own conversion rate
                $itemData['conversionRate'] = (float)$validated['conversionRate'];
            } elseif ($unit === 'sack' && $itemData['consumptionUnit'] === 'cup') {
                // Compute from the verified cups-per-kg figure and this item's sack weight
                $itemData['conversionRate'] = $itemData['sackWeightKg'] * self::CUPS_PER_KG;
            } else {
                $itemData['conversionRate'] = null;
            }
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

    /**
     * Normalize free-text inventory fields while leaving select values and
     * sentence-style notes/reasons untouched.
     */
    protected function normalizeInventoryInput(array $validated): array
    {
        foreach (['name', 'unitOther', 'consumptionUnitOther'] as $field) {
            if (isset($validated[$field]) && is_string($validated[$field])) {
                $validated[$field] = strtoupper(trim($validated[$field]));
            }
        }

        if (($validated['unit'] ?? null) === 'other' && !empty($validated['unitOther'])) {
            $validated['unit'] = $validated['unitOther'];
        }

        if (($validated['consumptionUnit'] ?? null) === 'other' && !empty($validated['consumptionUnitOther'])) {
            $validated['consumptionUnit'] = $validated['consumptionUnitOther'];
        }

        // Sack weight is only relevant for sack-stocked items. Removing it
        // for every other unit prevents an empty/stale hidden input from
        // triggering the numeric validation rule.
        if (($validated['unit'] ?? null) !== 'sack') {
            unset($validated['sackWeightKg']);
        } elseif (!isset($validated['sackWeightKg']) || $validated['sackWeightKg'] === '') {
            $validated['sackWeightKg'] = self::DEFAULT_SACK_WEIGHT_KG;
        }

        return $validated;
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
            'consumptionUnitOther' => 'nullable|string|max:50',
            'conversionRate' => 'nullable|numeric|min:0.01',
            'seedsPerCycle' => 'nullable|numeric|min:0',
            'daysToMaturity' => 'nullable|integer|min:0',
            'procurementSource' => 'required|in:DA,Farm Purchase',
            'sackWeightKg' => 'exclude_unless:unit,sack|nullable|numeric|min:1',
        ]);

        $validated = $this->normalizeInventoryInput($validated);
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
            'consumptionUnitOther' => 'nullable|string|max:50',
            'conversionRate' => 'nullable|numeric|min:0.01',
            'seedsPerCycle' => 'nullable|numeric|min:0',
            'daysToMaturity' => 'nullable|integer|min:0',
            'procurementSource' => 'required|in:DA,Farm Purchase',
            'sackWeightKg' => 'exclude_unless:unit,sack|nullable|numeric|min:1',
        ]);

        $validated = $this->normalizeInventoryInput($validated);
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
        ];

        // Sack weight: how many kg are in one sack of this item. Only relevant
        // for sack-stocked items; used to correctly scale the kg/cup deduction
        // conversions to the sack's actual size (25kg, 50kg, or custom).
        if ($unit === 'sack') {
            $updateData['sackWeightKg'] = (float)($validated['sackWeightKg'] ?? self::DEFAULT_SACK_WEIGHT_KG);
        }

        // Update consumption fields based on frequency
        if ($validated['usageFrequency'] === 'daily') {
            $updateData['dailyConsumptionAmount'] = (float)($validated['dailyConsumptionAmount'] ?? 0);
            $updateData['consumptionUnit'] = $validated['consumptionUnit'] ?? 'cup';

            if (!empty($validated['conversionRate'])) {
                $updateData['conversionRate'] = (float)$validated['conversionRate'];
            } elseif ($unit === 'sack' && $updateData['consumptionUnit'] === 'cup') {
                $sackWeightKg = $updateData['sackWeightKg'] ?? self::DEFAULT_SACK_WEIGHT_KG;
                $updateData['conversionRate'] = $sackWeightKg * self::CUPS_PER_KG;
            } else {
                $updateData['conversionRate'] = null;
            }
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
            $deductUnit = $validated['deductUnit'];

            // Perform conversion if deduction unit is different from item's base unit.
            // The CAC Manager can deduct in whichever unit they actually have on hand
            // (a cup, a weighing scale, etc.) — they never need to know or calculate
            // the conversion themselves. Resolution order:
            //   1. A conversion rate the admin explicitly saved for this exact item
            //   2. For sack items, a rate computed from the item's own sack weight
            //      (25kg/50kg/custom, set when the item was added) times the
            //      farm's verified cups-per-kg figure — so a 50kg sack correctly
            //      converts to twice as many cups as a 25kg sack of the same feed
            if ($itemUnit !== $deductUnit) {
                $conversionRate = null;

                if (isset($item['conversionRate']) && (float)$item['conversionRate'] > 0 && ($item['consumptionUnit'] ?? '') === $deductUnit) {
                    $conversionRate = (float)$item['conversionRate'];
                }

                if ($conversionRate === null && $itemUnit === 'sack') {
                    $sackWeightKg = $this->resolveSackWeightKg($item);
                    if ($deductUnit === 'kg') {
                        $conversionRate = $sackWeightKg;
                    } elseif ($deductUnit === 'cup') {
                        $conversionRate = $sackWeightKg * self::CUPS_PER_KG;
                    }
                }

                if ($conversionRate !== null && $conversionRate > 0) {
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
        foreach (self::SACK_ITEM_KEYWORDS as $keyword) {
            if ($name !== '' && str_contains($name, $keyword)) {
                // The frontend uses cupsPerKg and defaultSackWeightKg to compute a
                // live estimate (e.g. "25kg sack ≈ 200 cups"), and lets the admin
                // change the sack weight if theirs is different (e.g. 50kg).
                return response()->json([
                    'unit' => 'sack',
                    'consumptionUnit' => 'cup',
                    'cupsPerKg' => self::CUPS_PER_KG,
                    'defaultSackWeightKg' => self::DEFAULT_SACK_WEIGHT_KG,
                ]);
            }
        }
        return response()->json(null);
    }
}
