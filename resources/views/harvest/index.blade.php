@extends('layouts.app', ['title' => 'Harvest'])

@section('content')
@include('inventory._tabs', ['active' => 'harvest'])

@php
    $unitLabel = fn ($u) => $u === 'pcs' ? 'pcs' : 'kilos';
    $num = fn ($n) => rtrim(rtrim(number_format((float) $n, 4, '.', ''), '0'), '.');
    $sourceLabel = ['hydroponics' => 'Hydroponics', 'aquaculture' => 'Aquaculture', 'landfarming' => 'Landfarming'];
@endphp

{{-- Tabs: which kind of harvest is being viewed. --}}
<div class="flex gap-2 p-1 bg-[#EFE9D8] rounded-xl w-fit mb-6 flex-wrap" id="harvestTabs">
    <button type="button" data-tab="plants" class="px-6 py-2.5 rounded-lg text-sm font-semibold transition">Plants</button>
    <button type="button" data-tab="fish" class="px-6 py-2.5 rounded-lg text-sm font-semibold transition">Fish</button>
    <button type="button" data-tab="disposals" class="px-6 py-2.5 rounded-lg text-sm font-semibold transition">Self-consumed &amp; DA</button>
</div>

{{-- Available stock: shows the kind of the active tab (plants or fish). --}}
<div id="availableSection">
<h3 class="font-display text-base font-semibold mb-3" style="color:var(--color-ink-900)">Available for Sale</h3>

<div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    @forelse($available as $a)
        <div class="ledger-card p-4" data-kind="{{ $a['kind'] }}">
            <span class="stamp {{ $a['kind'] === 'fish' ? 'stamp-fish' : 'stamp-plant' }}">{{ $a['kind'] }}</span>
            <p class="text-sm font-medium text-gray-800 mt-2">{{ $a['product'] }}@if(!empty($a['stage'])) <span class="text-xs font-normal text-gray-500">— {{ $a['stage'] }}</span>@endif</p>
            <p class="figure text-xl mt-1" style="color:var(--color-forest-700)">{{ $num($a['remaining']) }} <span class="text-sm font-normal text-gray-500">{{ $unitLabel($a['unit']) }}</span></p>
            @if($a['kind'] === 'fish' && !empty($a['remainingFish']))
                <p class="text-xs text-gray-500">{{ number_format($a['remainingFish']) }} fish left</p>
            @endif
            <p class="text-xs text-gray-400 mt-1">
                @if($a['price'] !== null)
                    Reference price ₱{{ number_format($a['price'], 2) }}@if(!empty($a['priceEstimated'])) <span title="{{ $a['priceNote'] }}">(estimate)</span>@endif
                @else
                    No reference price yet
                @endif
            </p>
        </div>
    @empty
        <p class="text-sm text-gray-400 col-span-full">Nothing is available to sell yet. Add a harvest to start.</p>
    @endforelse
    <p id="availableEmpty" class="hidden text-sm text-gray-400 col-span-full"></p>
</div>
</div>

{{-- ============ Plant harvests ============ --}}
<div data-panel="plants">
    <div class="flex items-center justify-between mb-3 flex-wrap gap-3">
        <h3 class="font-display text-base font-semibold" style="color:var(--color-ink-900)">Plant Harvests</h3>
        @if($role === 'admin')
            <button onclick="openHarvestModal()" class="btn-primary px-4 py-2.5 text-sm flex items-center gap-2">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 5v14M5 12h14"/></svg>
                Add Plant Harvest
            </button>
        @endif
    </div>


    <div class="ledger-card overflow-hidden mb-8">
        <div class="overflow-x-auto">
        <table class="min-w-full ledger-table" id="plantTable">
            <thead>
                <tr>
                    <th class="p-3 text-left">Harvest Date</th>
                    <th class="p-3 text-left">Product</th>
                    <th class="p-3 text-left">Source</th>
                    <th class="p-3 text-left">Harvested</th>
                    <th class="p-3 text-left">Remaining</th>
                    <th class="p-3 text-left">Notes</th>
                    <th class="p-3 text-left">Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach($harvestRows as $h)
                <tr class="border-b border-[#F1ECDC]" data-source="{{ $h['source'] }}">
                    <td class="p-3 align-middle text-sm text-gray-600 whitespace-nowrap">
                        {{ \Carbon\Carbon::parse($h['harvestDate'])->format('M d, Y') }}
                    </td>
                    <td class="p-3 align-middle">
                        <div class="flex items-center gap-2">
                            <span class="stamp {{ $h['kind'] === 'fish' ? 'stamp-fish' : 'stamp-plant' }}">{{ $h['kind'] }}</span>
                            <span class="text-sm font-medium text-gray-800">{{ $h['product'] }}</span>
                        </div>
                    </td>
                    <td class="p-3 align-middle text-sm text-gray-600">{{ $sourceLabel[$h['source']] ?? $h['source'] }}</td>
                    <td class="p-3 align-middle figure text-sm">{{ $num($h['quantity']) }} {{ $unitLabel($h['unit']) }}</td>
                    <td class="p-3 align-middle figure text-sm" style="color: {{ $h['remaining'] > 0.00001 ? 'var(--color-forest-700)' : '#9C9377' }}">{{ $num($h['remaining']) }} {{ $unitLabel($h['unit']) }}</td>
                    <td class="p-3 align-middle text-sm text-gray-500 max-w-[24rem] whitespace-normal">{{ filled($h['notes']) ? $h['notes'] : '—' }}</td>
                    <td class="p-3 align-middle">
                        @if($role === 'admin')
                            <form method="POST" action="{{ route('harvest.archive', $h['id']) }}" onsubmit="return confirm('Archive this harvest?')">
                                @csrf
                                <button type="submit" class="text-sm font-medium hover:underline" style="color:var(--color-rust-600)">Archive</button>
                            </form>
                        @else
                            <span class="text-gray-400 italic text-xs">View only</span>
                        @endif
                    </td>
                </tr>
                @endforeach
                @if(count($harvestRows) === 0)
                <tr><td colspan="7" class="p-8 text-center text-sm text-gray-400">No plant harvests recorded yet.</td></tr>
                @endif
            </tbody>
        </table>
        </div>
    </div>
</div>

{{-- ============ Fish ============ --}}
<div data-panel="fish" class="hidden">
    <div class="flex items-center justify-between mb-3 flex-wrap gap-3">
        <div>
            <h3 class="font-display text-base font-semibold" style="color:var(--color-ink-900)">Fish</h3>
        </div>
    </div>

    @if(!empty($fishSync['error']))
        <div class="mb-3 text-xs px-3 py-2 rounded-lg" style="background:#fdf1dc;color:#8a5a1e">{{ $fishSync['error'] }}</div>
    @endif

    <div class="ledger-card overflow-hidden mb-8">
        <div class="overflow-x-auto">
        <table class="min-w-full ledger-table">
            <thead>
                <tr>
                    <th class="p-3 text-left">Fish</th>
                    <th class="p-3 text-left">Growth Stage</th>
                    <th class="p-3 text-left">Batch</th>
                    <th class="p-3 text-left">Quantity</th>
                    <th class="p-3 text-left">Date</th>
                    <th class="p-3 text-left">Notes</th>
                    <th class="p-3 text-left">Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach($fishRows as $f)
                <tr class="border-b border-[#F1ECDC]">
                    <td class="p-3 align-middle">
                        <div class="flex items-center gap-2">
                            <span class="stamp stamp-fish">fish</span>
                            <span class="text-sm font-medium text-gray-800">{{ $f['product'] }}</span>
                        </div>
                    </td>
                    <td class="p-3 align-middle text-sm text-gray-600">{{ filled($f['growthStage']) ? $f['growthStage'] : '—' }}</td>
                    <td class="p-3 align-middle text-sm text-gray-600">{{ filled($f['batch']) ? $f['batch'] : '—' }}</td>
                    <td class="p-3 align-middle text-sm">
                        <span class="figure" style="color:{{ $f['remaining'] > 0.00001 ? 'var(--color-forest-700)' : '#9C9377' }}">{{ $num($f['remaining']) }} kg</span>
                        @if($f['fishCount'] > 0)
                            <div class="text-[11px] text-gray-500">{{ number_format($f['remainingFish']) }} fish left</div>
                        @endif
                    </td>
                    <td class="p-3 align-middle text-sm text-gray-600 whitespace-nowrap">{{ \Carbon\Carbon::parse($f['harvestDate'])->format('M d, Y') }}</td>
                    <td class="p-3 align-middle text-sm text-gray-500 max-w-[20rem] whitespace-normal">{{ filled($f['notes']) ? $f['notes'] : '—' }}</td>
                    <td class="p-3 align-middle">
                        @if($role === 'admin')
                            <form method="POST" action="{{ route('harvest.archive', $f['id']) }}" onsubmit="return confirm('Archive this fish record?')">
                                @csrf
                                <button type="submit" class="text-sm font-medium hover:underline" style="color:var(--color-rust-600)">Archive</button>
                            </form>
                        @else
                            <span class="text-gray-400 italic text-xs">View only</span>
                        @endif
                    </td>
                </tr>
                @endforeach
                @if(count($fishRows) === 0)
                <tr><td colspan="7" class="p-8 text-center text-sm text-gray-400">No fish batches yet. They appear here once Fish Monitoring receives pond data.</td></tr>
                @endif
            </tbody>
        </table>
        </div>
    </div>
</div>

{{-- ============ Self-consumed / given to the DA ============ --}}
<div data-panel="disposals" class="hidden">
    <div class="flex items-center justify-between mb-3">
        <h3 class="font-display text-base font-semibold" style="color:var(--color-ink-900)">Self-consumed and Given to the DA</h3>
        @if($role === 'admin')
            <button onclick="openDisposeModal()" class="btn-ghost px-4 py-2.5 text-sm">Record Self-consumption / DA</button>
        @endif
    </div>

    <div class="ledger-card overflow-hidden mb-8">
        <div class="overflow-x-auto">
        <table class="min-w-full ledger-table">
            <thead>
                <tr>
                    <th class="p-3 text-left">Date</th>
                    <th class="p-3 text-left">Product</th>
                    <th class="p-3 text-left">Went to</th>
                    <th class="p-3 text-left">Quantity</th>
                    <th class="p-3 text-left">Note</th>
                    <th class="p-3 text-left">Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach($disposals as $d)
                <tr class="border-b border-[#F1ECDC]">
                    <td class="p-3 align-middle text-sm text-gray-600">{{ \Carbon\Carbon::parse($d['date'])->format('M d, Y') }}</td>
                    <td class="p-3 align-middle text-sm font-medium text-gray-800">{{ $d['product'] }}</td>
                    <td class="p-3 align-middle text-sm text-gray-600">{{ $d['disposalType'] === 'da' ? 'Given to the DA' : 'Self-consumed' }}</td>
                    <td class="p-3 align-middle figure text-sm">{{ $num($d['quantity']) }} {{ $unitLabel($d['unit']) }}</td>
                    <td class="p-3 align-middle text-sm text-gray-500 max-w-[24rem] whitespace-normal">{{ filled($d['note']) ? $d['note'] : '—' }}</td>
                    <td class="p-3 align-middle">
                        @if($role === 'admin')
                            <form method="POST" action="{{ route('harvest.disposal.archive', $d['id']) }}" onsubmit="return confirm('Archive this record? The quantity goes back into the harvest.')">
                                @csrf
                                <button type="submit" class="text-sm font-medium hover:underline" style="color:var(--color-rust-600)">Archive</button>
                            </form>
                        @else
                            <span class="text-gray-400 italic text-xs">View only</span>
                        @endif
                    </td>
                </tr>
                @endforeach
                @if(count($disposals) === 0)
                <tr><td colspan="6" class="p-8 text-center text-sm text-gray-400">Nothing recorded yet.</td></tr>
                @endif
            </tbody>
        </table>
        </div>
    </div>
</div>

{{-- Add Harvest modal --}}
<div id="harvestModal" class="fixed inset-0 modal-backdrop hidden items-center justify-center z-50 p-4 overflow-y-auto">
    <div class="modal-panel p-6 w-full max-w-md my-8">
        <h2 class="font-display text-xl font-semibold mb-4" style="color:var(--color-ink-900)">Add Plant Harvest</h2>
        <form id="harvestForm" method="POST" action="{{ route('harvest.store') }}" class="space-y-3" onsubmit="return prepareHarvest()">
            @csrf
            <input type="hidden" name="product" id="hProduct">
            <input type="hidden" name="seedItemId" id="hSeedItemId">
            <input type="hidden" name="kind" id="hKind">

            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">Grown in</label>
                <div class="flex items-center gap-5 text-sm text-gray-700">
                    <label class="inline-flex items-center gap-2 cursor-pointer">
                        <input type="radio" name="source" value="hydroponics" class="h-source" checked>
                        <span>Hydroponics</span>
                    </label>
                    <label class="inline-flex items-center gap-2 cursor-pointer">
                        <input type="radio" name="source" value="landfarming" class="h-source">
                        <span>Landfarming</span>
                    </label>
                </div>
            </div>

            <div class="relative">
                <label class="block text-xs font-semibold text-gray-600 mb-1">Plant</label>
                <input type="text" id="hPlantInput" class="input-field" placeholder="Type to search, e.g. LETTUCE" autocomplete="off"
                       oninput="filterPlants()" onfocus="filterPlants(true)">
                <div id="hPlantList" class="hidden absolute z-20 left-0 right-0 mt-1 max-h-48 overflow-y-auto rounded-lg border border-[#ECE5CE] bg-white shadow-lg"></div>
                <label id="hNewWrap" class="hidden items-center gap-2 mt-2 text-xs text-gray-600 cursor-pointer">
                    <input type="checkbox" id="hNewPlant">
                    <span>This is a new plant — add it to the list</span>
                </label>
                <p id="hProductHint" class="text-[11px] text-gray-400 mt-1"></p>
                <p id="hPriceHint" class="text-xs mt-1" style="color:var(--color-forest-700)"></p>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Quantity</label>
                    <input type="number" step="any" min="0.01" name="quantity" class="input-field" required>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Unit</label>
                    <select name="unit" id="hUnit" class="input-field" required onchange="updatePriceHint()">
                        <option value="kilos">Kilos</option>
                        <option value="pcs">Pcs</option>
                    </select>
                </div>
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">Harvest Date</label>
                <input type="date" name="harvestDate" id="hDate" class="input-field" required>
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">Notes (optional)</label>
                <input type="text" name="notes" class="input-field" maxlength="255">
            </div>
            <div class="pt-2 flex gap-2">
                <button type="submit" class="btn-primary w-full py-2.5 text-sm">Save Harvest</button>
                <button type="button" onclick="closeModal('harvestModal')" class="btn-ghost w-full py-2.5 text-sm">Cancel</button>
            </div>
        </form>
    </div>
</div>

{{-- Self-consume / DA modal --}}
<div id="disposeModal" class="fixed inset-0 modal-backdrop hidden items-center justify-center z-50 p-4 overflow-y-auto">
    <div class="modal-panel p-6 w-full max-w-md my-8">
        <h2 class="font-display text-xl font-semibold mb-4" style="color:var(--color-ink-900)">Self-consume or Give to the DA</h2>
        @if(count($batches) === 0)
            <p class="text-sm text-gray-500 mb-4">There is no harvest stock left to record.</p>
            <button type="button" onclick="closeModal('disposeModal')" class="btn-ghost w-full py-2.5 text-sm">Close</button>
        @else
        <form method="POST" action="{{ route('harvest.dispose') }}" class="space-y-3">
            @csrf
            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">Harvest</label>
                <select name="harvestId" class="input-field" required>
                    @foreach($batches as $b)
                        <option value="{{ $b['id'] }}">{{ $b['product'] }}, {{ \Carbon\Carbon::parse($b['harvestDate'])->format('M d') }} ({{ $num($b['remaining']) }} {{ $unitLabel($b['unit']) }} left)</option>
                    @endforeach
                </select>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Went to</label>
                    <select name="disposalType" class="input-field" required>
                        <option value="self_consume">Self-consumed</option>
                        <option value="da">Given to the DA</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Quantity</label>
                    <input type="number" step="any" min="0.01" name="quantity" class="input-field" required>
                </div>
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">Date</label>
                <input type="date" name="date" id="dDate" class="input-field" required>
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">Note (optional)</label>
                <input type="text" name="note" class="input-field" maxlength="255">
            </div>
            <div class="pt-2 flex gap-2">
                <button type="submit" class="btn-primary w-full py-2.5 text-sm">Save</button>
                <button type="button" onclick="closeModal('disposeModal')" class="btn-ghost w-full py-2.5 text-sm">Cancel</button>
            </div>
        </form>
        @endif
    </div>
</div>

@endsection

@push('scripts')
<script>
    const priceHints = @json($priceHints);

    function localToday() {
        const d = new Date();
        return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
    }

    function showModal(id) {
        const m = document.getElementById(id);
        m.classList.remove('hidden'); m.classList.add('flex');
    }
    function closeModal(id) {
        const m = document.getElementById(id);
        m.classList.add('hidden'); m.classList.remove('flex');
    }

    // ---- Tabs ----
    const TAB_NAMES = ['plants', 'fish', 'disposals'];

    function activateTab(name) {
        if (!TAB_NAMES.includes(name)) name = 'plants';

        document.querySelectorAll('[data-panel]').forEach(p => {
            p.classList.toggle('hidden', p.dataset.panel !== name);
        });

        applyAvailability(name);

        document.querySelectorAll('#harvestTabs [data-tab]').forEach(b => {
            const on = b.dataset.tab === name;
            b.classList.toggle('bg-white', on);
            b.classList.toggle('shadow-sm', on);
            b.classList.toggle('text-gray-500', !on);
            b.style.color = on ? 'var(--color-forest-700)' : '';
        });
    }

    document.querySelectorAll('#harvestTabs [data-tab]').forEach(b => {
        b.addEventListener('click', () => {
            activateTab(b.dataset.tab);
            history.replaceState(null, '', '#' + b.dataset.tab);
        });
    });

    activateTab(location.hash.replace('#', ''));

    // ---- Add Plant Harvest ----
    const PLANT_OPTIONS = @json($plantOptions);

    // The price a sale would start from for the chosen crop and unit, so it can
    // be seen while the harvest is being recorded.
    function updatePriceHint() {
        const hint = document.getElementById('hPriceHint');
        if (!hint) return;

        const input = document.getElementById('hPlantInput');
        const unit = document.getElementById('hUnit').value;
        const product = input ? input.value.trim().toUpperCase() : '';
        const ref = product ? priceHints[product + '|' + unit] : null;
        const word = unit === 'pcs' ? 'pc' : 'kg';

        if (!ref) {
            hint.textContent = product
                ? 'No price reference yet for this crop — set one in Price Reference and sales will start from it.'
                : '';
            return;
        }

        // A national average is dated by year, not by day.
        const on = ref.on
            ? (String(ref.on).match(/^(\d{4})-\d{2}-\d{2}$/) ? ' (' + String(ref.on).slice(0, 4) + ')' : ' (' + ref.on + ')')
            : '';
        hint.textContent = (ref.estimated ? 'Estimated' : 'Reference')
            + ' price ₱' + Number(ref.price).toFixed(2) + ' per ' + word + on
            + ' — the Sales page starts from this.';
    }

    function openHarvestModal() {
        document.getElementById('harvestForm').reset();
        document.getElementById('hDate').value = localToday();
        document.getElementById('hPlantInput').value = '';
        refreshNewPlantBox();
        closePlantList();
        updatePriceHint();
        showModal('harvestModal');
    }

    // Type-ahead over the plants the system already knows about, so a crop that
    // was harvested once (UBE, for example) is offered again next time.
    function filterPlants(showAll) {
        const input = document.getElementById('hPlantInput');
        const list = document.getElementById('hPlantList');
        const hint = document.getElementById('hProductHint');
        if (!input || !list) return;

        const q = input.value.trim().toUpperCase();
        const matches = PLANT_OPTIONS.filter(name => q === '' || name.indexOf(q) >= 0).slice(0, 50);

        if (showAll === true || q !== '') {
            list.innerHTML = matches.length
                ? matches.map(name => '<button type="button" class="block w-full text-left px-3 py-2 text-sm hover:bg-[#F7F3E6]" data-name="' + name + '">' + name + '</button>').join('')
                : '<p class="px-3 py-2 text-xs text-gray-400">No match — tick the box below to add it.</p>';
            list.classList.remove('hidden');
            list.querySelectorAll('button[data-name]').forEach(function (btn) {
                btn.addEventListener('mousedown', function (e) {
                    e.preventDefault();
                    input.value = btn.dataset.name;
                    updatePriceHint();
                    closePlantList();
                });
            });
        }

        hint.textContent = matches.length
            ? 'Pick from the list, or type a name that is not there yet.'
            : '';

        refreshNewPlantBox();
        updatePriceHint();
    }

    // The "new plant" checkbox only appears when the typed name is not listed,
    // so a typo cannot quietly create a second crop.
    function refreshNewPlantBox() {
        const input = document.getElementById('hPlantInput');
        const wrap = document.getElementById('hNewWrap');
        if (!input || !wrap) return;

        const q = input.value.trim().toUpperCase();
        const isNew = q !== '' && PLANT_OPTIONS.indexOf(q) < 0;

        wrap.classList.toggle('hidden', !isNew);
        wrap.classList.toggle('flex', isNew);
        if (!isNew) document.getElementById('hNewPlant').checked = false;
    }

    function closePlantList() {
        const list = document.getElementById('hPlantList');
        if (list) list.classList.add('hidden');
        refreshNewPlantBox();
    }

    document.addEventListener('click', function (e) {
        const input = document.getElementById('hPlantInput');
        const list = document.getElementById('hPlantList');
        if (!input || !list) return;
        if (!e.target.closest('#hPlantList') && !e.target.closest('#hNewWrap') && e.target !== input) {
            list.classList.add('hidden');
        }
    });

    function prepareHarvest() {
        const product = document.getElementById('hPlantInput').value.trim().toUpperCase();

        if (!product) {
            alert('Choose or type what was planted.');
            return false;
        }

        if (PLANT_OPTIONS.indexOf(product) < 0 && !document.getElementById('hNewPlant').checked) {
            alert('"' + product + '" is not in the list yet. Tick "This is a new plant" to add it.');
            return false;
        }

        document.getElementById('hProduct').value = product;
        document.getElementById('hSeedItemId').value = '';
        document.getElementById('hKind').value = 'plant';
        return true;
    }

    // Available for Sale follows the tab: plants on Plants, fish on Fish, and
    // hidden on the disposal tab where stock is not being sold.
    function applyAvailability(name) {
        const section = document.getElementById('availableSection');
        if (!section) return;

        section.classList.toggle('hidden', name === 'disposals');

        const want = name === 'fish' ? 'fish' : 'plant';
        let shown = 0;

        section.querySelectorAll('[data-kind]').forEach(function (card) {
            const on = card.dataset.kind === want;
            card.classList.toggle('hidden', !on);
            if (on) shown++;
        });

        const empty = document.getElementById('availableEmpty');
        if (empty) {
            empty.classList.toggle('hidden', shown > 0);
            empty.textContent = want === 'fish'
                ? 'No fish stock available to sell right now.'
                : 'No plant stock available to sell right now.';
        }
    }

    // ---- Self-consume / DA ----
    function openDisposeModal() {
        const d = document.getElementById('dDate');
        if (d) d.value = localToday();
        showModal('disposeModal');
    }

</script>
@endpush
