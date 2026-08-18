@extends('layouts.app', ['title' => 'Dashboard'])
@section('content')

<div class="grid grid-cols-1 md:grid-cols-3 gap-5 mb-8">
    <div class="ledger-card accent-green p-5">
        <div class="flex items-center justify-between">
            <div>
                <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500">Current Month</h3>
                <p class="figure text-3xl mt-2" style="color:var(--color-forest-700)">₱{{ number_format($currentMonthIncome, 2) }}</p>
                <p class="text-xs text-gray-400 mt-1">{{ $currentMonthLabel }}</p>
            </div>
            <svg class="w-8 h-8 opacity-20" viewBox="0 0 24 24" fill="none" stroke="var(--color-forest-600)" stroke-width="1.8"><path d="M8 7V5a2 2 0 012-2h4a2 2 0 012 2v2M8 7H6a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V9a2 2 0 00-2-2h-2"/></svg>
        </div>
    </div>

    <div class="ledger-card p-5" style="border-left:5px solid var(--color-soil-600)">
        <div class="flex items-center justify-between">
            <div>
                <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500">Last Month</h3>
                <p class="figure text-3xl mt-2" style="color:var(--color-soil-700)">₱{{ number_format($lastMonthIncome, 2) }}</p>
                <p class="text-xs text-gray-400 mt-1">{{ $lastMonthLabel }}</p>
            </div>
            <svg class="w-8 h-8 opacity-20" viewBox="0 0 24 24" fill="none" stroke="var(--color-soil-600)" stroke-width="1.8"><path d="M9 11l3 3L22 4M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
    </div>

    <div class="alert-card {{ $lowStockCount > 0 ? 'alert-card-active' : 'alert-card-clear' }} p-5 flex items-center justify-between"
        style="{{ $lowStockCount > 0 ? '' : 'background: var(--color-amber-200); border-left: 5px solid var(--color-amber-600);' }}">
        <div>
            <h3 class="text-xs font-semibold uppercase tracking-wider {{ $lowStockCount > 0 ? 'text-white/80' : 'text-amber-600' }}">Low Stock Alerts</h3>
            <p class="figure text-3xl mt-2" style="color: {{ $lowStockCount > 0 ? '#fff' : 'var(--color-amber-600)' }}">{{ $lowStockCount }}</p>
        </div>
        <svg class="w-9 h-9" viewBox="0 0 24 24" fill="none" stroke="{{ $lowStockCount > 0 ? '#fff' : 'var(--color-amber-600)' }}" stroke-width="1.8"><path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.3 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.7 3.86a2 2 0 00-3.4 0z"/></svg>
    </div>
</div>

<div class="ledger-card p-6 mb-8">
    <div class="flex items-center justify-between mb-6">
        <h3 class="font-display text-base font-semibold" style="color:var(--color-ink-900)">Income Trend</h3>
        <div class="flex gap-2">
            <select id="yearSelector" class="input-field text-sm" style="width: auto; padding: 0.5rem 0.75rem;" onchange="loadChartData()">
                    @php
                    $currentYear = date('Y');
                    $startYear = $currentYear;
                    $endYear = $currentYear;
                @endphp
                @for ($year = $startYear; $year <= $endYear; $year++)
                    <option value="{{ $year }}" {{ $year == $currentYear ? 'selected' : '' }}>{{ $year }}</option>
                @endfor
            </select>
        </div>
    </div>

    <div class="flex gap-2 mb-4">
        <button onclick="filterChart('all')" class="btn-ghost px-3 py-1.5 text-xs font-medium" id="btnAll" style="background: var(--color-water-100); color: var(--color-water-700); border: 1px solid var(--color-water-300)">All</button>
        <button onclick="filterChart('fish')" class="btn-ghost px-3 py-1.5 text-xs font-medium" id="btnFish" style="color: var(--color-water-600)">Fish Only</button>
        <button onclick="filterChart('plant')" class="btn-ghost px-3 py-1.5 text-sm font-medium" id="btnPlant" style="color: var(--color-soil-600)">Plant Only</button>
    </div>

    <div style="position:relative; height:350px;">
        <canvas id="incomeChart"></canvas>
    </div>
    <p id="chartEmpty" class="hidden text-sm text-gray-400 text-center py-10">No sales data for this year.</p>
</div>

@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    let chartInstance = null;
    let allChartData = {};
    let currentFilter = 'all';
    let currentYear = '2026';

    async function loadChartData( ) {
        try {
            const year = document.getElementById('yearSelector').value;
            const response = await fetch(`/income-chart-data?year=${year}`);
            const data = await response.json();
            allChartData = data;
            updateChart();
        } catch (err) {
            console.error('Failed to load chart data:', err);
        }
    }

    function filterChart(filter) {
        currentFilter = filter;
        
        // Update button styles
        document.getElementById('btnAll').style.background = filter === 'all' ? 'var(--color-water-100)' : 'transparent';
        document.getElementById('btnFish').style.background = filter === 'fish' ? 'var(--color-water-100)' : 'transparent';
        document.getElementById('btnPlant').style.background = filter === 'plant' ? 'var(--color-soil-100)' : 'transparent';
        
        updateChart();
    }

    function updateChart() {
        const canvas = document.getElementById('incomeChart');
        const emptyMsg = document.getElementById('chartEmpty');
        
        if (!allChartData || !allChartData.labels) {
            emptyMsg.classList.remove('hidden');
            canvas.classList.add('hidden');
            return;
        }

        const hasData = (allChartData.fish && allChartData.fish.some(v => v !== null && v > 0))||
                        (allChartData.plant && allChartData.plant.some(v => v !== null && v > 0));

        if (!hasData) {
            emptyMsg.classlist.remove('hidden');
            canvas.classList.add('hidden');
            return;
        }

        emptyMsg.classList.add('hidden');
        canvas.classList.remove('hidden');

        const datasets = [];
        
        if (currentFilter === 'all' || currentFilter === 'fish') {
            datasets.push({
                label: 'Fish Income (₱)',
                data: allChartData.fish || [],
                borderColor: '#29677D',
                backgroundColor: 'rgba(41, 103, 125, 0.1)',
                borderWidth: 2,
                tension: 0.4,
                fill: true,
                pointBackgroundColor: '#29677D',
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
                pointRadius: 4
            });
        }

        if (currentFilter === 'all' || currentFilter === 'plant') {
            datasets.push({
                label: 'Plant Income (₱)',
                data: allChartData.plant || [],
                borderColor: '#8C5A33',
                backgroundColor: 'rgba(140, 90, 51, 0.1)',
                borderWidth: 2,
                tension: 0.4,
                fill: true,
                pointBackgroundColor: '#8C5A33',
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
                pointRadius: 4
            });
        }

        if (chartInstance) {
            chartInstance.destroy();
        }

        chartInstance = new Chart(canvas.getContext('2d'), {
            type: 'line',
            data: {
                labels: allChartData.labels || [],
                datasets: datasets
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true,
                        position: 'top',
                        labels: {
                            usePointStyle: true,
                            padding: 15,
                            font: { size: 12 }
                        }
                    },
                    filler: {
                        propagate: true
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: '#F1ECDC' },
                        ticks: { callback: function(value) { return '₱' + value.toLocaleString(); } }
                    },
                    x: {
                        grid: { display: false }
                    }
                }
            }
        });
    }

    loadChartData();
</script>
@endpush
