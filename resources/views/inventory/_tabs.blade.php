<div class="flex gap-2 p-1 bg-[#EFE9D8] rounded-xl w-fit mb-6">
    <a href="{{ route('inventory.index') }}"
       class="px-6 py-2.5 rounded-lg text-sm font-semibold transition {{ $active === 'stock' ? 'bg-white shadow-sm' : 'text-gray-500 hover:text-gray-700' }}"
       style="{{ $active === 'stock' ? 'color:var(--color-forest-700)' : '' }}">
        Stock
    </a>
    <a href="{{ route('harvest.index') }}"
       class="px-6 py-2.5 rounded-lg text-sm font-semibold transition {{ $active === 'harvest' ? 'bg-white shadow-sm' : 'text-gray-500 hover:text-gray-700' }}"
       style="{{ $active === 'harvest' ? 'color:var(--color-forest-700)' : '' }}">
        Harvest
    </a>
</div>
