@extends('layouts.app', ['title' => 'Sensor History'])
@section('content')

<div class="flex flex-wrap items-start justify-between gap-4 mb-6">
    <div>
        <span class="stamp stamp-fish mb-2">{{ $devices[$activeDevice] }}</span>
        <h1 class="font-display text-2xl font-semibold" style="color:var(--color-ink-900)">Field Log</h1>
        <p class="text-sm mt-0.5" style="color:var(--color-ink-700)">Environmental readings for <span id="rangeLabel">today</span></p>
    </div>

    <div class="flex flex-col items-end gap-2">
        <div class="flex gap-4" id="deviceTabs">
            @foreach($devices as $id => $label)
                <a href="{{ route('sensor-history.index', $id) }}"
                   class="log-tab {{ $activeDevice === $id ? 'log-tab-active' : '' }}">{{ $label }}</a>
            @endforeach
        </div>
        <div class="flex items-center gap-3">
            <div class="flex gap-4" id="rangeToggle">
                <button data-range="day"   class="range-btn log-tab log-tab-active">Day</button>
                <button data-range="week"  class="range-btn log-tab">Week</button>
                <button data-range="month" class="range-btn log-tab">Month</button>
            </div>
            <input type="date" id="datePicker" class="input-field w-auto text-sm">
        </div>
    </div>
</div>

<!-- Instrument grid -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-6" id="sensorTiles"></div>

<!-- Log entry: the expanded trend for whichever instrument was tapped -->
<div class="ledger-card p-6 hidden" id="detailPanel">
    <div class="flex flex-wrap items-baseline justify-between gap-3 mb-4">
        <h3 class="font-display text-lg" style="color:var(--color-ink-900)">
            <span id="detailTitle"></span><span class="text-sm font-sans" style="color:var(--color-ink-700)" id="detailSubtitle"></span>
        </h3>
        <button id="closeDetail" class="btn-ghost px-3 py-1.5 text-sm rounded-lg">Close entry</button>
    </div>
    <div style="position:relative; height:300px;">
        <canvas id="sensorChart"></canvas>
    </div>
    <p id="sensorChartEmpty" class="hidden text-sm text-gray-400 text-center py-10">No readings logged for this period yet.</p>
</div>

@endsection

@push('scripts')
<style>
    .log-tab {
        font-family: var(--font-display);
        font-size: .85rem;
        color: var(--color-ink-700);
        padding-bottom: 4px;
        border-bottom: 2px solid transparent;
        cursor: pointer;
        background: none;
    }
    .log-tab:hover { color: var(--color-ink-900); }
    .log-tab-active { color: var(--color-forest-700); font-weight: 600; border-bottom-color: var(--color-amber-600); }

    .instrument { cursor: pointer; padding: 1.25rem; transition: box-shadow .15s ease; }
    .instrument:hover { box-shadow: 0 4px 16px rgba(24,42,32,.08); }
    .instrument-active { box-shadow: 0 0 0 2px var(--color-forest-600) inset; }

    .gauge-wrap { position: relative; width: 100%; max-width: 168px; margin: 0 auto; }
    .gauge-needle {
        position: absolute; left: 50%; bottom: 6px; width: 2px; height: 44%;
        background: var(--color-ink-900); transform-origin: bottom center;
        transition: transform .5s cubic-bezier(.4,1.4,.4,1);
    }
    .gauge-needle::after {
        content: ''; position: absolute; bottom: -4px; left: -4px; width: 10px; height: 10px;
        border-radius: 50%; background: var(--color-ink-900);
    }
    .gauge-bounds { display: flex; justify-content: space-between; font-family: var(--font-mono); font-size: .65rem; color: #9C9377; padding: 0 4px; margin-top: -6px; }

    .tank { width: 46px; height: 92px; margin: 0 auto; border: 2px solid var(--color-ink-700); border-radius: 4px; position: relative; overflow: hidden; background: #fff; }
    .tank-fill { position: absolute; left: 0; right: 0; bottom: 0; background: var(--color-water-600); transition: height .5s ease; }

    .status-optimal { color: var(--color-forest-700); background: var(--color-moss-100); }
    .status-watch    { color: var(--color-amber-600); background: var(--color-amber-100); }
    .status-alert     { color: var(--color-rust-600); background: var(--color-rust-100); }
</style>

<script>
(function () {
    const device = @json($activeDevice);
    const sensors = @json($sensors);
    const sensorKeys = Object.keys(sensors);

    let range = 'day';
    let anchorDate = new Date().toISOString().slice(0, 10);
    let chart = null;
    let latestPayload = null;
    let selectedKey = null;

    const tilesEl      = document.getElementById('sensorTiles');
    const rangeLabel    = document.getElementById('rangeLabel');
    const datePicker    = document.getElementById('datePicker');
    const detailPanel   = document.getElementById('detailPanel');
    const detailTitle   = document.getElementById('detailTitle');
    const detailSubtitle = document.getElementById('detailSubtitle');
    const chartEmpty    = document.getElementById('sensorChartEmpty');
    const chartCanvas   = document.getElementById('sensorChart');

    datePicker.value = anchorDate;

    const STATUS_COLOR = { optimal: '#356B45', watch: '#C2862C', alert: '#AE4332' };

    function polar(cx, cy, r, angleDeg) {
        const rad = angleDeg * Math.PI / 180;
        return { x: cx + r * Math.cos(rad), y: cy - r * Math.sin(rad) };
    }
    function arcPath(cx, cy, r, startAngle, endAngle) {
        const s = polar(cx, cy, r, startAngle);
        const e = polar(cx, cy, r, endAngle);
        return `M ${s.x} ${s.y} A ${r} ${r} 0 0 1 ${e.x} ${e.y}`;
    }

    function statusForValue(meta, value) {
        if (!meta.zones || value === null || value === undefined) return null;
        for (const [zMin, zMax, status] of meta.zones) {
            if (value >= zMin && value <= zMax) return status;
        }
        return null;
    }

    // Builds the gauge SVG (band + needle) for one sensor tile.
    function gaugeSVG(meta, value) {
        const cx = 60, cy = 62, r = 48;
        let bands = '';
        (meta.zones || []).forEach(([zMin, zMax, status]) => {
            const f0 = (zMin - meta.min) / (meta.max - meta.min);
            const f1 = (zMax - meta.min) / (meta.max - meta.min);
            const a0 = 180 - 180 * f0, a1 = 180 - 180 * f1;
            bands += `<path d="${arcPath(cx, cy, r, a0, a1)}" fill="none" stroke="${STATUS_COLOR[status]}" stroke-width="9" opacity="0.85"/>`;
        });
        return `<svg viewBox="0 0 120 68" class="w-full">${bands}</svg>`;
    }

    function needleRotation(meta, value) {
        if (value === null || value === undefined) return -90;
        const f = Math.min(1, Math.max(0, (value - meta.min) / (meta.max - meta.min)));
        return 180 * f - 90;
    }

    // Build tiles once.
    sensorKeys.forEach(key => {
        const meta = sensors[key];
        const tile = document.createElement('button');
        tile.type = 'button';
        tile.className = 'ledger-card instrument text-left';
        tile.dataset.key = key;

        if (meta.kind === 'level') {
            tile.innerHTML = `
                <p class="text-sm mb-2" style="color:var(--color-ink-700)">${meta.label}</p>
                <div class="tank"><div class="tank-fill" data-field="fill" style="height:0%"></div></div>
                <p class="figure text-lg mt-3 text-center" data-field="avg" style="color:var(--color-ink-900)">—</p>
                <p class="text-xs text-center mt-1" style="color:var(--color-ink-700)" data-field="range"></p>
            `;
        } else {
            tile.innerHTML = `
                <p class="text-sm mb-1" style="color:var(--color-ink-700)">${meta.label}</p>
                <div class="gauge-wrap">
                    ${gaugeSVG(meta, null)}
                    <div class="gauge-needle" data-field="needle" style="transform: rotate(-90deg)"></div>
                </div>
                <div class="gauge-bounds"><span>${meta.min}</span><span>${meta.max}</span></div>
                <p class="figure text-lg mt-1 text-center" data-field="avg" style="color:var(--color-ink-900)">—</p>
                <p class="text-xs text-center mt-1" style="color:var(--color-ink-700)" data-field="range"></p>
                <p class="stamp mt-2 mx-auto w-fit hidden" data-field="status"></p>
            `;
        }

        tile.addEventListener('click', () => selectSensor(key));
        tilesEl.appendChild(tile);
    });

    document.querySelectorAll('.range-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            range = btn.dataset.range;
            document.querySelectorAll('.range-btn').forEach(b => b.classList.remove('log-tab-active'));
            btn.classList.add('log-tab-active');
            load();
        });
    });

    datePicker.addEventListener('change', () => { anchorDate = datePicker.value; load(); });

    document.getElementById('closeDetail').addEventListener('click', () => {
        selectedKey = null;
        detailPanel.classList.add('hidden');
        document.querySelectorAll('.instrument').forEach(t => t.classList.remove('instrument-active'));
    });

    function load() {
        const params = new URLSearchParams({ device, range, date: anchorDate });
        fetch('/sensor-history-data?' + params.toString())
            .then(res => res.json())
            .then(data => {
                latestPayload = data;
                rangeLabel.textContent = data.range_label;
                renderTiles();
                if (selectedKey) renderChart();
            })
            .catch(err => console.error('Sensor history failed to load:', err));
    }

    function renderTiles() {
        if (!latestPayload) return;
        sensorKeys.forEach(key => {
            const meta = sensors[key];
            const stats = latestPayload.overall[key] || {};
            const unit = meta.unit ? ' ' + meta.unit : '';
            const tile = tilesEl.querySelector(`[data-key="${key}"]`);
            const hasVal = stats.avg !== null && stats.avg !== undefined;

            tile.querySelector('[data-field="avg"]').textContent = hasVal ? stats.avg + unit : 'No reading';
            const rangeEl = tile.querySelector('[data-field="range"]');
            rangeEl.textContent = hasVal ? `Ranged ${stats.low}\u2013${stats.high}${unit}` : '';

            if (meta.kind === 'level') {
                const pct = hasVal ? Math.min(100, Math.max(0, ((stats.avg - meta.min) / (meta.max - meta.min)) * 100)) : 0;
                tile.querySelector('[data-field="fill"]').style.height = pct + '%';
            } else {
                tile.querySelector('[data-field="needle"]').style.transform = `rotate(${needleRotation(meta, hasVal ? stats.avg : null)}deg)`;
                const statusEl = tile.querySelector('[data-field="status"]');
                const status = hasVal ? statusForValue(meta, stats.avg) : null;
                if (status) {
                    statusEl.textContent = status.charAt(0).toUpperCase() + status.slice(1);
                    statusEl.className = 'stamp mt-2 mx-auto w-fit status-' + status;
                } else {
                    statusEl.classList.add('hidden');
                }
            }
        });
    }

    function selectSensor(key) {
        selectedKey = key;
        document.querySelectorAll('.instrument').forEach(t => t.classList.remove('instrument-active'));
        tilesEl.querySelector(`[data-key="${key}"]`).classList.add('instrument-active');
        detailPanel.classList.remove('hidden');
        detailTitle.textContent = sensors[key].label;
        detailSubtitle.textContent = latestPayload ? ' \u2014 ' + latestPayload.range_label : '';
        renderChart();
        detailPanel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function renderChart() {
        if (!latestPayload || !selectedKey) return;
        const series = latestPayload.series[selectedKey] || { avg: [], low: [], high: [] };
        const hasData = latestPayload.count > 0;

        if (chart) { chart.destroy(); chart = null; }

        if (!hasData) {
            chartCanvas.classList.add('hidden');
            chartEmpty.classList.remove('hidden');
            return;
        }
        chartCanvas.classList.remove('hidden');
        chartEmpty.classList.add('hidden');

        chart = new Chart(chartCanvas.getContext('2d'), {
            type: 'line',
            data: {
                labels: latestPayload.labels,
                datasets: [
                    { label: '__high_boundary', data: series.high, borderColor: 'transparent', pointRadius: 0, fill: false, spanGaps: true },
                    { label: 'Low\u2013High range', data: series.low, borderColor: 'transparent', backgroundColor: 'rgba(41,103,125,0.15)', pointRadius: 0, fill: '-1', spanGaps: true },
                    { label: 'Average', data: series.avg, borderColor: '#29677D', backgroundColor: '#29677D', pointRadius: 3, tension: 0.3, fill: false, spanGaps: true },
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: true, position: 'bottom', labels: { filter: item => item.text !== '__high_boundary' } } },
                scales: {
                    y: { grid: { color: '#F1ECDC' } },
                    x: { grid: { display: false }, ticks: { maxTicksLimit: 12, autoSkip: true } }
                }
            }
        });
    }

    load();
})();
</script>
@endpush
