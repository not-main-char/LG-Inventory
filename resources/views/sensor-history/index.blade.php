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
                <button data-range="hour"  class="range-btn log-tab">Hour</button>
                <button data-range="day"   class="range-btn log-tab log-tab-active">Day</button>
                <button data-range="week"  class="range-btn log-tab">Week</button>
                <button data-range="month" class="range-btn log-tab">Month</button>
            </div>
            <input type="date" id="datePicker" class="input-field w-auto text-sm">
        </div>
    </div>
</div>

<!-- Hour picker: only shown on the Hour tab -->
<div id="hourBar" class="hidden mb-6">
    <p class="text-sm mb-2" style="color:var(--color-ink-700)">Pick an hour to look at. The small number under each hour is how many readings were logged in it.</p>
    <div id="hourChips" class="flex flex-wrap gap-2"></div>
</div>

<!-- Instrument grid -->
<div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4 mb-6" id="sensorTiles"></div>

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
    <p class="text-xs mt-4 leading-relaxed" style="color:var(--color-ink-700)">
        <strong>How to read this:</strong> the solid line is the <em>average</em> of the readings in each time slot.
        The light shaded band stretches from the <em>lowest</em> to the <em>highest</em> reading in that slot, so a thin band means steady readings
        and a wide band means the values jumped around. If a slot has only one reading, the band collapses into the line.
        A dot only appears where a reading exists. Hover a time slot to see the exact values, and <strong>click a dot to zoom in</strong> (a Week or Month dot opens that day, a Day dot opens that hour). On the <strong>Hour</strong> tab every dot is one actual reading, with nothing averaged. Dashed red lines mark the normal range.
    </p>
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
    .hour-chip { border: 1px solid #DCD3B6; background: #fff; border-radius: .6rem; padding: .35rem .6rem; text-align: center; min-width: 3.6rem; line-height: 1.15; font-size: .8rem; color: var(--color-ink-900); cursor: pointer; }
    .hour-chip small { display: block; font-family: var(--font-mono); font-size: .65rem; color: #9C9377; }
    .hour-chip:hover:not(:disabled) { border-color: var(--color-forest-600); }
    .hour-chip:disabled { opacity: .4; cursor: default; }
    .hour-chip-active { background: var(--color-forest-700); border-color: var(--color-forest-700); color: #fff; }
    .hour-chip-active small { color: #E4ECD9; }
    .tank-mark { position: absolute; left: 0; right: 0; border-top: 2px dashed var(--color-ink-900); opacity: .55; z-index: 2; }
    .tank-fill { position: absolute; left: 0; right: 0; bottom: 0; background: var(--color-water-600); transition: height .5s ease; }

    .status-optimal { color: var(--color-forest-700); background: var(--color-moss-100); }
    .status-watch    { color: var(--color-amber-600); background: var(--color-amber-100); }
    .status-alert,
    .status-high,
    .status-low       { color: var(--color-rust-600); background: var(--color-rust-100); }
</style>

<script>
(function () {
    const device = @json($activeDevice);
    const sensors = @json($sensors);
    const sensorKeys = Object.keys(sensors);

    let range = 'day';
    // Local calendar date (toISOString alone gives the UTC date, which is
    // "yesterday" in the Philippines before 8 AM).
    function localToday() {
        const d = new Date();
        return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
    }
    let anchorDate = localToday();
    let hour = new Date().getHours();
    let hourCounts = Array(24).fill(0);
    let chart = null;
    let latestPayload = null;
    let selectedKey = null;

    const tilesEl      = document.getElementById('sensorTiles');
    const rangeLabel    = document.getElementById('rangeLabel');
    const datePicker    = document.getElementById('datePicker');
    const hourBar       = document.getElementById('hourBar');
    const hourChips     = document.getElementById('hourChips');
    const detailPanel   = document.getElementById('detailPanel');
    const detailTitle   = document.getElementById('detailTitle');
    const detailSubtitle = document.getElementById('detailSubtitle');
    const chartEmpty    = document.getElementById('sensorChartEmpty');
    const chartCanvas   = document.getElementById('sensorChart');

    datePicker.value = anchorDate;

    const STATUS_COLOR = { optimal: '#356B45', watch: '#C2862C', alert: '#AE4332' };
        const STATUS_LABEL = { optimal: 'Normal', watch: 'Watch', alert: 'Out of range', high: 'Too high', low: 'Too low' };

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
        if (!meta.normal || value === null || value === undefined) return null;
        const [lo, hi] = meta.normal;
        if (lo !== null && value < lo) return 'low';
        if (hi !== null && value > hi) return 'high';
        return 'optimal';
    }

    function normalText(meta) {
        if (!meta.normal) return '';
        const [lo, hi] = meta.normal;
        const unit = meta.unit ? ' ' + meta.unit : '';
        if (lo === null || lo <= meta.min) return `Normal: up to ${hi}${unit}`;
        if (hi === null) return `Normal: ${lo}${unit} and above`;
        return `Normal: ${lo}\u2013${hi}${unit}`;
    }

    // Explains readings that went outside the normal range during the period
    // even though the average (which the stamp is based on) may look fine.
    function outOfRangeNote(meta, stats, status) {
        if (!meta.normal || stats.low === null || stats.low === undefined) return '';
        const [lo, hi] = meta.normal;
        const unit = meta.unit ? ' ' + meta.unit : '';
        const parts = [];
        if (hi !== null && stats.high > hi && status !== 'high') parts.push(`went too high (up to ${stats.high}${unit})`);
        if (lo !== null && stats.low < lo && status !== 'low') parts.push(`dipped too low (down to ${stats.low}${unit})`);
        if (!parts.length) return '';
        return (status === 'optimal' ? 'But some readings ' : 'Also, some readings ') + parts.join(' and ');
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
                <div class="tank">
                    <div class="tank-fill" data-field="fill" style="height:0%"></div>
                    ${(meta.normal || []).map(v => v === null ? '' : `<div class="tank-mark" style="bottom:${((v - meta.min) / (meta.max - meta.min)) * 100}%"></div>`).join('')}
                </div>
                <p class="figure text-lg mt-3 text-center" data-field="avg" style="color:var(--color-ink-900)">—</p>
                <p class="text-xs text-center mt-1" style="color:var(--color-ink-700)" data-field="range"></p>
                <p class="text-xs text-center mt-0.5" style="color:#9C9377">${normalText(meta)}</p>
                <p class="text-xs text-center mt-0.5 hidden" style="color:var(--color-rust-600)" data-field="flag"></p>
                <p class="stamp mt-2 mx-auto w-fit hidden" data-field="status"></p>
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
                <p class="text-xs text-center mt-0.5" style="color:#9C9377">${normalText(meta)}</p>
                <p class="text-xs text-center mt-0.5 hidden" style="color:var(--color-rust-600)" data-field="flag"></p>
                <p class="stamp mt-2 mx-auto w-fit hidden" data-field="status"></p>
            `;
        }

        tile.addEventListener('click', () => selectSensor(key));
        tilesEl.appendChild(tile);
    });

    function hourLabel(h) {
        return (h % 12 === 0 ? 12 : h % 12) + (h < 12 ? ' AM' : ' PM');
    }

    function setRange(next) {
        range = next;
        document.querySelectorAll('.range-btn').forEach(b => b.classList.toggle('log-tab-active', b.dataset.range === range));
        hourBar.classList.toggle('hidden', range !== 'hour');
        if (range === 'hour') { refreshHourChips().then(load); } else { load(); }
    }

    // Asks for the whole chosen day to learn how many readings each hour has,
    // so the picker can show which hours are worth opening.
    function refreshHourChips() {
        const params = new URLSearchParams({ device, range: 'day', date: anchorDate });
        return fetch('/sensor-history-data?' + params.toString())
            .then(res => res.json())
            .then(data => {
                hourCounts = Array(24).fill(0);
                sensorKeys.forEach(k => ((data.series[k] || {}).count || []).forEach((c, i) => {
                    hourCounts[i] = Math.max(hourCounts[i], c);
                }));
                // If the selected hour has nothing, jump to the latest hour that does.
                if (!hourCounts[hour]) {
                    const withData = hourCounts.map((c, i) => c > 0 ? i : -1).filter(i => i >= 0);
                    if (withData.length) hour = withData[withData.length - 1];
                }
                renderHourChips();
            })
            .catch(err => { console.error('Hour list failed to load:', err); renderHourChips(); });
    }

    function renderHourChips() {
        hourChips.innerHTML = '';
        for (let h = 0; h < 24; h++) {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'hour-chip' + (h === hour ? ' hour-chip-active' : '');
            b.disabled = !hourCounts[h];
            b.innerHTML = `${hourLabel(h)}<small>${hourCounts[h] || '\u2013'}</small>`;
            b.addEventListener('click', () => { hour = h; renderHourChips(); load(); });
            hourChips.appendChild(b);
        }
    }

    // Clicking a dot zooms in: Week/Month dot -> that day, Day dot -> that hour.
    function zoomIn(points) {
        if (!points || !points.length || !latestPayload || range === 'hour') return;
        const idx = points[0].index;
        const slot = (latestPayload.slots || [])[idx];
        const counts = ((latestPayload.series[selectedKey] || {}).count) || [];
        if (!slot || !counts[idx]) return;
        anchorDate = slot.slice(0, 10);
        datePicker.value = anchorDate;
        if (range === 'day') { hour = parseInt(slot.slice(11, 13), 10); setRange('hour'); }
        else { setRange('day'); }
    }

    document.querySelectorAll('.range-btn').forEach(btn => {
        btn.addEventListener('click', () => setRange(btn.dataset.range));
    });

    datePicker.addEventListener('change', () => {
        if (!datePicker.value) return;
        anchorDate = datePicker.value;
        if (range === 'hour') { refreshHourChips().then(load); } else { load(); }
    });

    document.getElementById('closeDetail').addEventListener('click', () => {
        selectedKey = null;
        detailPanel.classList.add('hidden');
        document.querySelectorAll('.instrument').forEach(t => t.classList.remove('instrument-active'));
    });

    function load() {
        const params = new URLSearchParams({ device, range, date: anchorDate });
        if (range === 'hour') params.set('hour', hour);
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
            }

            const statusEl = tile.querySelector('[data-field="status"]');
            const status = hasVal ? statusForValue(meta, stats.avg) : null;
            if (status) {
                statusEl.textContent = STATUS_LABEL[status];
                statusEl.className = 'stamp mt-2 mx-auto w-fit status-' + status;
            } else {
                statusEl.className = 'stamp mt-2 mx-auto w-fit hidden';
            }

            const flagEl = tile.querySelector('[data-field="flag"]');
            const note = hasVal ? outOfRangeNote(meta, stats, status) : '';
            flagEl.textContent = note;
            flagEl.classList.toggle('hidden', !note);
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

    // Dashed lines at the normal range's limits, so it's visible exactly
    // when a reading drifted out of range.
    function limitLines(meta, n) {
        const lines = [];
        (meta.normal || []).forEach((v, i) => {
            if (v === null || (i === 0 && v <= meta.min)) return;
            lines.push({
                label: lines.length === 0 ? 'Normal range limit' : '__limit_extra',
                data: Array(n).fill(v),
                borderColor: '#AE4332', borderDash: [6, 5], borderWidth: 1.5,
                pointRadius: 0, fill: false,
            });
        });
        return lines;
    }

    function tooltipOrder(label) {
        return label === 'Average' ? 0 : (label === '__high_boundary' ? 1 : 2);
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

        const isHour = range === 'hour';
        const hourDatasets = [
            { label: 'Reading', data: series.avg, borderColor: '#29677D', backgroundColor: '#29677D', pointRadius: 5, pointHoverRadius: 8, tension: 0.2, fill: false, spanGaps: true },
            ...limitLines(sensors[selectedKey], latestPayload.labels.length),
        ];
        chartCanvas.style.cursor = isHour ? 'default' : 'pointer';

        chart = new Chart(chartCanvas.getContext('2d'), {
            type: 'line',
            data: {
                labels: latestPayload.labels,
                datasets: isHour ? hourDatasets : [
                    { label: '__high_boundary', data: series.high, borderColor: 'transparent', pointRadius: 2.5, pointHoverRadius: 5, pointBackgroundColor: '#8FB3C0', pointBorderColor: '#8FB3C0', fill: false, spanGaps: true },
                    { label: 'Lowest to highest reading', data: series.low, borderColor: 'transparent', backgroundColor: 'rgba(41,103,125,0.15)', pointRadius: 2.5, pointHoverRadius: 5, pointBackgroundColor: '#8FB3C0', pointBorderColor: '#8FB3C0', fill: '-1', spanGaps: true },
                    { label: 'Average', data: series.avg, borderColor: '#29677D', backgroundColor: '#29677D', pointRadius: 4, pointHoverRadius: 7, tension: 0.3, fill: false, spanGaps: true },
                    ...limitLines(sensors[selectedKey], latestPayload.labels.length),
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                onClick: (evt, els, chartInstance) => zoomIn(chartInstance.getElementsAtEventForMode(evt, 'index', { intersect: false }, true)),
                plugins: {
                    legend: { display: true, position: 'bottom', labels: { filter: item => item.text !== '__high_boundary' && item.text !== '__limit_extra' } },
                    tooltip: {
                        filter: item => !String(item.dataset.label).includes('limit'),
                        itemSort: (a, b) => tooltipOrder(a.dataset.label) - tooltipOrder(b.dataset.label),
                        callbacks: {
                            label: ctx => {
                                const names = { 'Reading': 'Reading', 'Average': 'Average', '__high_boundary': 'Highest', 'Lowest to highest reading': 'Lowest' };
                                const unit = sensors[selectedKey].unit ? ' ' + sensors[selectedKey].unit : '';
                                return `${names[ctx.dataset.label] || ctx.dataset.label}: ${ctx.parsed.y}${unit}`;
                            },
                            footer: items => {
                                if (isHour) return '';
                                const n = (series.count || [])[items[0].dataIndex];
                                return n ? `${n} reading${n === 1 ? '' : 's'} in this time slot` : '';
                            }
                        }
                    }
                },
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
