@extends('layouts.app', ['title' => 'Pond Monitoring'])

@section('content')
@php
    $kg = fn ($n) => rtrim(rtrim(number_format((float) $n, 3, '.', ''), '0'), '.');
@endphp

<div class="mb-4">
    <h3 class="font-display text-2xl font-semibold" style="color:var(--color-ink-900)">Pond Monitoring</h3>
</div>

@if(!empty($error))
    <div class="mb-4 text-xs px-3 py-2 rounded-lg" style="background:#fdf1dc;color:#8a5a1e">{{ $error }} Showing what could be read.</div>
@endif

<div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-8">
    @foreach($ponds as $p)
    <div class="ledger-card p-5">
        <div class="flex items-start justify-between gap-3">
            <div>
                <span class="stamp stamp-fish">pond</span>
                <h4 class="font-display text-xl font-semibold mt-2" style="color:var(--color-ink-900)">{{ $p['label'] }}</h4>
                <p class="text-sm text-gray-500 font-mono">{{ $p['device'] }}</p>
            </div>
            <a href="{{ route('fishpond.index', $p['device']) }}" class="btn-ghost px-3 py-2 text-sm whitespace-nowrap">View batches</a>
        </div>

        <div class="grid grid-cols-3 gap-3 mt-4">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wider text-gray-500">Batches</p>
                <p class="figure text-2xl" style="color:var(--color-forest-700)">{{ $p['batches'] }}</p>
            </div>
            <div>
                <p class="text-xs font-semibold uppercase tracking-wider text-gray-500">Species</p>
                <p class="figure text-2xl" style="color:var(--color-forest-700)">{{ $p['speciesCount'] }}</p>
            </div>
            <div>
                <p class="text-xs font-semibold uppercase tracking-wider text-gray-500">Biomass</p>
                <p class="figure text-2xl" style="color:var(--color-forest-700)">{{ $kg($p['biomass']) }} <span class="text-sm font-normal text-gray-500">kg</span></p>
            </div>
        </div>

        <div class="mt-4 pt-3 border-t border-[#F1ECDC] text-sm text-gray-700">
            @if($p['batches'] === 0)
                <span class="text-gray-500">No active batch in this pond yet.</span>
            @else
                <p><span class="text-gray-500">Fish:</span> {{ number_format($p['fish']) }}</p>
                <div class="flex flex-wrap gap-1.5 mt-2">
                    @foreach($p['species'] as $sp)
                        <span class="inline-block text-xs font-medium px-2.5 py-1 rounded-full" style="color:#2f5d3a;background:#e6f1e6">{{ $sp }}</span>
                    @endforeach
                </div>
                @if(count($p['stages']))
                    <p class="mt-2 text-xs text-gray-500">Stages: {{ implode(', ', $p['stages']) }}</p>
                @endif
            @endif
        </div>
    </div>
    @endforeach
</div>
@endsection
