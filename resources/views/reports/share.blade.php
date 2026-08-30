<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $reportLabel }} | LG Agri-Tourism</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-50 flex items-center justify-center p-5">
    <main class="w-full max-w-lg bg-white rounded-3xl shadow-xl border border-slate-200 p-7 text-center">
        <img src="{{ asset('images/logo.png') }}" alt="LG Agri-Tourism logo" class="w-16 h-16 object-contain mx-auto">
        <p class="text-xs font-bold uppercase tracking-widest text-emerald-700 mt-3">LG Agri-Tourism</p>
        <h1 class="font-display text-2xl font-semibold text-slate-900 mt-2">Shared Report</h1>
        <p class="text-sm font-semibold text-slate-700 mt-4">{{ $reportLabel }}</p>
        <p class="text-sm text-slate-500 mt-3">This QR code is for the report shown above. Tap the button below to download the exact {{ strtoupper($format === 'xlsx' ? 'Excel' : 'PDF') }} file.</p>
        <a href="{{ $downloadUrl }}" class="btn-primary inline-block px-6 py-3 text-sm mt-7">Download {{ strtoupper($format === 'xlsx' ? 'Excel' : 'PDF') }}</a>
        <p class="text-[11px] text-slate-400 mt-6">QR valid until {{ $expiresAt->timezone('Asia/Manila')->format('F d, Y \a\t h:i A') }}.</p>
    </main>
</body>
</html>
