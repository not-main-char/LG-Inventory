@extends('layouts.app', ['title' => 'Income Ledger'])
@section('content')

<div class="grid grid-cols-1 md:grid-cols-3 gap-5 mb-6">
    <div class="ledger-card p-6" style="border-left: 5px solid var(--color-water-600)">
        <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500">Current Month Income</h3>
        <div class="mt-2 space-y-1">
            <p class="text-sm">
                <span class="stamp stamp-fish">fish</span> 
                <span class="figure ml-1" style="color:var(--color-water-600)">₱{{ number_format($currentMonthFish, 2) }}</span>
            </p>
            <p class="text-sm">
                <span class="stamp stamp-plant">plant</span> 
                <span class="figure ml-1" style="color:var(--color-soil-600)">₱{{ number_format($currentMonthPlant, 2) }}</span>
            </p>
        </div>
        <p class="text-xs text-gray-400 mt-2">{{ \Carbon\Carbon::now('Asia/Manila')->format('F Y') }}</p>
    </div>

    <div class="ledger-card p-6" style="border-left: 5px solid var(--color-soil-600)">
        <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500">Last Month Income</h3>
        <div class="mt-2 space-y-1">
            <p class="text-sm">
                <span class="stamp stamp-fish">fish</span> 
                <span class="figure ml-1" style="color:var(--color-water-600)">₱{{ number_format($lastMonthFish, 2) }}</span>
            </p>
            <p class="text-sm">
                <span class="stamp stamp-plant">plant</span> 
                <span class="figure ml-1" style="color:var(--color-soil-600)">₱{{ number_format($lastMonthPlant, 2) }}</span>
            </p>
        </div>
        <p class="text-xs text-gray-400 mt-2">{{ \Carbon\Carbon::now('Asia/Manila')->subMonth()->format('F Y') }}</p>
    </div>

    <div class="ledger-card accent-amber p-6">
        <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500">Highest Income Month</h3>
        <div class="mt-2 space-y-1">
            <p class="text-sm">
                <span class="stamp stamp-fish">fish</span> 
                <span class="figure ml-1" style="color:var(--color-water-600)">{{ $highestFishMonth['label'] }} — ₱{{ number_format($highestFishMonth['amount'], 2) }}</span>
            </p>
            <p class="text-sm">
                <span class="stamp stamp-plant">plant</span> 
                <span class="figure ml-1" style="color:var(--color-soil-600)">{{ $highestPlantMonth['label'] }} — ₱{{ number_format($highestPlantMonth['amount'], 2) }}</span>
            </p>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-5 mb-8">
    <div class="ledger-card accent-water p-6">
        <h3 class="font-display text-base font-semibold mb-4" style="color:var(--color-ink-900)">Fish Income Trend</h3>
        <div style="position:relative; height:240px;">
            <canvas id="fishChart"></canvas>
        </div>
        <p id="fishChartEmpty" class="hidden text-sm text-gray-400 text-center py-10">No fish sales recorded yet.</p>
    </div>
    <div class="ledger-card p-6" style="border-left:5px solid var(--color-soil-600)">
        <h3 class="font-display text-base font-semibold mb-4" style="color:var(--color-ink-900)">Plant Income Trend</h3>
        <div style="position:relative; height:240px;">
            <canvas id="plantChart"></canvas>
        </div>
        <p id="plantChartEmpty" class="hidden text-sm text-gray-400 text-center py-10">No plant sales recorded yet.</p>
    </div>
</div>

<div class="flex items-center justify-between mb-4">
    <h3 class="font-display text-base font-semibold" style="color:var(--color-ink-900)">Sales Transactions</h3>
    @if($role === 'admin')
        <div class="flex gap-2">
            <button onclick="openSaleModal()" class="btn-primary px-4 py-2.5 text-sm flex items-center gap-2">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 5v14M5 12h14"/></svg>
                Add Sale
            </button>
        </div>
    @endif
</div>

<div class="ledger-card overflow-hidden">
    <div class="overflow-x-auto">
    <table class="min-w-full ledger-table">
        <thead>
            <tr>
                <th class="p-3 text-left">Crop / Fish</th>
                <th class="p-3 text-left">Quantity Sold</th>
                <th class="p-3 text-left">Unit Sold</th>
                <th class="p-3 text-left">Sale Type</th>
                <th class="p-3 text-left">Total Sale</th>
                <th class="p-3 text-left">Date</th>
                <th class="p-3 text-left">Buyer / Notes</th>
                <th class="p-3 text-left">Actions</th>
            </tr>
        </thead>
        <tbody>
            @foreach($sales as $sale)
            @php $data = $sale->data(); $stampClass = $data['type'] === 'fish' ? 'stamp-fish' : 'stamp-plant'; @endphp
            <tr class="border-b border-[#F1ECDC]">
                <td class="p-3 align-middle">
                    <div class="flex items-center gap-1">
                        <span class="stamp {{ $stampClass }}">{{ $data['type'] }}</span>
                        <span class="text-xs text-gray-500">{{ $data['itemName'] }}</span>
                    </div>
                </td>
                <td class="p-3 align-middle">
                    <span class="figure text-sm">{{ $data['quantitySold'] ?? '—' }}</span>
                </td>
                <td class="p-3 align-middle">
                    @php
                        $saleUnit = strtolower(trim($data['unit'] ?? $data['saleUnit'] ?? ''));
                        $saleUnitLabel = in_array($saleUnit, ['kg', 'kilo', 'kilos', 'kilogram', 'kilograms'], true) ? 'Kilos' : (in_array($saleUnit, ['pcs', 'piece', 'pieces'], true) ? 'Pcs' : ($data['unit'] ?? $data['saleUnit'] ?? 'Not specified'));
                    @endphp
                    <span class="text-sm font-semibold text-slate-700">{{ $saleUnitLabel }}</span>
                </td>
                <td class="p-3 align-middle text-sm">
                    @if(!empty($data['saleType']))
                        <span class="font-semibold text-gray-700">{{ ucfirst($data['saleType']) }}</span>
                        @if(($data['discountPercent'] ?? 0) > 0)
                            <div class="text-[11px] text-gray-400">{{ rtrim(rtrim(number_format((float) $data['discountPercent'], 2, '.', ''), '0'), '.') }}% discount</div>
                        @endif
                    @else
                        <span class="text-gray-400">—</span>
                    @endif
                </td>
                <td class="p-3 align-middle figure text-sm" style="color:var(--color-forest-700)">₱{{ number_format($data['saleAmount'], 2) }}</td>
                <td class="p-3 align-middle text-sm text-gray-600">{{ \Carbon\Carbon::parse($data['date'])->format('M d, Y') }}</td>
                <td class="p-3 align-middle text-sm text-gray-500">{{ $data['notes'] ?? '—' }}</td>
                <td class="p-3 align-middle">
                    @if($role === 'admin')
                        <div class="flex items-center gap-3">
                            <form method="POST" action="{{ route('income.archive', $sale->id()) }}" onsubmit="return confirm('Archive this sale? Its quantity goes back into the harvest stock.')">
                                @csrf
                                <button type="submit" class="text-sm font-medium hover:underline" style="color:var(--color-rust-600)">Archive</button>
                            </form>
                        </div>
                    @else
                        <span class="text-gray-400 italic text-xs">View only</span>
                    @endif
                </td>
            </tr>
            @endforeach
            @if(count($sales) === 0)
            <tr><td colspan="8" class="p-8 text-center text-sm text-gray-400">No sales recorded yet.</td></tr>
            @endif
        </tbody>
    </table>
    </div>
</div>

<div id="saleModal" class="fixed inset-0 modal-backdrop hidden items-center justify-center z-50 p-4 overflow-y-auto">
    <div class="modal-panel p-6 w-full max-w-md my-8">
        <h2 id="saleModalTitle" class="font-display text-xl font-semibold mb-4" style="color:var(--color-ink-900)">Record Sale</h2>

        @if(count($availableStock) === 0)
            <p class="text-sm text-gray-500 mb-4">There is no harvest stock to sell yet. Record a harvest first, then come back here.</p>
            <div class="flex gap-2">
                <a href="{{ route('harvest.index') }}" class="btn-primary w-full py-2.5 text-sm text-center">Go to Harvest</a>
                <button type="button" onclick="closeSaleModal()" class="btn-ghost w-full py-2.5 text-sm">Close</button>
            </div>
        @else
        <form id="saleForm" method="POST" action="{{ route('income.store') }}" class="space-y-3">
            @csrf
            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">Product (from harvest stock)</label>
                <select id="stockKey" name="stockKey" class="input-field" required onchange="onStockChange()">
                    <option value="">-- Choose product --</option>
                    @foreach($availableStock as $s)
                        <option value="{{ $s['key'] }}"
                                data-unit="{{ $s['unit'] }}"
                                data-kind="{{ $s['kind'] }}"
                                data-remaining="{{ $s['remaining'] }}"
                                data-price="{{ $s['price'] ?? '' }}"
                                data-price-on="{{ $s['priceUpdatedOn'] ?? '' }}">
                            {{ $s['product'] }} ({{ rtrim(rtrim(number_format($s['remaining'], 4, '.', ''), '0'), '.') }} {{ $s['unit'] }} available)
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Quantity Sold <span id="qtyUnit" class="font-normal text-gray-400"></span></label>
                    <input type="number" step="any" min="0.01" id="saleQty" name="quantitySold" class="input-field" required oninput="recalcSale()">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Sale Type</label>
                    <select id="saleType" name="saleType" class="input-field" onchange="onSaleTypeChange()">
                        <option value="retail">Retail</option>
                        <option value="wholesale">Wholesale</option>
                    </select>
                </div>
            </div>

            <label id="customerWrap" class="flex items-center gap-2 text-sm text-gray-600">
                <input type="checkbox" id="customerDiscount" onchange="onCustomerChange()">
                Regular or nearby customer (applies the retail customer discount)
            </label>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Price per unit (₱)</label>
                    <input type="number" step="any" min="0" id="unitPrice" name="unitPrice" class="input-field" required oninput="recalcSale()">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Discount (%)</label>
                    <input type="number" step="any" min="0" max="100" id="discountPercent" name="discountPercent" class="input-field" value="0" required oninput="recalcSale()">
                </div>
            </div>
            <p id="priceHint" class="text-[11px] text-gray-400 -mt-1"></p>

            <div class="rounded-xl p-3" style="background:var(--color-moss-100)">
                <p class="text-xs text-gray-500">Price per unit after discount: <span id="finalUnit" class="figure">₱0.00</span></p>
                <p class="text-sm font-semibold mt-0.5" style="color:var(--color-forest-700)">Total sale: <span id="saleTotal" class="figure">₱0.00</span></p>
                <p id="stockWarning" class="hidden text-xs mt-1" style="color:var(--color-rust-600)"></p>
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">Date</label>
                <input type="date" id="saleDate" name="date" class="input-field" required>
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">Buyer name or reason for the discount (optional)</label>
                <textarea name="notes" class="input-field" rows="2" placeholder="e.g. Aling Rosa, regular customer"></textarea>
            </div>
            <div class="pt-2 flex gap-2">
                <button id="saleSubmitButton" type="submit" class="btn-primary w-full py-2.5 text-sm">Save Sale</button>
                <button type="button" onclick="closeSaleModal()" class="btn-ghost w-full py-2.5 text-sm">Cancel</button>
            </div>
        </form>
        @endif
    </div>
</div>
@endsection

@push('scripts')
<script>
    // Default discounts. Staff can still change the number in the form.
    const WHOLESALE_DISCOUNT = 7;
    const RETAIL_CUSTOMER_DISCOUNT = 10;

    function localToday() {
        const d = new Date();
        return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
    }

    function peso(n) {
        return '₱' + Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function selectedStock() {
        const sel = document.getElementById('stockKey');
        return sel && sel.value ? sel.options[sel.selectedIndex] : null;
    }

    function openSaleModal() {
        const form = document.getElementById('saleForm');
        if (form) {
            form.reset();
            document.getElementById('saleDate').value = localToday();
            document.getElementById('customerWrap').classList.remove('hidden');
            document.getElementById('qtyUnit').textContent = '';
            document.getElementById('priceHint').textContent = '';
            recalcSale();
        }
        const m = document.getElementById('saleModal');
        m.classList.remove('hidden'); m.classList.add('flex');
    }

    function onStockChange() {
        const opt = selectedStock();
        const hint = document.getElementById('priceHint');
        document.getElementById('qtyUnit').textContent = opt ? '(' + opt.dataset.unit + ')' : '';
        document.getElementById('saleQty').max = opt ? opt.dataset.remaining : '';

        if (opt && opt.dataset.price !== '') {
            document.getElementById('unitPrice').value = opt.dataset.price;
            let text = 'Reference price ' + peso(opt.dataset.price) + ' per ' + opt.dataset.unit.replace(/s$/, '');
            if (opt.dataset.priceOn) {
                const days = Math.floor((Date.now() - new Date(opt.dataset.priceOn).getTime()) / 86400000);
                text += ', set ' + opt.dataset.priceOn + (days > 14 ? ' (more than 2 weeks old, please check it)' : '');
            }
            hint.textContent = text + '. You can change it.';
        } else {
            document.getElementById('unitPrice').value = '';
            hint.textContent = opt ? 'No reference price yet for this product. Type the price (you can save one under Harvest, Price Reference).' : '';
        }
        recalcSale();
    }

    function onSaleTypeChange() {
        const wholesale = document.getElementById('saleType').value === 'wholesale';
        document.getElementById('customerWrap').classList.toggle('hidden', wholesale);
        document.getElementById('customerDiscount').checked = false;
        document.getElementById('discountPercent').value = wholesale ? WHOLESALE_DISCOUNT : 0;
        recalcSale();
    }

    function onCustomerChange() {
        document.getElementById('discountPercent').value = document.getElementById('customerDiscount').checked ? RETAIL_CUSTOMER_DISCOUNT : 0;
        recalcSale();
    }

    // Same math the server uses: final price = price - discount, total = final price x quantity.
    function recalcSale() {
        const qty = parseFloat(document.getElementById('saleQty')?.value) || 0;
        const price = parseFloat(document.getElementById('unitPrice')?.value) || 0;
        const disc = Math.min(100, Math.max(0, parseFloat(document.getElementById('discountPercent')?.value) || 0));
        const finalUnit = Math.round(price * (1 - disc / 100) * 100) / 100;
        const total = Math.round(finalUnit * qty * 100) / 100;
        if (!document.getElementById('finalUnit')) return;
        document.getElementById('finalUnit').textContent = peso(finalUnit);
        document.getElementById('saleTotal').textContent = peso(total);

        const opt = selectedStock();
        const warn = document.getElementById('stockWarning');
        const over = opt && qty > parseFloat(opt.dataset.remaining);
        warn.classList.toggle('hidden', !over);
        if (over) warn.textContent = 'Only ' + opt.dataset.remaining + ' ' + opt.dataset.unit + ' available.';
    }

    function closeSaleModal() {
        const m = document.getElementById('saleModal');
        m.classList.add('hidden'); m.classList.remove('flex');
    }

    fetch('/income-chart-data')
        .then(res => res.json())
        .then(data => {
            const hasFish = data.fish && data.fish.some(v => v > 0);
            const hasPlant = data.plant && data.plant.some(v => v > 0);

            if (!hasFish) {
                document.getElementById('fishChart').classList.add('hidden');
                document.getElementById('fishChartEmpty').classList.remove('hidden');
            } else {
                new Chart(document.getElementById('fishChart').getContext('2d'), {
                    type: 'bar',
                    data: { labels: data.labels, datasets: [{ label: 'Fish Income (₱)', data: data.fish, backgroundColor: '#29677D', borderRadius: 4 }] },
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: { y: { grid: { color: '#F1ECDC' } }, x: { grid: { display: false } } }
                    }
                });
            }

            if (!hasPlant) {
                document.getElementById('plantChart').classList.add('hidden');
                document.getElementById('plantChartEmpty').classList.remove('hidden');
            } else {
                new Chart(document.getElementById('plantChart').getContext('2d'), {
                    type: 'bar',
                    data: { labels: data.labels, datasets: [{ label: 'Plant Income (₱)', data: data.plant, backgroundColor: '#8C5A33', borderRadius: 4 }] },
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: { y: { grid: { color: '#F1ECDC' } }, x: { grid: { display: false } } }
                    }
                });
            }
        })
        .catch(err => console.error('Income chart failed to load:', err));
</script>
@endpush
