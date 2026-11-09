@extends('layouts.app', ['title' => 'Harvest'])

@section('content')
@include('inventory._tabs', ['active' => 'harvest'])

@php
    $unitLabel = fn ($u) => $u === 'pcs' ? 'pcs' : 'kilos';
    $num = fn ($n) => rtrim(rtrim(number_format((float) $n, 4, '.', ''), '0'), '.');
    $sourceLabel = ['hydroponics' => 'Hydroponics', 'aquaculture' => 'Aquaculture', 'landfarming' => 'Landfarming'];
@endphp

{{-- Available stock: what Sales can sell right now --}}
<div class="flex items-center justify-between mb-3">
    <h3 class="font-display text-base font-semibold" style="color:var(--color-ink-900)">Available for Sale</h3>
    @if($role === 'admin')
        <div class="flex gap-2">
            <button onclick="openHarvestModal()" class="btn-primary px-4 py-2.5 text-sm flex items-center gap-2">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 5v14M5 12h14"/></svg>
                Add Harvest
            </button>
            <button onclick="openDisposeModal()" class="btn-ghost px-4 py-2.5 text-sm">Self-consume / DA</button>
        </div>
    @endif
</div>

<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
    @forelse($available as $a)
        <div class="ledger-card p-4">
            <span class="stamp {{ $a['kind'] === 'fish' ? 'stamp-fish' : 'stamp-plant' }}">{{ $a['kind'] }}</span>
            <p class="text-sm font-medium text-gray-800 mt-2">{{ $a['product'] }}</p>
            <p class="figure text-xl mt-1" style="color:var(--color-forest-700)">{{ $num($a['remaining']) }} <span class="text-sm font-normal text-gray-500">{{ $unitLabel($a['unit']) }}</span></p>
            <p class="text-xs text-gray-400 mt-1">
                @if($a['price'] !== null) Reference price ₱{{ number_format($a['price'], 2) }} @else No reference price yet @endif
            </p>
        </div>
    @empty
        <p class="text-sm text-gray-400 col-span-full">Nothing is available to sell yet. Add a harvest to start.</p>
    @endforelse
</div>

{{-- Harvest records --}}
<h3 class="font-display text-base font-semibold mb-3" style="color:var(--color-ink-900)">Harvest Records</h3>
<div class="ledger-card overflow-hidden mb-8">
    <div class="overflow-x-auto">
    <table class="min-w-full ledger-table">
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
            <tr class="border-b border-[#F1ECDC]">
                <td class="p-3 align-middle text-sm text-gray-600">{{ \Carbon\Carbon::parse($h['harvestDate'])->format('M d, Y') }}</td>
                <td class="p-3 align-middle">
                    <div class="flex items-center gap-2">
                        <span class="stamp {{ $h['kind'] === 'fish' ? 'stamp-fish' : 'stamp-plant' }}">{{ $h['kind'] }}</span>
                        <span class="text-sm font-medium text-gray-800">{{ $h['product'] }}</span>
                    </div>
                </td>
                <td class="p-3 align-middle text-sm text-gray-600">{{ $sourceLabel[$h['source']] ?? $h['source'] }}</td>
                <td class="p-3 align-middle figure text-sm">{{ $num($h['quantity']) }} {{ $unitLabel($h['unit']) }}</td>
                <td class="p-3 align-middle figure text-sm" style="color: {{ $h['remaining'] > 0.00001 ? 'var(--color-forest-700)' : '#9C9377' }}">{{ $num($h['remaining']) }} {{ $unitLabel($h['unit']) }}</td>
                <td class="p-3 align-middle text-sm text-gray-500">{{ $h['notes'] !== '' ? $h['notes'] : '—' }}</td>
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
            <tr><td colspan="7" class="p-8 text-center text-sm text-gray-400">No harvests recorded yet.</td></tr>
            @endif
        </tbody>
    </table>
    </div>
</div>

{{-- Self-consumed / given to the DA --}}
<h3 class="font-display text-base font-semibold mb-3" style="color:var(--color-ink-900)">Self-consumed and Given to the DA</h3>
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
                <td class="p-3 align-middle text-sm text-gray-500">{{ $d['note'] !== '' ? $d['note'] : '—' }}</td>
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

{{-- Price reference --}}
<div class="flex items-center justify-between mb-3">
    <h3 class="font-display text-base font-semibold" style="color:var(--color-ink-900)">Price Reference</h3>
    @if($role === 'admin')
        <button onclick="openPriceModal()" class="btn-ghost px-4 py-2.5 text-sm">Set a Price</button>
    @endif
</div>
<p class="text-xs text-gray-500 mb-3">Retail price per unit. It pre-fills the price when you record a sale. Update it about once a week, for example from the DA's Bantay Presyo or what local buyers pay.</p>
<div class="ledger-card overflow-hidden mb-8">
    <div class="overflow-x-auto">
    <table class="min-w-full ledger-table">
        <thead>
            <tr>
                <th class="p-3 text-left">Product</th>
                <th class="p-3 text-left">Unit</th>
                <th class="p-3 text-left">Retail Price</th>
                <th class="p-3 text-left">Last Updated</th>
                <th class="p-3 text-left">Source / Note</th>
                <th class="p-3 text-left">Actions</th>
            </tr>
        </thead>
        <tbody>
            @foreach($prices as $p)
            @php $stale = $p['updatedOn'] !== '' && \Carbon\Carbon::parse($p['updatedOn'])->diffInDays(now('Asia/Manila')) > 14; @endphp
            <tr class="border-b border-[#F1ECDC]">
                <td class="p-3 align-middle text-sm font-medium text-gray-800">{{ $p['product'] }}</td>
                <td class="p-3 align-middle text-sm text-gray-600">{{ $unitLabel($p['unit']) }}</td>
                <td class="p-3 align-middle figure text-sm" style="color:var(--color-forest-700)">₱{{ number_format($p['retailPrice'], 2) }}</td>
                <td class="p-3 align-middle text-sm {{ $stale ? '' : 'text-gray-600' }}" style="{{ $stale ? 'color:var(--color-rust-600)' : '' }}">
                    {{ $p['updatedOn'] !== '' ? \Carbon\Carbon::parse($p['updatedOn'])->format('M d, Y') : '—' }}
                    @if($stale)<div class="text-[11px]">More than 2 weeks old</div>@endif
                </td>
                <td class="p-3 align-middle text-sm text-gray-500">{{ $p['note'] !== '' ? $p['note'] : '—' }}</td>
                <td class="p-3 align-middle">
                    @if($role === 'admin')
                        <button type="button" onclick='openPriceModal(@json($p))' class="text-sm font-medium hover:underline" style="color:var(--color-forest-700)">Edit</button>
                    @else
                        <span class="text-gray-400 italic text-xs">View only</span>
                    @endif
                </td>
            </tr>
            @endforeach
            @if(count($prices) === 0)
            <tr><td colspan="6" class="p-8 text-center text-sm text-gray-400">No reference prices yet.</td></tr>
            @endif
        </tbody>
    </table>
    </div>
</div>

{{-- Add Harvest modal --}}
<div id="harvestModal" class="fixed inset-0 modal-backdrop hidden items-center justify-center z-50 p-4 overflow-y-auto">
    <div class="modal-panel p-6 w-full max-w-md my-8">
        <h2 class="font-display text-xl font-semibold mb-4" style="color:var(--color-ink-900)">Add Harvest</h2>
        <form id="harvestForm" method="POST" action="{{ route('harvest.store') }}" class="space-y-3" onsubmit="return prepareHarvest()">
            @csrf
            <input type="hidden" name="product" id="hProduct">
            <input type="hidden" name="seedItemId" id="hSeedItemId">
            <input type="hidden" name="kind" id="hKind">

            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">Source</label>
                <select id="hSource" name="source" class="input-field" required onchange="onHarvestSourceChange()">
                    <option value="hydroponics">Hydroponics</option>
                    <option value="aquaculture">Aquaculture (fish pond)</option>
                    <option value="landfarming">Landfarming</option>
                </select>
            </div>

            <div id="hKindWrap">
                <label class="block text-xs font-semibold text-gray-600 mb-1">What was harvested from the hydroponics system?</label>
                <select id="hKindSelect" class="input-field" onchange="renderHarvestProduct()">
                    <option value="plant">Plant</option>
                    <option value="fish">Fish</option>
                </select>
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">Product</label>
                <select id="hProductSelect" class="input-field" onchange="renderHarvestProduct()"></select>
                <input type="text" id="hProductText" class="input-field uppercase mt-1.5" placeholder="Type the name, e.g. TILAPIA" oninput="this.value = this.value.toUpperCase()">
                <p id="hProductHint" class="text-[11px] text-gray-400 mt-1"></p>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Quantity</label>
                    <input type="number" step="any" min="0.01" name="quantity" class="input-field" required>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Unit</label>
                    <select name="unit" class="input-field" required>
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

{{-- Price modal --}}
<div id="priceModal" class="fixed inset-0 modal-backdrop hidden items-center justify-center z-50 p-4 overflow-y-auto">
    <div class="modal-panel p-6 w-full max-w-md my-8">
        <h2 class="font-display text-xl font-semibold mb-4" style="color:var(--color-ink-900)">Reference Price</h2>
        <form id="priceForm" method="POST" action="{{ route('harvest.price') }}" class="space-y-3">
            @csrf
            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">Product</label>
                <input type="text" name="product" id="pProduct" list="productList" class="input-field uppercase" required oninput="this.value = this.value.toUpperCase()">
                <datalist id="productList">
                    @foreach(collect($available)->pluck('product')->merge(collect($prices)->pluck('product'))->unique() as $name)
                        <option value="{{ $name }}"></option>
                    @endforeach
                </datalist>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Unit</label>
                    <select name="unit" id="pUnit" class="input-field" required>
                        <option value="kilos">Kilos</option>
                        <option value="pcs">Pcs</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Retail price per unit (₱)</label>
                    <input type="number" step="any" min="0" name="retailPrice" id="pPrice" class="input-field" required>
                </div>
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">Source / note (optional)</label>
                <input type="text" name="note" id="pNote" class="input-field" maxlength="255" placeholder="e.g. DA Bantay Presyo, week of Nov 3">
            </div>
            <div class="pt-2 flex gap-2">
                <button type="submit" class="btn-primary w-full py-2.5 text-sm">Save Price</button>
                <button type="button" onclick="closeModal('priceModal')" class="btn-ghost w-full py-2.5 text-sm">Cancel</button>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
    const seedItems = @json($seedItems);

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

    // ---- Add Harvest ----
    function currentKind() {
        const source = document.getElementById('hSource').value;
        if (source === 'aquaculture') return 'fish';
        if (source === 'landfarming') return 'plant';
        return document.getElementById('hKindSelect').value;
    }

    function openHarvestModal() {
        document.getElementById('harvestForm').reset();
        document.getElementById('hDate').value = localToday();
        onHarvestSourceChange();
        showModal('harvestModal');
    }

    function onHarvestSourceChange() {
        document.getElementById('hKindWrap').classList.toggle('hidden', document.getElementById('hSource').value !== 'hydroponics');
        renderHarvestProduct();
    }

    // Plants are picked from the Seed items in Inventory (or typed under "Other");
    // fish are always typed because no inventory item represents the fish itself.
    function renderHarvestProduct() {
        const kind = currentKind();
        const select = document.getElementById('hProductSelect');
        const text = document.getElementById('hProductText');
        const hint = document.getElementById('hProductHint');

        if (kind === 'plant') {
            const previous = select.value;
            select.innerHTML = seedItems.map(s => `<option value="${s.id}">${s.name}</option>`).join('')
                + '<option value="__other">Other (type it)</option>';
            if (previous && [...select.options].some(o => o.value === previous)) select.value = previous;
            select.classList.remove('hidden');
            text.classList.toggle('hidden', select.value !== '__other');
            hint.textContent = seedItems.length
                ? 'These come from the Seed items in Inventory.'
                : 'No Seed items in Inventory yet, so type the name under Other.';
        } else {
            select.classList.add('hidden');
            text.classList.remove('hidden');
            hint.textContent = 'Type the fish name, for example TILAPIA.';
        }
    }

    function prepareHarvest() {
        const kind = currentKind();
        const select = document.getElementById('hProductSelect');
        const text = document.getElementById('hProductText');
        let product = '', seedId = '';

        if (kind === 'plant' && select.value && select.value !== '__other') {
            const item = seedItems.find(s => s.id === select.value);
            product = item ? item.name : '';
            seedId = select.value;
        } else {
            product = text.value.trim().toUpperCase();
        }

        if (!product) {
            alert('Please choose or type what was harvested.');
            return false;
        }
        document.getElementById('hProduct').value = product;
        document.getElementById('hSeedItemId').value = seedId;
        document.getElementById('hKind').value = kind;
        return true;
    }

    // ---- Self-consume / DA ----
    function openDisposeModal() {
        const d = document.getElementById('dDate');
        if (d) d.value = localToday();
        showModal('disposeModal');
    }

    // ---- Price reference ----
    function openPriceModal(p) {
        document.getElementById('priceForm').reset();
        if (p) {
            document.getElementById('pProduct').value = p.product;
            document.getElementById('pUnit').value = p.unit;
            document.getElementById('pPrice').value = p.retailPrice;
            document.getElementById('pNote').value = p.note || '';
        }
        showModal('priceModal');
    }
</script>
@endpush
