@extends('layouts.app', ['title' => 'Fish Monitoring'])
@section('content')

<div class="fp-head">
    <div class="fp-head-main">
        <span class="stamp stamp-fish">{{ $devices[$activeDevice] }}</span>
        <h1 class="fp-title">Fish Monitoring</h1>
    </div>

    <div class="fp-head-side">
        <div class="fp-tabs" id="deviceTabs">
            @foreach($devices as $id => $label)
                <a href="{{ route('fishpond.index', $id) }}"
                   class="fp-tab {{ $activeDevice === $id ? 'fp-tab-active' : '' }}">{{ $label }}</a>
            @endforeach
        </div>
        <div class="fp-live">
            <span id="liveDot" class="fp-dot fp-dot-wait"></span>
            <span id="liveLabel">Connecting…</span>
            <span class="fp-sep">•</span>
            <span>Updated <span id="lastSync" class="fp-mono">—</span></span>
            <button type="button" id="refreshBtn" class="btn-ghost fp-refresh">Refresh</button>
        </div>
    </div>
</div>

<div id="errorBox" class="fp-error fp-hide">
    <strong>Couldn't read the fishpond records</strong>
    <p id="errorText">The page will keep retrying.</p>
</div>

<div class="fp-stats">
    <div class="fp-stat">
        <p class="fp-stat-label">Batches monitored</p>
        <p class="fp-stat-value" id="statPonds">—</p>
    </div>
    <div class="fp-stat">
        <p class="fp-stat-label">Species</p>
        <p class="fp-stat-value" id="statSpecies">—</p>
    </div>
    <div class="fp-stat">
        <p class="fp-stat-label">Total biomass</p>
        <p class="fp-stat-value" id="statBiomass">—</p>
    </div>
</div>

<p id="loadingState" class="fp-loading">Reading fishpond records…</p>

<div id="pondGrid" class="fp-grid"></div>

<div id="emptyState" class="fp-empty fp-hide">
    <p class="fp-empty-title">No fish batches yet</p>
    <p class="fp-empty-text">
        When the mobile app writes to
        <span class="fp-mono">devices/{{ $activeDevice }}/fishpond_records</span>,
        a batch box appears here automatically.
    </p>
</div>

<!-- Detail view: opens when a pond box is clicked -->
<div id="fpModal" class="fp-backdrop fp-hide">
    <div class="fp-modal" role="dialog" aria-modal="true" aria-label="Batch details" id="fpModalPanel"></div>
</div>

<div id="toasts" class="fp-toasts"></div>

@endsection

@push('scripts')
<style>
    /* ---- This page carries its own CSS so it never depends on a stale
       Tailwind build: the compiled bundle predates this view. ---- */
    .fp-mono { font-family: var(--font-mono); font-size: .9em; }

    /* Own hide utility: Tailwind's .hidden loses to .fp-backdrop{display:flex},
       which left the modal backdrop covering and blocking the whole page. */
    .fp-hide { display: none !important; }

    .fp-head {
        display: flex;
        flex-wrap: wrap;
        align-items: flex-end;
        justify-content: space-between;
        gap: 1rem;
        margin-bottom: 1.4rem;
    }
    .fp-head-main { min-width: 0; max-width: 48rem; }
    .fp-title {
        font-family: var(--font-display);
        font-size: 1.9rem;
        font-weight: 600;
        line-height: 1.15;
        color: var(--color-ink-900);
        margin-top: .55rem;
    }
    .fp-head-side { display: flex; flex-direction: column; align-items: flex-end; gap: .55rem; }

    .fp-tabs { display: flex; gap: 1.25rem; }
    .fp-tab {
        font-family: var(--font-display);
        font-size: .92rem;
        color: var(--color-ink-700);
        padding-bottom: 3px;
        border-bottom: 2px solid transparent;
        white-space: nowrap;
    }
    .fp-tab:hover { color: var(--color-ink-900); }
    .fp-tab-active { color: var(--color-forest-700); font-weight: 600; border-bottom-color: var(--color-amber-600); }

    .fp-live { display: flex; align-items: center; gap: .45rem; font-size: .84rem; color: var(--color-ink-700); }
    .fp-sep { opacity: .4; }
    .fp-refresh { font-size: .82rem; padding: .34rem .7rem; }

    .fp-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; background: var(--color-forest-600); }
    .fp-dot-wait { background: #C9C1A8; }
    .fp-dot-offline { background: var(--color-rust-600); }
    .fp-dot-live { animation: fp-pulse 1.8s ease-in-out infinite; }
    @keyframes fp-pulse {
        0%, 100% { box-shadow: 0 0 0 0 rgba(53, 107, 69, .55); }
        50% { box-shadow: 0 0 0 5px rgba(53, 107, 69, 0); }
    }

    .fp-error {
        background: #fff;
        border-left: 4px solid var(--color-rust-600);
        border-radius: .9rem;
        padding: .85rem 1rem;
        margin-bottom: 1.2rem;
        box-shadow: 0 1px 2px rgba(24,42,32,.05);
    }
    .fp-error strong { font-size: .95rem; color: var(--color-rust-600); }
    .fp-error p { font-size: .86rem; color: #7a3328; margin-top: .2rem; }

    .fp-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
        gap: .8rem;
        margin-bottom: 1.5rem;
    }
    .fp-stat {
        background: #fff;
        border: 1px solid #E7E0CC;
        border-left: 4px solid var(--color-water-600);
        border-radius: .85rem;
        padding: .8rem .95rem;
        min-width: 0;
    }
    .fp-stat-label { font-size: .7rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: #9C9377; }
    .fp-stat-value {
        font-family: var(--font-display);
        font-size: 1.7rem;
        font-weight: 600;
        line-height: 1.1;
        color: var(--color-ink-900);
        margin-top: .2rem;
        overflow-wrap: anywhere;
    }
    .fp-loading { font-size: .95rem; text-align: center; padding: 2.5rem 0; color: #9C9377; }

    .fp-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(265px, 1fr));
        gap: 1rem;
    }

    .fp-pond {
        display: block;
        width: 100%;
        text-align: left;
        background: #fff;
        border: 1px solid #E7E0CC;
        border-radius: 1rem;
        padding: 1rem;
        min-width: 0;
        cursor: pointer;
        transition: box-shadow .18s ease, transform .18s ease, border-color .18s ease;
    }
    .fp-pond:hover { box-shadow: 0 12px 28px rgba(24,42,32,.13); transform: translateY(-2px); border-color: #D9D0B4; }
    .fp-pond:focus-visible { outline: 2px solid var(--color-forest-600); outline-offset: 2px; }
    .fp-pond.is-new { box-shadow: 0 0 0 2px var(--color-forest-600); }
    .fp-pond.is-changed { box-shadow: 0 0 0 2px var(--color-amber-600); }

    .fp-pond-head { display: flex; align-items: flex-start; justify-content: space-between; gap: .5rem; }
    .fp-pond-name { min-width: 0; }
    .fp-species {
        font-family: var(--font-display);
        font-size: 1.25rem;
        font-weight: 600;
        line-height: 1.2;
        color: var(--color-ink-900);
        overflow-wrap: anywhere;
    }
    .fp-pill {
        display: inline-block;
        flex-shrink: 0;
        font-size: .68rem;
        font-weight: 700;
        letter-spacing: .05em;
        text-transform: uppercase;
        padding: .2rem .55rem;
        border-radius: 999px;
        white-space: nowrap;
    }
    .fp-pill-young { color: #29677D; background: #E3EEF1; }
    .fp-pill-grow { color: var(--color-forest-700); background: var(--color-moss-100); }
    .fp-pill-ready { color: var(--color-amber-600); background: var(--color-amber-100); }
    .fp-pill-unknown { color: #6B6455; background: #F1ECDC; }
    .fp-pill-new { color: #fff; background: var(--color-rust-600); }

    .fp-dead { margin-top: .35rem; font-size: .78rem; font-weight: 600; color: var(--color-rust-600); }
    .fp-pill-dead { color: #fff; background: var(--color-rust-600); }
    .fp-mortality {
        display: flex; align-items: flex-start; gap: .45rem;
        margin-top: .6rem; padding: .45rem .6rem;
        border-radius: .5rem;
        background: #FBEAE7;
        border-left: 4px solid var(--color-rust-600);
        color: #8A2B16;
        font-size: .8rem; font-weight: 700; line-height: 1.3;
        text-align: left;
    }
    .fp-mortality small { display: block; font-weight: 500; color: #A9543F; font-size: .72rem; }

    .fp-ring-wrap { display: flex; align-items: center; gap: .8rem; margin-top: .85rem; }
    .fp-ring { position: relative; width: 62px; height: 62px; flex-shrink: 0; }
    .fp-ring svg { display: block; width: 62px; height: 62px; transform: rotate(-90deg); }
    .fp-ring-val {
        position: absolute; inset: 0;
        display: flex; align-items: center; justify-content: center;
        font-family: var(--font-mono);
        font-size: .82rem;
        font-weight: 600;
        color: var(--color-ink-900);
    }
    .fp-count-wrap { min-width: 0; }
    .fp-count {
        font-family: var(--font-display);
        font-size: 1.95rem;
        font-weight: 600;
        line-height: 1;
        color: var(--color-forest-700);
    }
    .fp-count small { font-family: var(--font-sans); font-size: .82rem; font-weight: 500; color: #9C9377; margin-left: .25rem; }
    .fp-count-sub { font-size: .78rem; color: #9C9377; margin-top: .2rem; }

    .fp-kpis { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .4rem; margin-top: .85rem; }
    .fp-kpi { background: #FBF9F1; border: 1px solid #F1ECDC; border-radius: .6rem; padding: .42rem .3rem; text-align: center; min-width: 0; }
    .fp-kpi-label { display: block; font-size: .67rem; letter-spacing: .05em; text-transform: uppercase; color: #9C9377; }
    .fp-kpi-value {
        display: block;
        font-family: var(--font-mono);
        font-size: .92rem;
        font-weight: 600;
        color: var(--color-ink-900);
        margin-top: .12rem;
        overflow-wrap: anywhere;
    }

    .fp-bar { height: 6px; border-radius: 999px; background: #ECE5CE; overflow: hidden; margin-top: .8rem; }
    .fp-bar-fill { height: 100%; border-radius: 999px; background: var(--color-forest-600); transition: width .35s ease; }
    .fp-harvest { font-size: .82rem; color: var(--color-ink-700); margin-top: .35rem; }
    .fp-harvest strong { color: var(--color-ink-900); }
    .fp-harvest-due { color: var(--color-rust-600); }

    .fp-open {
        display: flex; align-items: center; justify-content: space-between;
        margin-top: .7rem; padding-top: .6rem;
        border-top: 1px solid #F1ECDC;
        font-size: .8rem; font-weight: 600; color: var(--color-forest-700);
    }

    .fp-empty { background: #fff; border: 1px solid #E7E0CC; border-radius: 1rem; padding: 2.5rem 1.5rem; text-align: center; }
    .fp-empty-title { font-family: var(--font-display); font-size: 1.25rem; color: var(--color-ink-900); }
    .fp-empty-text { font-size: .92rem; color: var(--color-ink-700); margin-top: .35rem; }

    /* ---- Detail view ---- */
    .fp-backdrop {
        position: fixed; inset: 0; z-index: 60;
        background: rgba(22, 41, 28, .55);
        backdrop-filter: blur(2px);
        display: flex; align-items: center; justify-content: center;
        padding: 1rem;
    }
    .fp-modal {
        background: #fff;
        width: 100%; max-width: 660px; max-height: 88vh;
        border-radius: 1.1rem;
        box-shadow: 0 25px 60px rgba(24,42,32,.4);
        overflow: auto;
        animation: fp-rise .18s ease-out;
    }
    @keyframes fp-rise { from { transform: translateY(10px); opacity: .6; } to { transform: translateY(0); opacity: 1; } }

    .fp-modal-head {
        position: sticky; top: 0; z-index: 1;
        display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem;
        background: #fff;
        padding: 1.1rem 1.25rem .9rem;
        border-bottom: 1px solid #F1ECDC;
    }
    .fp-modal-title { font-family: var(--font-display); font-size: 1.5rem; font-weight: 600; color: var(--color-ink-900); line-height: 1.2; }
    .fp-close {
        flex-shrink: 0;
        width: 34px; height: 34px;
        display: flex; align-items: center; justify-content: center;
        border: 1px solid #E7E0CC; background: #FBF9F1;
        border-radius: .55rem; font-size: 1.15rem; line-height: 1; color: var(--color-ink-700);
        cursor: pointer;
    }
    .fp-close:hover { background: #EFE9D8; }
    .fp-modal-body { padding: 1rem 1.25rem 1.35rem; }

    /* Metrics are grouped by the question they answer, then joined into one
       data row per group — six equal boxes made unrelated numbers look alike. */
    .fp-group {
        background: #FDFCF7;
        border: 1px solid #EDE7D6;
        border-radius: .9rem;
        padding: .8rem .9rem .85rem;
    }
    .fp-group + .fp-group { margin-top: .8rem; }
    .fp-group-head {
        display: flex; align-items: baseline; justify-content: space-between;
        flex-wrap: wrap; gap: .3rem .75rem; margin-bottom: .55rem;
    }
    .fp-group-head h4 {
        font-family: var(--font-display); font-size: 1.02rem; font-weight: 600;
        color: var(--color-ink-900); margin: 0;
    }
    .fp-group-note { font-size: .78rem; color: #9C9377; }

    .fp-facts {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(88px, 1fr));
        background: #fff;
        border: 1px solid #F1ECDC;
        border-radius: .7rem;
        overflow: hidden;
    }
    .fp-fact { padding: .5rem .6rem; min-width: 0; border-left: 1px solid #F1ECDC; }
    .fp-fact:first-child { border-left: none; }
    .fp-fact span { display: block; font-size: .67rem; letter-spacing: .06em; text-transform: uppercase; color: #9C9377; }
    .fp-fact b {
        display: block; font-family: var(--font-mono); font-size: 1.02rem; font-weight: 600;
        color: var(--color-ink-900); margin-top: .14rem; overflow-wrap: anywhere;
    }

    .fp-timeline { margin: .35rem 0 .7rem; }
    .fp-tl-track { position: relative; height: 8px; border-radius: 999px; background: #ECE5CE; }
    .fp-tl-fill { position: absolute; left: 0; top: 0; bottom: 0; border-radius: 999px; background: var(--color-forest-600); }
    .fp-tl-now { position: absolute; top: -4px; width: 3px; height: 16px; background: var(--color-ink-900); border-radius: 2px; transform: translateX(-1.5px); }
    .fp-tl-labels { display: flex; justify-content: space-between; gap: .5rem; margin-top: .5rem; font-size: .8rem; color: #9C9377; }
    .fp-tl-labels b { display: block; font-size: .68rem; letter-spacing: .05em; text-transform: uppercase; color: var(--color-ink-700); font-weight: 600; }
    .fp-tl-today { color: var(--color-forest-700); font-weight: 600; text-align: center; }

    .fp-section { margin-top: 1.2rem; }
    .fp-section h4 {
        font-family: var(--font-display); font-size: 1.02rem; font-weight: 600;
        color: var(--color-ink-900); margin-bottom: .55rem;
    }
    .fp-dl { display: grid; grid-template-columns: 1fr 1fr; gap: .6rem 1.2rem; }
    .fp-dl div { min-width: 0; }
    .fp-dl dt { font-size: .68rem; letter-spacing: .06em; text-transform: uppercase; color: #9C9377; }
    .fp-dl dd { font-family: var(--font-mono); font-size: .88rem; color: var(--color-ink-900); margin-top: .12rem; overflow-wrap: anywhere; }

    .fp-toasts { position: fixed; right: 1.25rem; bottom: 1.25rem; z-index: 70; display: flex; flex-direction: column; gap: .5rem; }
    .fp-toast {
        background: #fff; border-left: 3px solid var(--color-forest-600);
        border-radius: .6rem; padding: .55rem .85rem;
        box-shadow: 0 10px 26px rgba(24,42,32,.18);
        font-size: .88rem; color: var(--color-ink-900);
        animation: fp-rise .18s ease-out;
    }
    .fp-toast-changed { border-left-color: var(--color-amber-600); }

    @media (max-width: 640px) {
        .fp-head-side { align-items: flex-start; }
        .fp-dl { grid-template-columns: 1fr; }
    }
</style>

<script>
(function () {
    const device = @json($activeDevice);
    const POLL_MS = 6000;
    const RING_LENGTH = 163.36; // 2 * pi * r (r = 26)

    const els = {
        grid:      document.getElementById('pondGrid'),
        empty:     document.getElementById('emptyState'),
        loading:   document.getElementById('loadingState'),
        error:     document.getElementById('errorBox'),
        errorText: document.getElementById('errorText'),
        liveDot:   document.getElementById('liveDot'),
        liveLabel: document.getElementById('liveLabel'),
        lastSync:  document.getElementById('lastSync'),
        toasts:    document.getElementById('toasts'),
        modal:     document.getElementById('fpModal'),
        panel:     document.getElementById('fpModalPanel'),
        stats: {
            ponds:   document.getElementById('statPonds'),
            species: document.getElementById('statSpecies'),
            biomass: document.getElementById('statBiomass'),
        },
    };

    let signatures = new Map();
    let recordsByKey = new Map();
    let openKey = null;
    let loadedOnce = false;
    let inFlight = false;
    let timer = null;

    function esc(value) {
        return String(value === null || value === undefined ? '' : value).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function fmt(value, digits) {
        const d = digits || 0;
        if (value === null || value === undefined || value === '' || isNaN(Number(value))) return '—';
        return Number(value).toLocaleString(undefined, { minimumFractionDigits: d, maximumFractionDigits: d });
    }

    function shortDate(iso) {
        if (!iso) return '—';
        const d = new Date(iso);
        if (isNaN(d.getTime())) return '—';
        return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
    }

    function dateTime(iso) {
        if (!iso) return '—';
        const d = new Date(iso);
        if (isNaN(d.getTime())) return '—';
        return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) +
            ', ' + d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
    }

    function relative(iso) {
        if (!iso) return '';
        const diff = Math.abs(Date.now() - new Date(iso).getTime());
        const mins = Math.round(diff / 60000);
        if (mins < 1) return 'just now';
        if (mins < 60) return mins + 'm ago';
        const hours = Math.round(mins / 60);
        if (hours < 24) return hours + 'h ago';
        return Math.round(hours / 24) + 'd ago';
    }

    function pillClass(stage) {
        const s = String(stage).toLowerCase();
        if (s.indexOf('fry') >= 0 || s.indexOf('juvenile') >= 0 || s.indexOf('fingerling') >= 0) return 'fp-pill-young';
        if (s.indexOf('harvest') >= 0 || s.indexOf('finish') >= 0 || s.indexOf('market') >= 0) return 'fp-pill-ready';
        if (s.indexOf('grow') >= 0 || s.indexOf('adult') >= 0) return 'fp-pill-grow';
        return 'fp-pill-unknown';
    }

    function harvestLine(r) {
        if (r.daysToHarvest === null) return '<span style="color:#9C9377">No harvest date on this record</span>';
        if (r.daysToHarvest > 1) return '<strong>' + r.daysToHarvest + '</strong> days to expected harvest';
        if (r.daysToHarvest === 1) return '<strong>1</strong> day to expected harvest';
        if (r.daysToHarvest === 0) return '<strong class="fp-harvest-due">Harvest window is today</strong>';
        return '<strong class="fp-harvest-due">' + Math.abs(r.daysToHarvest) + ' days past</strong> expected harvest';
    }

    function ring(r) {
        const pct = r.progress === null ? 0 : Math.max(0, Math.min(100, r.progress));
        const due = r.daysToHarvest !== null && r.daysToHarvest <= 0;
        const color = due ? 'var(--color-rust-600)' : 'var(--color-forest-600)';
        const dash = (RING_LENGTH * pct / 100).toFixed(1);

        return '<div class="fp-ring">' +
            '<svg viewBox="0 0 64 64" aria-hidden="true">' +
                '<circle cx="32" cy="32" r="26" fill="none" stroke="#ECE5CE" stroke-width="6"></circle>' +
                '<circle cx="32" cy="32" r="26" fill="none" stroke="' + color + '" stroke-width="6" ' +
                    'stroke-linecap="round" stroke-dasharray="' + dash + ' ' + RING_LENGTH + '"></circle>' +
            '</svg>' +
            '<span class="fp-ring-val">' + (r.progress === null ? '—' : pct + '%') + '</span>' +
        '</div>';
    }

    function rowKey(r) {
        return r.key || r.batchId;
    }

    function signature(r) {
        return [r.fishCount, r.averageWeight, r.biomass, r.growthStage, r.expectedGrowthDays, r.updatedMs || ''].join('|');
    }

    function pondCard(r, flash) {
        const newTag = flash === 'is-new' ? '<span class="fp-pill fp-pill-new" style="margin-left:.35rem">new</span>' : '';

        return '<button type="button" class="fp-pond ' + flash + '" data-key="' + esc(rowKey(r)) + '">' +
            '<div class="fp-pond-head">' +
                '<div class="fp-pond-name">' +
                    '<div class="fp-species">' + esc(r.species) + newTag + '</div>' +
                '</div>' +
                '<div class="flex items-center gap-1.5 flex-shrink-0">' +
                    (r.deadCount ? '<span class="fp-pill fp-pill-dead" title="Fish that died are not sellable stock">' + fmt(r.deadCount) + ' dead</span>' : '') +
                    '<span class="fp-pill ' + pillClass(r.growthStage) + '">' + esc(r.growthStage) + '</span>' +
                '</div>' +
            '</div>' +
            '<div class="fp-ring-wrap">' +
                ring(r) +
                '<div class="fp-count-wrap">' +
                    '<div class="fp-count">' + fmt(r.fishCount) + '<small>fish alive</small></div>' +
                    '<div class="fp-count-sub"' +
                        (r.deadCount ? ' title="' + fmt(r.fishCount + r.deadCount) + ' stocked, ' + fmt(r.fishCount) + ' alive"' : '') + '>' +
                        (r.deadCount ? fmt(r.fishCount + r.deadCount) + '/' + fmt(r.fishCount) : 'in this batch') +
                    '</div>' +
                '</div>' +
            '</div>' +
            '<div class="fp-kpis">' +
                '<div class="fp-kpi"><span class="fp-kpi-label">Avg wt</span><span class="fp-kpi-value">' + (r.averageWeight ? fmt(r.averageWeight, 2) + ' g' : '—') + '</span></div>' +
                '<div class="fp-kpi"><span class="fp-kpi-label">Biomass</span><span class="fp-kpi-value">' + (r.biomass ? fmt(r.biomass, 2) + ' kg' : '—') + '</span></div>' +
                '<div class="fp-kpi"><span class="fp-kpi-label">Age</span><span class="fp-kpi-value">' + (r.ageDays === null ? '—' : fmt(r.ageDays) + ' d') + '</span></div>' +
            '</div>' +
            '<div class="fp-bar"><div class="fp-bar-fill" style="width:' + (r.progress === null ? 0 : Math.max(0, Math.min(100, r.progress))) + '%"></div></div>' +
            '<p class="fp-harvest">' + harvestLine(r) + '</p>' +
            '<div class="fp-open"><span>View full details</span><span aria-hidden="true">→</span></div>' +
        '</button>';
    }

    function fact(label, value, color) {
        return '<div class="fp-fact"><span>' + label + '</span><b' +
            (color ? ' style="color:' + color + '"' : '') + '>' + value + '</b></div>';
    }

    function info(label, value) {
        return '<div><dt>' + label + '</dt><dd>' + value + '</dd></div>';
    }

    function modalHtml(r) {
        const pct = r.progress === null ? 0 : Math.max(0, Math.min(100, r.progress));
        const due = r.daysToHarvest !== null && r.daysToHarvest <= 0;

        return '<div class="fp-modal-head">' +
                '<div style="min-width:0">' +
                    '<div class="fp-modal-title">' + esc(r.species) + '</div>' +
                    '<div style="margin-top:.45rem; display:flex; gap:.35rem; flex-wrap:wrap">' +
                        (r.deadCount ? '<span class="fp-pill fp-pill-dead">' + fmt(r.deadCount) + ' dead</span>' : '') +
                        '<span class="fp-pill ' + pillClass(r.growthStage) + '">' + esc(r.growthStage) + '</span>' +
                    '</div>' +
                '</div>' +
                '<button type="button" class="fp-close" data-close="1" aria-label="Close details">✕</button>' +
            '</div>' +
            '<div class="fp-modal-body">' +
                '<div class="fp-group">' +
                    '<div class="fp-group-head">' +
                        '<h4>Stock today</h4>' +
                    '</div>' +
                    '<div class="fp-facts">' +
                        fact('Fish count', fmt(r.fishCount) + ' alive') +
                        fact('Average weight', r.averageWeight ? fmt(r.averageWeight, 2) + ' g' : '—') +
                        fact('Biomass', r.biomass ? fmt(r.biomass, 2) + ' kg' : '—') +
                    '</div>' +
                    (r.deadCount
                        ? '<div class="fp-mortality" role="note">⚠<span>' + fmt(r.deadCount) + ' fish died of ' +
                            fmt(r.fishCount + r.deadCount) + ' stocked — ' + fmt(r.fishCount) + ' left' +
                            (r.mortalityReason ? '<small>Reason: ' + esc(r.mortalityReason) + '</small>' : '') +
                            '</span></div>'
                        : '') +
                '</div>' +

                '<div class="fp-group">' +
                    '<div class="fp-group-head">' +
                        '<h4>Growth timeline</h4>' +
                        '<span class="fp-group-note">' +
                            (r.expectedGrowthDays
                                ? pct + '% of a ' + r.expectedGrowthDays + '-day cycle'
                                : (r.progress === null ? 'no stocking date on record' : pct + '% of cycle')) +
                        '</span>' +
                    '</div>' +
                    '<div class="fp-timeline">' +
                        '<div class="fp-tl-track">' +
                            '<div class="fp-tl-fill" style="width:' + pct + '%;' + (due ? 'background:var(--color-rust-600)' : '') + '"></div>' +
                            '<div class="fp-tl-now" style="left:' + pct + '%"></div>' +
                        '</div>' +
                        '<div class="fp-tl-labels">' +
                            '<span><b>Stocked</b>' + shortDate(r.stockingDate) + '</span>' +
                            '<span class="fp-tl-today">' + (r.ageDays === null ? '—' : 'Day ' + fmt(r.ageDays)) + '</span>' +
                            '<span style="text-align:right"><b>Expected harvest</b>' + shortDate(r.expectedHarvest) + '</span>' +
                        '</div>' +
                    '</div>' +
                    '<div class="fp-facts">' +
                        fact('Age', r.ageDays === null ? '—' : fmt(r.ageDays) + ' days') +
                        fact('Cycle progress', r.progress === null ? '—' : pct + '%') +
                        fact('To harvest', r.daysToHarvest === null ? '—' : (due ? Math.abs(r.daysToHarvest) + ' d past' : fmt(r.daysToHarvest) + ' days'),
                            due ? 'var(--color-rust-600)' : '') +
                    '</div>' +
                '</div>' +

                '<div class="fp-section">' +
                    '<h4>Schedule</h4>' +
                    '<dl class="fp-dl">' +
                        info('Stocking date', shortDate(r.stockingDate)) +
                        info('Expected harvest', shortDate(r.expectedHarvest)) +
                        info('Record created', dateTime(r.createdAt)) +
                        info('Last update', r.updatedAt ? dateTime(r.updatedAt) + ' (' + relative(r.updatedAt) + ')' : '—') +
                    '</dl>' +
                '</div>' +

            '</div>';
    }

    function openModal(key) {
        const r = recordsByKey.get(key);
        if (!r) return;

        openKey = key;
        els.panel.innerHTML = modalHtml(r);
        els.modal.classList.remove('fp-hide');
        document.body.style.overflow = 'hidden';
    }

    function closeModal() {
        openKey = null;
        els.modal.classList.add('fp-hide');
        els.panel.innerHTML = '';
        document.body.style.overflow = '';
    }

    function toast(message, kind) {
        if (!els.toasts) return;
        const el = document.createElement('div');
        el.className = 'fp-toast' + (kind === 'changed' ? ' fp-toast-changed' : '');
        el.textContent = message;
        els.toasts.appendChild(el);
        setTimeout(function () { el.remove(); }, 6000);
    }

    function setLive(state) {
        els.liveDot.classList.remove('fp-dot-live', 'fp-dot-wait', 'fp-dot-offline');
        if (state === 'live') {
            els.liveDot.classList.add('fp-dot-live');
            els.liveLabel.textContent = 'Live';
        } else if (state === 'offline') {
            els.liveDot.classList.add('fp-dot-offline');
            els.liveLabel.textContent = 'Offline';
        } else {
            els.liveDot.classList.add('fp-dot-wait');
            els.liveLabel.textContent = 'Connecting…';
        }
    }

    function showError(detail) {
        els.error.classList.remove('fp-hide');
        els.errorText.textContent = detail || 'The page will keep retrying.';
    }

    function hideError() {
        els.error.classList.add('fp-hide');
    }

    function apply(data) {
        const records = Array.isArray(data.records) ? data.records : [];
        const summary = data.summary || {};

        els.loading.classList.add('fp-hide');
        els.empty.classList.toggle('fp-hide', records.length > 0);

        els.stats.ponds.textContent = fmt(summary.batches !== undefined ? summary.batches : records.length);
        els.stats.species.textContent = fmt(summary.species || 0);
        els.stats.biomass.textContent = fmt(summary.biomassKg || 0, 2) + ' kg';

        recordsByKey = new Map();
        const next = new Map();
        const freshIds = [];
        const touchedIds = [];

        records.forEach(function (r) {
            const id = rowKey(r);
            const sig = signature(r);
            recordsByKey.set(id, r);
            next.set(id, sig);

            if (!loadedOnce) return;
            if (!signatures.has(id)) {
                freshIds.push(id);
            } else if (signatures.get(id) !== sig) {
                touchedIds.push(id);
            }
        });

        els.grid.innerHTML = records.map(function (r) {
            const id = rowKey(r);
            let flash = '';
            if (freshIds.indexOf(id) >= 0) flash = 'is-new';
            else if (touchedIds.indexOf(id) >= 0) flash = 'is-changed';
            return pondCard(r, flash);
        }).join('');

        if (freshIds.length) {
            const r = recordsByKey.get(freshIds[0]);
            toast('New batch: ' + r.species + ' — ' + fmt(r.fishCount) + ' fish', 'new');
        }
        if (touchedIds.length) {
            toast(touchedIds.length + (touchedIds.length > 1 ? ' batches updated' : ' batch updated'), 'changed');
        }

        if (freshIds.length || touchedIds.length) {
            setTimeout(function () {
                els.grid.querySelectorAll('.is-new, .is-changed').forEach(function (el) {
                    el.classList.remove('is-new', 'is-changed');
                });
            }, 6000);
        }

        // Keep an open detail view in sync with the newest values.
        if (openKey && recordsByKey.has(openKey)) {
            els.panel.innerHTML = modalHtml(recordsByKey.get(openKey));
        } else if (openKey) {
            closeModal();
        }

        signatures = next;
        loadedOnce = true;
    }

    async function refresh(manual) {
        if (inFlight) return;
        inFlight = true;
        if (manual) setLive('waiting');

        try {
            const res = await fetch('/fishpond-records?device=' + encodeURIComponent(device), {
                headers: { 'Accept': 'application/json' },
                cache: 'no-store',
            });

            if (res.redirected) {
                window.location.href = res.url;
                return;
            }

            const data = await res.json();

            if (data.error) {
                showError(data.detail || data.error);
                setLive('offline');
                return;
            }

            hideError();
            apply(data);
            setLive('live');
            els.lastSync.textContent = new Date().toLocaleTimeString();
        } catch (e) {
            showError('Network error — retrying automatically.');
            setLive('offline');
        } finally {
            inFlight = false;
        }
    }

    function startPolling() {
        clearInterval(timer);
        timer = setInterval(refresh, POLL_MS);
    }

    els.grid.addEventListener('click', function (e) {
        const pond = e.target.closest('.fp-pond');
        if (pond) openModal(pond.getAttribute('data-key'));
    });

    els.modal.addEventListener('click', function (e) {
        if (e.target === els.modal || e.target.closest('[data-close]')) closeModal();
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && openKey) closeModal();
    });

    document.getElementById('refreshBtn').addEventListener('click', function () {
        refresh(true);
    });

    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            clearInterval(timer);
        } else {
            refresh();
            startPolling();
        }
    });

    setLive('waiting');
    refresh();
    startPolling();
})();
</script>
@endpush
