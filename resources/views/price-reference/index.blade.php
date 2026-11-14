@extends('layouts.app', ['title' => 'Price Reference'])

@section('content')
@php
    $unitLabel = fn ($u) => $u === 'pcs' ? 'pcs' : 'kilos';
    $money = fn ($n) => $n === null ? '—' : '₱' . number_format((float) $n, 2);
    $sourceBadge = function (array $p) {
        if (!empty($p['saved'])) {
            $label = $p['source'] ?? '';
            return [$label !== '' ? $label : 'Farm price', '#8a5a1e', '#fdf1dc'];
        }
        if (!empty($p['own'])) {
            return ['PSA', '#2f5d3a', '#e6f1e6'];
        }
        if (!empty($p['seed'])) {
            return ['BFAR / SEAFDEC', '#6b4a1b', '#f7ecd8'];
        }
        if (!empty($p['estimated'])) {
            return ['Estimate', '#6b6350', '#f0ece0'];
        }
        return ['DA Region I', '#1f4d6b', '#e3eef5'];
    };
@endphp

<div class="mb-4">
    <h3 class="font-display text-base font-semibold" style="color:var(--color-ink-900)">Price Reference</h3>
</div>

@if(session('status'))
    <div class="ledger-card p-4 mb-4 text-sm" style="color:var(--color-forest-700); border-color:var(--color-forest-600)">
        {{ session('status') }}
    </div>
@endif

@if($errors->any())
    <div class="ledger-card p-4 mb-4 text-sm" style="color:var(--color-rust-600); border-color:var(--color-rust-600)">
        {{ $errors->first() }}
    </div>
@endif

{{-- Tabs --}}
<div class="flex gap-2 p-1 bg-[#EFE9D8] rounded-xl w-fit mb-6 flex-wrap" id="priceTabs">
    <button type="button" data-tab="active" class="px-6 py-2.5 rounded-lg text-sm font-semibold transition">Active</button>
    <button type="button" data-tab="history" class="px-6 py-2.5 rounded-lg text-sm font-semibold transition">History</button>
</div>

{{-- ============ Active prices ============ --}}
<div data-panel="active">
    @php
        $fishRows  = collect($priceList)->where('kind', 'fish')->count();
        $plantRows = count($priceList) - $fishRows;
    @endphp
    <div class="flex gap-2 p-1 bg-[#EFE9D8] rounded-xl w-fit mb-4 flex-wrap" id="priceKind">
        <button type="button" data-kind="all" class="px-4 py-1.5 rounded-lg text-sm font-semibold transition">All ({{ count($priceList) }})</button>
        <button type="button" data-kind="plant" class="px-4 py-1.5 rounded-lg text-sm font-semibold transition">Plants ({{ $plantRows }})</button>
        <button type="button" data-kind="fish" class="px-4 py-1.5 rounded-lg text-sm font-semibold transition">Fish ({{ $fishRows }})</button>
    </div>

    <div class="ledger-card overflow-hidden mb-8">
        <div class="overflow-x-auto">
        <table class="min-w-full ledger-table">
            <thead>
                <tr>
                    <th class="p-3 text-left">Product</th>
                    <th class="p-3 text-left">Unit</th>
                    <th class="p-3 text-left">Retail Price</th>
                    <th class="p-3 text-left">Wholesale</th>
                    <th class="p-3 text-left">Usual Range</th>
                    <th class="p-3 text-left">Last Updated</th>
                    <th class="p-3 text-left">Source</th>
                </tr>
            </thead>
            <tbody>
                @foreach($priceList as $p)
                @php
                    $stale = empty($p['own']) && empty($p['saved']) && !empty($p['updatedOn'])
                        && \Carbon\Carbon::parse($p['updatedOn'])->diffInDays(now('Asia/Manila')) > 14;
                    [$badgeText, $badgeColor, $badgeBg] = $sourceBadge($p);
                @endphp
                <tr class="border-b border-[#F1ECDC]" data-price-row data-kind="{{ $p['kind'] ?? '' }}">
                    <td class="p-3 align-middle text-sm font-medium text-gray-800">
                        {{ $p['product'] }}
                        @if(!empty($p['stage']))
                            <div class="text-[11px] font-normal text-gray-500">{{ $p['stage'] }}</div>
                        @endif
                    </td>
                    <td class="p-3 align-middle text-sm text-gray-600">
                        {{ $unitLabel($p['unit']) }}
                        @if($p['piecesPerKg'] !== null)
                            <div class="text-[11px] text-gray-400">{{ rtrim(rtrim(number_format($p['piecesPerKg'], 2, '.', ''), '0'), '.') }} pcs/kg</div>
                        @endif
                    </td>
                    <td class="p-3 align-middle figure text-sm" style="color:var(--color-forest-700)">
                        ₱{{ number_format($p['retailPrice'], 2) }}
                    </td>
                    <td class="p-3 align-middle figure text-sm text-gray-600">{{ $money($p['wholesalePrice']) }}</td>
                    <td class="p-3 align-middle text-sm text-gray-600">
                        @if($p['floorPrice'] !== null || $p['ceilingPrice'] !== null)
                            {{ $money($p['floorPrice']) }} – {{ $money($p['ceilingPrice']) }}
                        @else
                            <span class="text-gray-400">Not set</span>
                        @endif
                    </td>
                    <td class="p-3 align-middle text-sm {{ $stale ? '' : 'text-gray-600' }}" style="{{ $stale ? 'color:var(--color-rust-600)' : '' }}">
                        {{ !empty($p['updatedOn']) ? \Carbon\Carbon::parse($p['updatedOn'])->format('M d, Y') : '—' }}
                        @if($stale)<div class="text-[11px]">More than 2 weeks old</div>@endif
                    </td>
                    <td class="p-3 align-middle">
                        <span class="inline-block text-[11px] font-semibold px-2 py-0.5 rounded-full whitespace-nowrap" style="color:{{ $badgeColor }};background:{{ $badgeBg }}">{{ $badgeText }}</span>
                    </td>
                </tr>
                @endforeach
                @if(count($priceList) === 0)
                <tr><td colspan="7" class="p-8 text-center text-sm text-gray-400">Nothing priced yet. Record a harvest and its DA Region I price appears here.</td></tr>
                @endif
                <tr id="priceKindEmpty" class="hidden"><td colspan="7" class="p-8 text-center text-sm text-gray-400">No prices for this kind yet.</td></tr>
            </tbody>
        </table>
        </div>
    </div>
</div>

{{-- ============ Price history ============ --}}
<div data-panel="history" class="hidden">
    <div class="ledger-card overflow-hidden mb-8">
        <div class="overflow-x-auto">
        <table class="min-w-full ledger-table">
            <thead>
                <tr>
                    <th class="p-3 text-left">Changed</th>
                    <th class="p-3 text-left">Product</th>
                    <th class="p-3 text-left">Unit</th>
                    <th class="p-3 text-left">Old Price</th>
                    <th class="p-3 text-left">New Price</th>
                    <th class="p-3 text-left">Source</th>
                </tr>
            </thead>
            <tbody>
                @forelse($history as $h)
                <tr class="border-b border-[#F1ECDC]">
                    <td class="p-3 align-middle text-sm text-gray-600">
                        {{ $h['changedAt'] !== '' ? \Carbon\Carbon::parse($h['changedAt'])->format('M d, Y g:i A') : '—' }}
                    </td>
                    <td class="p-3 align-middle text-sm font-medium text-gray-800">{{ $h['product'] }}</td>
                    <td class="p-3 align-middle text-sm text-gray-600">{{ $unitLabel($h['unit']) }}</td>
                    <td class="p-3 align-middle figure text-sm text-gray-600">{{ $money($h['oldPrice']) }}</td>
                    <td class="p-3 align-middle figure text-sm" style="color:var(--color-forest-700)">{{ $money($h['newPrice']) }}</td>
                    <td class="p-3 align-middle text-sm text-gray-600">{{ $h['source'] !== '' ? $h['source'] : '—' }}</td>
                </tr>
                @empty
                <tr><td colspan="6" class="p-8 text-center text-sm text-gray-400">No price changes recorded yet. Once a price changes, the old one appears here.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    // ---- Tabs ----
    const TAB_NAMES = ['active', 'history'];

    function activateTab(name) {
        if (!TAB_NAMES.includes(name)) name = 'active';

        document.querySelectorAll('[data-panel]').forEach(p => {
            p.classList.toggle('hidden', p.dataset.panel !== name);
        });

        document.querySelectorAll('#priceTabs [data-tab]').forEach(b => {
            const on = b.dataset.tab === name;
            b.classList.toggle('bg-white', on);
            b.classList.toggle('shadow-sm', on);
            b.classList.toggle('text-gray-500', !on);
            b.style.color = on ? 'var(--color-forest-700)' : '';
        });
    }

    document.querySelectorAll('#priceTabs [data-tab]').forEach(b => {
        b.addEventListener('click', () => {
            activateTab(b.dataset.tab);
            history.replaceState(null, '', '#' + b.dataset.tab);
        });
    });

    activateTab(location.hash.replace('#', ''));

    // ---- Plants / fish filter ----
    const KIND_NAMES = ['all', 'plant', 'fish'];

    function filterKind(kind) {
        if (!KIND_NAMES.includes(kind)) kind = 'all';

        let shown = 0;
        document.querySelectorAll('[data-price-row]').forEach(tr => {
            const on = kind === 'all' || tr.dataset.kind === kind;
            tr.classList.toggle('hidden', !on);
            if (on) shown++;
        });

        const empty = document.getElementById('priceKindEmpty');
        if (empty) empty.classList.toggle('hidden', shown > 0);

        document.querySelectorAll('#priceKind [data-kind]').forEach(b => {
            const on = b.dataset.kind === kind;
            b.classList.toggle('bg-white', on);
            b.classList.toggle('shadow-sm', on);
            b.classList.toggle('text-gray-500', !on);
            b.style.color = on ? 'var(--color-forest-700)' : '';
        });
    }

    document.querySelectorAll('#priceKind [data-kind]').forEach(b => {
        b.addEventListener('click', () => filterKind(b.dataset.kind));
    });
    filterKind('all');

</script>
@endpush
