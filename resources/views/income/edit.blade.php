@extends('layouts.app')

@section('content')
@php
    $rawUnit = strtolower(trim((string) ($sale['unit'] ?? $sale['saleUnit'] ?? '')));
    $label = in_array($rawUnit, ['kg', 'kilo', 'kilos', 'kilogram', 'kilograms'], true)
        ? 'Kilos'
        : (in_array($rawUnit, ['pcs', 'piece', 'pieces'], true) ? 'Pcs' : ($sale['unit'] ?? 'Not specified'));
@endphp

<div class="max-w-xl mx-auto space-y-4">
    <div>
        <p class="text-xs font-semibold uppercase tracking-wider text-emerald-700">Sales</p>
        <h1 class="font-display text-2xl font-semibold text-slate-900">Correct a sale</h1>
        <p class="text-sm text-slate-500 mt-1">
            Fix a number that was typed wrong. The difference is returned to, or taken from, the same harvest batches.
        </p>
    </div>

    @if($errors->any())
        <div class="ledger-card p-4 text-sm" style="color:var(--color-rust-600); border-color:var(--color-rust-600)">
            {{ $errors->first() }}
        </div>
    @endif

    <div class="ledger-card p-6 space-y-4">
        <div class="flex items-center gap-2">
            <span class="stamp {{ ($sale['type'] ?? '') === 'fish' ? 'stamp-fish' : 'stamp-plant' }}">{{ $sale['type'] ?? 'sale' }}</span>
            <span class="text-sm font-medium text-gray-800">{{ $sale['itemName'] ?? 'Sale' }}</span>
            @if(!empty($sale['growthStage']))
                <span class="text-xs text-gray-500">— {{ $sale['growthStage'] }}</span>
            @endif
        </div>

        <form method="POST" action="{{ route('income.update', $saleId) }}" class="space-y-4">
            @csrf
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Quantity Sold ({{ $label }})</label>
                    <input type="number" step="{{ $label === 'Pcs' ? '1' : 'any' }}" min="0.01" name="quantitySold"
                           class="input-field w-full" value="{{ old('quantitySold', $sale['quantitySold'] ?? '') }}" required>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Price per unit (₱)</label>
                    <input type="number" step="any" min="0" name="unitPrice"
                           class="input-field w-full" value="{{ old('unitPrice', $sale['unitPrice'] ?? '') }}" required>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Discount (%)</label>
                    <input type="number" step="any" min="0" max="100" name="discountPercent"
                           class="input-field w-full" value="{{ old('discountPercent', $sale['discountPercent'] ?? 0) }}" required>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Date</label>
                    <input type="date" name="date" class="input-field w-full" value="{{ old('date', $saleDate) }}" required>
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">Note</label>
                <textarea name="notes" rows="2" class="input-field w-full">{{ old('notes', $sale['notes'] ?? '') }}</textarea>
            </div>

            <div class="flex gap-2 pt-1">
                <button type="submit" class="btn-primary w-full py-2.5 text-sm">Save correction</button>
                <a href="{{ route('income.index') }}" class="btn-ghost w-full py-2.5 text-sm text-center">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection
