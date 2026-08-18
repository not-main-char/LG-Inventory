@extends('layouts.app', ['title' => 'Archives'])

@section('content')
<div class="mb-6">
    <div class="flex gap-2 p-1 bg-[#EFE9D8] rounded-xl w-fit">
        <a href="{{ route('archives.index', ['tab' => 'inventory']) }}" 
           class="px-6 py-2.5 rounded-lg text-sm font-semibold transition {{ $tab === 'inventory' ? 'bg-white shadow-sm' : 'text-gray-500 hover:text-gray-700' }}"
           style="{{ $tab === 'inventory' ? 'color:var(--color-forest-700)' : '' }}">
            Inventory Archive
        </a>
        <a href="{{ route('archives.index', ['tab' => 'sales']) }}" 
           class="px-6 py-2.5 rounded-lg text-sm font-semibold transition {{ $tab === 'sales' ? 'bg-white shadow-sm' : 'text-gray-500 hover:text-gray-700' }}"
           style="{{ $tab === 'sales' ? 'color:var(--color-forest-700)' : '' }}">
            Sales Archive
        </a>
    </div>
</div>

@if($tab === 'inventory')
    <div class="ledger-card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full ledger-table">
                <thead>
                    <tr>
                        <th class="p-3 text-left">Item</th>
                        <th class="p-3 text-left">Last Stock / Unit</th>
                        <th class="p-3 text-left">Archived Date</th>
                        <th class="p-3 text-left">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($inventoryItems as $item)
                    @php
                        $data = $item->data();
                        $id = $item->id();
                        $stampClass = $data['type'] === 'fish' ? 'stamp-fish' : ($data['type'] === 'plant' ? 'stamp-plant' : 'stamp-supplies');
                    @endphp
                    <tr class="border-b border-[#F1ECDC] opacity-75">
                        <td class="p-3 align-middle">
                            <div class="flex items-center gap-2.5">
                                <span class="stamp {{ $stampClass }}">{{ $data['type'] }}</span>
                                <span class="text-sm font-medium text-gray-800">{{ $data['name'] }}</span>
                            </div>
                        </td>
                        <td class="p-3 align-middle">
                            <span class="figure text-sm">{{ rtrim(rtrim(number_format((float)$data['currentStock'], 4, '.', ''), '0'), '.') }} {{ $data['unit'] }}</span>
                        </td>
                        <td class="p-3 align-middle text-sm text-gray-600">
                            {{ isset($data['archivedAt']) ? \Carbon\Carbon::parse($data['archivedAt'])->format('M d, Y') : 'N/A' }}
                        </td>
                        <td class="p-3 align-middle">
                            @if($role === 'admin')
                                <form method="POST" action="{{ route('inventory.restore', $id) }}" onsubmit="return confirm('Restore this item to active inventory?')" class="inline">
                                    @csrf
                                    <button type="submit" class="font-medium hover:underline" style="color:var(--color-forest-700)">Restore</button>
                                </form>
                            @else
                                <span class="text-gray-400 italic text-xs">View only</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                    @if(count($inventoryItems) === 0)
                    <tr><td colspan="4" class="p-8 text-center text-sm text-gray-400">No archived items found.</td></tr>
                    @endif
                </tbody>
            </table>
        </div>
    </div>
@else
    <div class="ledger-card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full ledger-table">
                <thead>
                    <tr>
                        <th class="p-3 text-left">Crop / Fish</th>
                        <th class="p-3 text-left">Quantity</th>
                        <th class="p-3 text-left">Total Sale</th>
                        <th class="p-3 text-left">Sale Date</th>
                        <th class="p-3 text-left">Archived Date</th>
                        <th class="p-3 text-left">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($salesItems as $sale)
                    @php 
                        $data = (array)$sale->data; 
                        $stampClass = $data['type'] === 'fish' ? 'stamp-fish' : 'stamp-plant'; 
                    @endphp
                    <tr class="border-b border-[#F1ECDC] opacity-75">
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
                        <td class="p-3 align-middle text-sm text-gray-600">
                            {{ isset($data['archivedAt']) ? \Carbon\Carbon::parse($data['archivedAt'])->format('M d, Y') : 'N/A' }}
                        </td>
                        <td class="p-3 align-middle">
                            @if($role === 'admin')
                                <form method="POST" action="{{ route('income.restore', $sale->id) }}" onsubmit="return confirm('Restore this sale record to active list?')">
                                    @csrf
                                    <button type="submit" class="text-sm font-medium hover:underline" style="color:var(--color-forest-700)">Restore</button>
                                </form>
                            @else
                                <span class="text-gray-400 italic text-xs">View only</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                    @if(count($salesItems) === 0)
                    <tr><td colspan="6" class="p-8 text-center text-sm text-gray-400">No archived sales found.</td></tr>
                    @endif
                </tbody>
            </table>
        </div>
    </div>
@endif
@endsection
