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
                <th class="p-3 text-left">Quantity</th>
                <th class="p-3 text-left">Total Sale</th>
                <th class="p-3 text-left">Date</th>
                <th class="p-3 text-left">Notes</th>
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
                    <div class="flex items-center gap-1">
                        <span class="figure text-sm">{{ $data['quantitySold'] }}</span>
                        <span class="text-xs text-gray-500">{{ $data['unit'] ?? '—' }}</span>
                    </div>
                </td>
                <td class="p-3 align-middle figure text-sm" style="color:var(--color-forest-700)">₱{{ number_format($data['saleAmount'], 2) }}</td>
                <td class="p-3 align-middle text-sm text-gray-600">{{ \Carbon\Carbon::parse($data['date'])->format('M d, Y') }}</td>
                <td class="p-3 align-middle text-sm text-gray-500">{{ $data['notes'] ?? '—' }}</td>
                <td class="p-3 align-middle">
                    @if($role === 'admin')
                        <form method="POST" action="{{ route('income.archive', $sale->id()) }}" onsubmit="return confirm('Archive this sale record?')">
                            @csrf
                            <button type="submit" class="text-sm font-medium hover:underline" style="color:var(--color-rust-600)">Archive</button>
                        </form>
                    @else
                        <span class="text-gray-400 italic text-xs">View only</span>
                    @endif
                </td>
            </tr>
            @endforeach
            @if(count($sales) === 0)
            <tr><td colspan="6" class="p-8 text-center text-sm text-gray-400">No sales recorded yet.</td></tr>
            @endif
        </tbody>
    </table>
    </div>
</div>

<div id="saleModal" class="fixed inset-0 modal-backdrop hidden items-center justify-center z-50 p-4">
    <div class="modal-panel p-6 w-full max-w-md">
        <h2 class="font-display text-xl font-semibold mb-4" style="color:var(--color-ink-900)">Record Sale</h2>
        <form method="POST" action="{{ route('income.store') }}" class="space-y-3">
            @csrf
            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">Item Name (crop or fish)</label>
                <input type="text" name="itemName" class="input-field" required>
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">Type</label>
                <select name="type" class="input-field">
                    <option value="fish">Fish</option>
                    <option value="plant">Plant</option>
                </select>
            </div>
                        <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Quantity Sold</label>
                    <input type="number" step="any" name="quantitySold" class="input-field" required>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Unit</label>
                    <select name="unit" class="input-field" required>
                        <option value="">-- Select Unit --</option>
                        <option value="kilos">Kilos</option>
                        <option value="pcs">Pcs</option>
                    </select>
                </div>
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">Sale Amount (₱)</label>
                <input type="number" step="any" name="saleAmount" class="input-field" required>
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">Date</label>
                <input type="date" name="date" class="input-field" required>
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">Notes</label>
                <textarea name="notes" class="input-field" rows="2"></textarea>
            </div>
            <div class="pt-2 flex gap-2">
                <button type="submit" class="btn-primary w-full py-2.5 text-sm">Save Sale</button>
                <button type="button" onclick="closeSaleModal()" class="btn-ghost w-full py-2.5 text-sm">Cancel</button>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
    function openSaleModal() {
        const m = document.getElementById('saleModal');
        m.classList.remove('hidden'); m.classList.add('flex');
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
