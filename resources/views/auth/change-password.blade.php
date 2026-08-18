@extends('layouts.app', ['title' => 'Set New Password'])
@section('content')
<div class="min-h-screen flex items-center justify-center p-4" style="background: linear-gradient(180deg, var(--color-forest-900), var(--color-forest-800));">
    <div class="bg-white rounded-2xl login-card-shadow p-8 w-full max-w-md">
        <div class="text-center mb-6">
            <img src="{{ asset('images/logo.png') }}" alt="LG Agri-Tourism" class="h-14 w-14 object-contain mx-auto mb-3">
            <h1 class="font-display text-2xl font-semibold text-slate-800">Set Your New Password</h1>
            <p class="text-sm text-slate-500 mt-1">This is your first login. Choose a password only you know before continuing.</p>
        </div>

        @if(session('success'))
            <div class="bg-emerald-50 border-l-4 p-3 mb-4 rounded-lg text-sm" style="border-color:var(--color-forest-600); color:var(--color-forest-700)">{{ session('success') }}</div>
        @endif
        @if($errors->any())
            <div class="bg-rose-50 border-l-4 p-3 mb-4 rounded-lg text-sm" style="border-color:var(--color-rust-600); color:var(--color-rust-600)">
                @foreach($errors->all() as $error) <p>{{ $error }}</p> @endforeach
            </div>
        @endif

        <form method="POST" action="{{ route('change-password') }}" class="space-y-4">
            @csrf
            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">New Password</label>
                <input type="password" name="password" required minlength="6" class="input-field" placeholder="Min. 6 characters">
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">Confirm New Password</label>
                <input type="password" name="password_confirmation" required minlength="6" class="input-field" placeholder="Re-type the password">
            </div>
            <button type="submit" class="btn-primary w-full py-3 text-sm">Save Password &amp; Continue</button>
        </form>
    </div>
</div>
@endsection
