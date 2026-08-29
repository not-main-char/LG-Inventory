<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Shared Report</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-50 flex items-center justify-center p-5">
    <main class="w-full max-w-lg bg-white rounded-3xl shadow-xl border border-slate-200 p-7 text-center">
        <p class="text-xs font-bold uppercase tracking-widest text-emerald-700">LG Agri-Tourism</p>
        <h1 class="font-display text-2xl font-semibold text-slate-900 mt-2">Shared Report</h1>
        <p class="text-sm font-semibold text-slate-700 mt-4">{{ $reportLabel }}</p>
        <p class="text-sm text-slate-500 mt-2">This QR code contains one specific report file.</p>
        <a href="{{ $downloadUrl }}" class="btn-primary inline-block px-6 py-3 text-sm mt-7">Download {{ strtoupper($format === 'xlsx' ? 'Excel' : 'PDF') }}</a>
        <p class="text-[11px] text-slate-400 mt-6">This shared QR link expires in 7 days.</p>
    </main>
</body>
</html>
