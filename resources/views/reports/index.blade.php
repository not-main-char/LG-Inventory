@extends('layouts.app')

@section('content')
<div class="max-w-3xl mx-auto space-y-6">
    <div>
        <p class="text-xs font-semibold uppercase tracking-wider text-emerald-700">Reports</p>
        <h1 class="font-display text-2xl font-semibold text-slate-900">Generate a Report</h1>
        <p class="text-sm text-slate-500 mt-1">Select one month, one report, and one format. The QR code will point to that exact file.</p>
    </div>

    <div class="ledger-card p-6">
        <form method="GET" action="{{ route('reports.index') }}" class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
            <div>
                <label for="month" class="block text-xs font-semibold text-gray-600 mb-1">1. Month</label>
                <input id="month" name="month" type="month" value="{{ $month }}" class="input-field w-full" required>
            </div>
            <div>
                <label for="type" class="block text-xs font-semibold text-gray-600 mb-1">2. Report</label>
                <select id="type" name="type" class="input-field w-full" required>
                    <option value="">Select report</option>
                    <option value="inventory" @selected($type === 'inventory')>Inventory</option>
                    <option value="sales" @selected($type === 'sales')>Sales</option>
                </select>
            </div>
            <div>
                <label for="format" class="block text-xs font-semibold text-gray-600 mb-1">3. Format</label>
                <select id="format" name="format" class="input-field w-full" required>
                    <option value="">Select format</option>
                    <option value="xlsx" @selected($format === 'xlsx')>Excel (.xlsx)</option>
                    <option value="pdf" @selected($format === 'pdf')>PDF (.pdf)</option>
                </select>
            </div>
            <div class="md:col-span-3">
                <button class="btn-primary px-5 py-3 text-sm" type="submit">Create File and QR Code</button>
            </div>
        </form>
    </div>

    @if($validationMessage)
        <div class="ledger-card p-5 border border-amber-200 bg-amber-50 text-amber-800 text-sm">{{ $validationMessage }}</div>
    @elseif($qrCode)
        <div class="ledger-card p-7 text-center">
            <p class="text-xs font-bold uppercase tracking-widest text-emerald-700">Generated File</p>
            <h2 class="font-display text-xl font-semibold text-slate-900 mt-2">{{ $reportLabel }}</h2>
            <p class="text-sm text-slate-500 mt-2">Save the report file and the QR image. Scanning the QR image opens this exact report.</p>

            <div class="mt-6 inline-block bg-white p-3 rounded-2xl border border-slate-200 shadow-sm"><img src="data:image/png;base64,{{ $qrCode }}" alt="QR code for {{ $reportLabel }}" class="w-[260px] h-[260px]"></div>
            <p class="max-w-md mx-auto text-xs text-slate-500 mt-3">This QR code is for {{ $reportLabel }}. Scan it to open and download this exact report file.</p>
            <p class="text-sm font-semibold text-slate-700 mt-3">{{ $reportLabel }}</p>
            <p class="text-[11px] text-slate-400 mt-1">QR valid until {{ $expiresAt->timezone('Asia/Manila')->format('F d, Y \a\t h:i A') }}.</p>

            <div class="flex flex-wrap justify-center gap-3 mt-6">
                <a class="btn-primary px-5 py-3 text-sm" href="{{ $downloadUrl }}">Download This File</a>
                <a class="btn-ghost px-5 py-3 text-sm" href="data:image/png;base64,{{ $qrCode }}" download="{{ $type }}-report-{{ $month }}-{{ $format }}-qr.png">Save QR Image</a>
            </div>
        </div>
    @else
        <div class="ledger-card p-7 text-center text-sm text-slate-500">Select all three options above to generate one report file and its QR code.</div>
    @endif
</div>
@endsection
