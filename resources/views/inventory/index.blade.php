@extends('layouts.app', ['title' => 'Inventory'])

@section('content')
@php
    use App\Support\InventoryAlerts;
    $lowStockCount = 0;
    foreach ($items as $it) {
        if (InventoryAlerts::isLow($it->data())) $lowStockCount++;
    }
@endphp

<div class="grid grid-cols-1 sm:grid-cols-2 gap-5 mb-6">
    <div class="ledger-card accent-green p-5 flex items-center justify-between">
        <div>
            <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500">Total Items in Inventory</h3>
            <p class="figure text-3xl mt-1" style="color:var(--color-forest-700)">{{ count($items) }}</p>
        </div>
        <svg class="w-9 h-9" viewBox="0 0 24 24" fill="none" stroke="var(--color-forest-600)" stroke-width="1.8"><path d="M21 8L12 3 3 8l9 5 9-5z"/><path d="M3 8v8l9 5 9-5V8"/></svg>
    </div>
    <div class="alert-card {{ $lowStockCount > 0 ? 'alert-card-active' : 'alert-card-clear' }} p-5 flex items-center justify-between" style="{{ $lowStockCount > 0 ? '' : 'background: var(--color-amber-200); border-left: 5px solid var(--color-amber-600);' }}">
        <div>
            <h3 class="text-xs font-semibold uppercase tracking-wider {{ $lowStockCount > 0 ? 'text-white/80' : 'text-amber-600' }}">Active Low-Stock Alerts</h3>
            <p class="figure text-3xl mt-1" style="color: {{ $lowStockCount > 0 ? '#ffffff' : 'var(--color-amber-600)' }}">{{ $lowStockCount }}</p>
        </div>
        <svg class="w-9 h-9" viewBox="0 0 24 24" fill="none" stroke="{{ $lowStockCount > 0 ? '#fff' : 'var(--color-amber-600)' }}" stroke-width="1.8"><path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.3 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.7 3.86a2 2 0 00-3.4 0z"/></svg>
    </div>
</div>

<div class="flex items-center justify-between mb-4">
    <h3 class="font-display text-base font-semibold" style="color:var(--color-ink-900)">Stock Records</h3>
    @if($role === 'admin')
        <div class="flex gap-2">
            <button onclick="openItemModal()" class="btn-primary px-4 py-2.5 text-sm flex items-center gap-2">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 5v14M5 12h14"/></svg>
                Add New Item
            </button>
            <button onclick="openManualDeductionModal()" class="btn-ghost px-4 py-2.5 text-sm flex items-center gap-2" style="border: 1px solid var(--color-rust-300); color: var(--color-rust-600)">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M19 12H5M12 19V5"/></svg>
                Manual Deduction
            </button>
            <button onclick="openRestockModal()" class="btn-ghost px-4 py-2.5 text-sm flex items-center gap-2" style="border: 1px solid var(--color-forest-300); color: var(--color-forest-700)">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 5v14M5 12h14"/></svg>
                Restock Item
            </button>
        </div>
    @endif
</div>

<div class="ledger-card overflow-hidden mb-8">
    <div class="overflow-x-auto">
    <table class="min-w-full ledger-table">
        <thead>
            <tr>
                <th class="p-3 text-left">Item</th>
                <th class="p-3 text-left">Stock / Unit</th>
                <th class="p-3 text-left">Date Stocked</th>
                <th class="p-3 text-left">Consumption</th>
                <th class="p-3 text-left">Days Remaining</th>
                <th class="p-3 text-left">Actions</th>
            </tr>
        </thead>
        <tbody>
            @foreach($items as $item)
            @php
                $data = $item->data();
                $id = $item->id();
                $isLow = \App\Support\InventoryAlerts::isLow($data);
                $daysLeft = \App\Support\InventoryAlerts::daysRemaining($data);
                $cyclesLeft = \App\Support\InventoryAlerts::cyclesRemaining($data);
                $stampClass = $data['type'] === 'fish' ? 'stamp-fish' : ($data['type'] === 'plant' ? 'stamp-plant' : 'stamp-supplies');
            @endphp
            <tr class="border-b border-[#F1ECDC] {{ $isLow ? 'low-stock-row' : '' }}">
                <td class="p-3 align-middle">
                    <div class="flex items-center gap-2.5">
                        <span class="stamp {{ $stampClass }}">{{ $data['type'] }}</span>
                        <span class="text-sm font-medium text-gray-800">{{ $data['name'] }}</span>
                        @if($isLow)
                            <span class="text-[10px] font-bold uppercase tracking-wide px-1.5 py-0.5 rounded text-white" style="background:var(--color-rust-600)">Low</span>
                        @endif
                    </div>
                </td>
                <td class="p-3 align-middle">
                    <span class="figure text-sm">{{ rtrim(rtrim(number_format((float)$data['currentStock'], 4, '.', ''), '0'), '.') }} {{ $data['unit'] }}</span>
                </td>
                <td class="p-3 align-middle text-sm text-gray-600">
                    {{ isset($data['lastStockUpdate']) ? $data['lastStockUpdate']->toDateTime()->format('M d, Y') : 'N/A' }}
                </td>
                <td class="p-3 align-middle text-sm text-gray-600">
                    @if($data['usageFrequency'] === 'daily' && ($data['dailyConsumptionAmount'] ?? 0) > 0)
                        Eats <strong>{{ $data['dailyConsumptionAmount'] }} {{ $data['consumptionUnit'] ?? $data['unit'] }}</strong>/day (auto)
                        <div class="text-[11px] text-gray-400">1 {{ $data['unit'] }} = {{ $data['conversionRate'] ?? 1 }} {{ $data['consumptionUnit'] ?? $data['unit'] }}</div>
                    @elseif($data['usageFrequency'] === 'seasonal')
                        @if(($data['seedsPerCycle'] ?? 0) > 0)
                            <strong>{{ $data['seedsPerCycle'] }} {{ $data['unit'] }}</strong> used per cycle
                        @endif
                        @if(($data['daysToMaturity'] ?? 0) > 0)
                            <div class="text-[11px] text-gray-400">{{ $data['daysToMaturity'] }}-day cycle</div>
                        @endif
                    @else
                        Manually Deduction
                    @endif
                </td>
                <td class="p-3 align-middle text-sm">
                    @if($daysLeft !== null)
                        <span class="figure" style="color: {{ $isLow ? 'var(--color-rust-600)' : 'var(--color-ink-700)' }}">{{ $daysLeft }} days left</span>
                        @if($isLow)<div class="text-[11px] font-semibold" style="color:var(--color-rust-600)">Won't last the week — restock now</div>@endif
                    @elseif($cyclesLeft !== null)
                        <span class="figure" style="color: {{ $isLow ? 'var(--color-rust-600)' : 'var(--color-ink-700)' }}">{{ $cyclesLeft }} cycle(s) left</span>
                        @if($isLow)<div class="text-[11px] font-semibold" style="color:var(--color-rust-600)">Won't cover next season — restock seeds</div>@endif
                    @else
                        <span class="text-gray-400">—</span>
                    @endif
                </td>
                <td class="p-3 align-middle">
                    <div class="flex items-center gap-3 text-sm">
                        <button onclick="viewHistory('{{ $id }}', '{{ $data['name'] }}')" class="font-medium hover:underline" style="color:var(--color-water-600)">History</button>
                        @if($role === 'admin')
	                            <button onclick='editItem(@json(array_merge(["id" => $id], $data)))' class="font-medium hover:underline" style="color:var(--color-forest-700)">Edit</button>

                                <form method="POST" action="{{ route('inventory.archive', $id) }}" onsubmit="return confirm('Archive this item? It will be hidden from the active list.')" class="inline">
                                    @csrf
                                    <button type="submit" class="font-medium hover:underline" style="color:var(--color-rust-600)">Archive</button>
                                </form>
	                        @else
                            <span class="text-gray-400 italic text-xs">View only</span>
                        @endif
                    </div>
                </td>
            </tr>
            @endforeach
            @if(count($items) === 0)
            <tr><td colspan="6" class="p-8 text-center text-sm text-gray-400">No items recorded yet. Add the first item to start the ledger.</td></tr>
            @endif
        </tbody>
    </table>
    </div>
</div>

<!-- Add / Edit Item Modal  	Manual restock only -->
<div id="itemModal" class="fixed inset-0 modal-backdrop hidden items-center justify-center z-50 p-4 overflow-y-auto">
    <div class="modal-panel p-6 w-full max-w-md my-8">
        <h2 class="font-display text-xl font-semibold mb-4" id="modalTitle" style="color:var(--color-ink-900)">Add Item</h2>
        <form id="itemForm" method="POST" action="{{ route('inventory.store') }}" class="space-y-3">
            @csrf
            <input type="hidden" id="itemId" name="id">
            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">Name</label>
                <input type="text" id="itemName" name="name" class="input-field" required oninput="suggestKnownConversion()">
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">Category</label>
                <select id="itemType" name="type" class="input-field">
                    <option value="fish">Fish</option>
                    <option value="plant">Plant / Seed</option>
                    <option value="supplies">Supplies</option>
                </select>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Current Stock</label>
                    <input type="number" id="currentStock" name="currentStock" step="any" class="input-field" required>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Stock Unit</label>
                    <select id="unitSelect" name="unit" class="input-field" onchange="document.getElementById('unitOtherWrap').classList.toggle('hidden', this.value !== 'other')">
                        <option value="pcs">Pieces</option>
                        <option value="sack">Sack</option>
                        <option value="seeds">Seed</option>
                        <option value="other">Other…</option>
                    </select>
                    <div id="unitOtherWrap" class="hidden mt-1.5">
                        <input type="text" id="unitOther" name="unitOther" class="input-field" placeholder="Type the unit">
                    </div>
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">Usage Frequency</label>
                <select id="usageFrequency" name="usageFrequency" class="input-field" required onchange="toggleFrequencyFields()">
                    <option value="daily">Daily — consumed every day </option>
                    <option value="manual">Manual — self deductioin</option>
                </select>
                <p class="text-[11px] text-gray-400 mt-1">Low-stock alerts, Daily items alert when fewer than 7 days of stock remain; Seasonal items alert when stock can't cover the next cycle.</p>
            </div>

            <!-- DAILY FIELDS -->
            <div id="dailyFields" class="hidden space-y-3 p-3 rounded-xl" style="background:var(--color-water-100)">
                <p class="text-xs font-semibold" style="color:var(--color-water-600)">Daily consumption (auto-deducted every day, even if no one logs in)</p>
                <div>
                    <label class="block text-xs text-gray-600 mb-1">Daily Amount</label>
                    <input type="number" id="dailyConsumptionAmount" name="dailyConsumptionAmount" step="any" class="input-field" placeholder="e.g. 9">
                </div>

                <p id="fishFeedAssumedNote" class="text-[11px] text-gray-500">
                    Assuming this is fish feed — 1 sack ≈ 125 cups, so "Daily Amount" above is in cups.
                    <button type="button" onclick="showManualConsumptionFields()" class="underline font-medium" style="color:var(--color-water-600)">Set the units manually</button>
                </p>

                <div id="manualConsumptionFields" class="hidden space-y-2">
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="block text-xs text-gray-600 mb-1">Consumption Unit</label>
                            <select id="consumptionUnit" name="consumptionUnit" class="input-field" onchange="document.getElementById('consumptionUnitOtherWrap').classList.toggle('hidden', this.value !== 'other')">
                                <option value="cup">Cup</option>
                                <option value="kg">Kilogram</option>
                                <option value="pcs">Pieces</option>
                                <option value="other">Other…</option>
                            </select>
                            <div id="consumptionUnitOtherWrap" class="hidden mt-1">
                                <input type="text" id="consumptionUnitOther" name="consumptionUnitOther" class="input-field" placeholder="Type the unit">
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-600 mb-1">Conversion Rate</label>
                            <input type="number" id="conversionRate" name="conversionRate" step="any" class="input-field" placeholder="e.g. 125">
                        </div>
                    </div>
                </div>
            </div> 
            <div class="flex justify-end gap-3 mt-4">
                <button type="button" onclick="closeItemModal()" class="btn-ghost px-4 py-2.5 text-sm">Cancel</button>
                <button type="submit" class="btn-primary px-4 py-2.5 text-sm">Save Item</button>
            </div>
        </form>
    </div>
</div>

<!-- Manual Deduction Modal (MOVED OUTSIDE) -->
<div id="manualDeductionModal" class="fixed inset-0 modal-backdrop hidden items-center justify-center z-50 p-4">
    <div class="modal-panel p-6 w-full max-w-md">
        <h2 class="font-display text-xl font-semibold mb-4" style="color:var(--color-ink-900)">Manual Stock Deduction</h2>
        <form method="POST" action="{{ route('inventory.manual-deduction') }}" class="space-y-3">
            @csrf
            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">Select Item</label>
                <select id="deductItemSelect" name="itemId" class="input-field" required onchange="updateDeductUnitDisplay()">
                    <option value="">-- Choose Item --</option>
                    @foreach($items as $item)
                        @php $data = $item->data(); @endphp
                        <option value="{{ $item->id() }}" 
                                data-unit="{{ $data['unit'] ?? 'pcs' }}" 
                                data-type="{{ $data['type'] ?? 'supplies' }}"
                                data-consumption-unit="{{ $data['consumptionUnit'] ?? '' }}"
                                data-conversion-rate="{{ $data['conversionRate'] ?? '' }}">
                            {{ $data['name'] ?? 'Unnamed' }} ({{ $data['currentStock'] ?? 0 }} {{ $data['unit'] ?? 'pcs' }})
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Quantity</label>
                    <input type="number" step="any" name="quantity" class="input-field" required min="0.01" placeholder="e.g. 2">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Deduction Unit</label>
                    <select id="deductUnitSelect" name="deductUnit" class="input-field" required>
                        <option value="">-- Select --</option>
                        <!-- Options will be populated dynamically via JavaScript -->
                    </select>
                </div>
            </div>

            <div id="deductUnitOtherWrap" class="hidden">
                <label class="block text-xs font-semibold text-gray-600 mb-1">Custom Unit</label>
                <input type="text" name="deductUnitOther" class="input-field" placeholder="e.g., Handful, Bunch">
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">Reason</label>
                <select name="reason" class="input-field" required>
                    <option value="">-- Select Reason --</option>
                    <option value="Used at home">Used at home</option>
                    <option value="Spoilage/Waste">Spoilage/Waste</option>
                    <option value="Testing/Sample">Testing/Sample</option>
                    <option value="Damaged">Damaged</option>
                    <option value="Lost/Theft">Lost/Theft</option>
                    <option value="Other">Other</option>
                </select>
            </div>

            <div class="pt-2 flex gap-2">
                <button type="submit" class="btn-primary w-full py-2.5 text-sm">Deduct Stock</button>
                <button type="button" onclick="closeManualDeductionModal()" class="btn-ghost w-full py-2.5 text-sm">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Restock Modal (NEW) -->
<div id="restockModal" class="fixed inset-0 modal-backdrop hidden items-center justify-center z-50 p-4">
    <div class="modal-panel p-6 w-full max-w-md">
        <h2 class="font-display text-xl font-semibold mb-4" style="color:var(--color-ink-900)">Restock Item</h2>
        <form method="POST" action="{{ route('inventory.restock') }}" class="space-y-3">
            @csrf
            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">Select Item</label>
                <select id="restockItemSelect" name="itemId" class="input-field" required onchange="updateRestockUnitDisplay()">
                    <option value="">-- Choose Item --</option>
                    @foreach($items as $item)
                        @php $data = $item->data(); @endphp
                        <option value="{{ $item->id() }}" data-unit="{{ $data['unit'] ?? '' }}">{{ $data['name'] ?? 'Unnamed' }} ({{ $data['currentStock'] ?? 0 }} {{ $data['unit'] ?? '' }})</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">Stock Unit</label>
                <p id="restockUnitDisplay" class="text-sm text-gray-600 py-2">Select an item first</p>
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">Quantity to Add</label>
                <input type="number" step="any" name="quantity" class="input-field" required min="0.01">
            </div>
            <div class="pt-2 flex gap-2">
                <button type="submit" class="btn-primary w-full py-2.5 text-sm">Add to Stock</button>
                <button type="button" onclick="closeRestockModal()" class="btn-ghost w-full py-2.5 text-sm">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- History Modal -->
<div id="historyModal" class="fixed inset-0 modal-backdrop hidden items-center justify-center z-50 p-4 overflow-y-auto">
    <div class="modal-panel p-6 w-full max-w-md my-8">
        <h2 class="font-display text-xl font-semibold mb-4" id="historyTitle" style="color:var(--color-ink-900)">Stock History</h2>
        <div id="historyList" class="space-y-2 max-h-96 overflow-y-auto"></div>
        <button type="button" onclick="closeHistoryModal()" class="btn-ghost w-full py-2.5 text-sm mt-4">Close</button>
    </div>
</div>

@endsection

@push('scripts')
<script>
    function toggleFrequencyFields() {
        const freq = document.getElementById('usageFrequency').value;
        const dailyFields = document.getElementById('dailyFields');
        if (dailyFields) dailyFields.classList.toggle('hidden', freq !== 'daily');
        
        const seasonalFields = document.getElementById('seasonalFields');
        if (seasonalFields) seasonalFields.classList.toggle('hidden', freq !== 'seasonal');
    }

    function showManualConsumptionFields() {
        document.getElementById('manualConsumptionFields').classList.remove('hidden');
        document.getElementById('fishFeedAssumedNote').classList.add('hidden');
    }

    async function suggestKnownConversion() {
        const name = document.getElementById('itemName').value;
        if (!name || name.length < 3) return;
        
        try {
            const res = await fetch(`/inventory/known-conversion?name=${encodeURIComponent(name)}`);
            const known = await res.json();
            if (known) {
                setSelectValue('consumptionUnit', 'consumptionUnitOtherWrap', 'consumptionUnitOther', known.consumptionUnit, ['cup','kg','pcs']);
                document.getElementById('conversionRate').value = known.conversionRate;
                document.getElementById('manualConsumptionFields').classList.add('hidden');
                document.getElementById('fishFeedAssumedNote').classList.remove('hidden');
            } else {
                showManualConsumptionFields();
            }
        } catch (e) { /* lookup failed, leave as-is */ }
    }

    function openItemModal() {
        const modal = document.getElementById('itemModal');
        if (!modal) {
            console.error('itemModal not found');
            return;
        }
        document.getElementById('itemForm').reset();
        document.getElementById('modalTitle').innerText = 'Add Item';
        document.getElementById('itemForm').action = '{{ route('inventory.store') }}';
        const methodInput = document.querySelector('#itemForm input[name="_method"]');
        if (methodInput) methodInput.remove();
        toggleFrequencyFields();
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }

    function closeItemModal() {
        const modal = document.getElementById('itemModal');
        if (modal) {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }
    }

    function setSelectValue(selectId, otherWrapId, otherInputId, value, knownOptions) {
        const select = document.getElementById(selectId);
        if (!value) return;
        if (knownOptions.includes(value)) {
            select.value = value;
            document.getElementById(otherWrapId).classList.add('hidden');
        } else {
            select.value = 'other';
            document.getElementById(otherWrapId).classList.remove('hidden');
            document.getElementById(otherInputId).value = value;
        }
    }

    function editItem(item) {
        document.getElementById('modalTitle').innerText = 'Edit Item';
        document.getElementById('itemId').value = item.id;
        document.getElementById('itemName').value = item.name;
        document.getElementById('itemType').value = item.type;
        document.getElementById('currentStock').value = item.currentStock;
        document.getElementById('usageFrequency').value = item.usageFrequency || 'manual';
        setSelectValue('unitSelect', 'unitOtherWrap', 'unitOther', item.unit, ['pcs','sack','seeds']);

        if (item.usageFrequency === 'daily') {
            document.getElementById('dailyConsumptionAmount').value = item.dailyConsumptionAmount ?? '';
            document.getElementById('conversionRate').value = item.conversionRate ?? '';
            setSelectValue('consumptionUnit', 'consumptionUnitOtherWrap', 'consumptionUnitOther', item.consumptionUnit, ['cup','kg','pcs']);
            if (item.consumptionUnit !== 'cup' || Number(item.conversionRate) !== 125) {
                showManualConsumptionFields();
            } else {
                document.getElementById('manualConsumptionFields').classList.add('hidden');
                document.getElementById('fishFeedAssumedNote').classList.remove('hidden');
            }
        }
        if (item.usageFrequency === 'seasonal') {
            document.getElementById('seedsPerCycle').value = item.seedsPerCycle ?? '';
            document.getElementById('daysToMaturity').value = item.daysToMaturity ?? '';
        }

        document.getElementById('itemForm').action = `/inventory/${item.id}`;
        let methodInput = document.createElement('input');
        methodInput.type = 'hidden'; methodInput.name = '_method'; methodInput.value = 'PUT';
        document.getElementById('itemForm').appendChild(methodInput);
        document.getElementById('dailyFields').classList.toggle('hidden', item.usageFrequency !== 'daily');
        showModal('itemModal');
    }

    function showModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }
    }

    function closeModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }
    }

    function closeHistoryModal() {
        closeModal('historyModal');
    }

    function openManualDeductionModal() {
        showModal('manualDeductionModal');
    }

    function closeManualDeductionModal() {
        closeModal('manualDeductionModal');
    }

    function openRestockModal() {
        showModal('restockModal');
    }

    function closeRestockModal() {
        closeModal('restockModal');
    }

    function updateDeductUnitDisplay() {
        const select = document.getElementById('deductItemSelect');
        const option = select.options[select.selectedIndex];
        if (!option.value) return;

        const itemUnit = option.getAttribute('data-unit') || 'pcs';
        const consumptionUnit = option.getAttribute('data-consumption-unit') || '';
        const deductUnitSelect = document.getElementById('deductUnitSelect');
        const otherWrap = document.getElementById('deductUnitOtherWrap');
        
        // Clear current options
        deductUnitSelect.innerHTML = '<option value="">-- Select --</option>';
        
        // Always allow the Base Unit
        deductUnitSelect.innerHTML += `<option value="${itemUnit}">${itemUnit}</option>`;

        // If Admin has set a custom Consumption Unit (like cup, kg, or caps), allow that too
        if (consumptionUnit && consumptionUnit !== itemUnit) {
            deductUnitSelect.innerHTML += `<option value="${consumptionUnit}">${consumptionUnit}</option>`;
        }

        // Special fallback for Sack items if no consumption unit is set
        if (itemUnit === 'sack' && !consumptionUnit) {
            deductUnitSelect.innerHTML += `
                <option value="kg">Kilogram (Kg)</option>
                <option value="cup">Cup</option>
            `;
        }

        // Default to the base unit
        deductUnitSelect.value = itemUnit;
        otherWrap.classList.add('hidden');
    }

    function updateRestockUnitDisplay() {
        const select = document.getElementById('restockItemSelect');
        const option = select.options[select.selectedIndex];
        const unit = option.getAttribute('data-unit') || 'units';
        document.getElementById('restockUnitDisplay').innerText = `Adding to: ${unit}`;
    }

    async function deleteItem(id) {
        if (confirm('Delete this item permanently?')) {
            await fetch(`/inventory/${id}`, { method: 'DELETE', headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' } })
            location.reload();
        }
    }

    async function viewHistory(id, name) {
        document.getElementById('historyTitle').innerText = `Stock History — ${name}`;
        document.getElementById('historyList').innerHTML = '<p class="text-sm text-gray-400">Loading…</p>';
        showModal('historyModal');
        try {
            const res = await fetch(`/inventory/${id}/history`);
            const history = await res.json();
            const list = document.getElementById('historyList');
            if (!history || history.length === 0) {
                list.innerHTML = '<p class="text-sm text-gray-400 py-6 text-center">No stock movement recorded yet for this item.</p>';
                return;
            }
            list.innerHTML = history.map(h => `
	                <div class="border-b border-[#F1ECDC] pb-2">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-700">${h.action || h.type || 'Stock update'}</p>
                                <p class="text-xs text-gray-400">${h.date || ''}</p>
                            </div>
                            <span class="figure text-sm">${h.quantity ?? h.amount ?? ''}</span>
                        </div>
                        ${h.notes ? `<p class="text-[11px] text-gray-500 mt-1 italic">Reason: ${h.notes}</p>` : ''}
	                </div>
            `).join('');
        } catch (e) {
            document.getElementById('historyList').innerHTML = '<p class="text-sm text-rust-600 py-6 text-center">Could not load history.</p>';
        }
    }
    // When user changes the "Deduction Unit" dropdown, show/hide the "Custom Unit" field
    document.getElementById('deductUnitSelect').addEventListener('change', function() {
        const otherWrap = document.getElementById('deductUnitOtherWrap');
        if (this.value === 'other') {
            otherWrap.classList.remove('hidden');
        } else {
            otherWrap.classList.add('hidden'); 
        }
    });
</script>
@endpush
