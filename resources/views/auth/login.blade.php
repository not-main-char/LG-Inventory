@extends('layouts.app', ['title' => 'Secure Portal Login'])

@section('content')
<div class="min-h-screen w-full flex items-center justify-center bg-cover bg-center bg-no-repeat px-4 relative" style="background-image: url('{{ asset('images/background.jpg') }}');">
    
    <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-[2px]"></div>

    <div class="w-full max-w-md bg-white rounded-3xl login-card-shadow border border-slate-200 p-8 sm:p-10 relative z-10">
        
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center mb-4">
                <img src="{{ asset('images/logo.png') }}" alt="LG Agri-Tourism Logo" class="h-24 w-auto object-contain">
            </div>
            <h1 class="font-display text-2xl sm:text-3xl font-semibold text-slate-800 tracking-tight">LG Agri-Tourism</h1>
            <p class="text-sm text-slate-500 font-medium mt-1">Inventory &amp; Income Management</p>
        </div>

        @if($errors->any())
            <div class="bg-rose-50 border-l-4 border-rose-500 text-rose-800 p-4 mb-5 rounded-xl text-sm shadow-sm animate-fade-in">
                <div class="font-bold flex items-center mb-1">
                    <span class="text-base">⚠️</span>
                    <span class="ml-2">Authentication Alert</span>
                </div>
                <ul class="list-disc list-inside text-xs space-y-0.5 text-rose-700/90 pl-1">
                    @foreach($errors->all() as $error)
                        @php
                            $cleanError = $error;
                            if (str_contains($error, 'INVALID_LOGIN_CREDENTIALS') || str_contains($error, 'invalid')) {
                                $cleanError = 'The email address or password you entered is incorrect.';
                            }
                        @endphp
                        <li>{{ $cleanError }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
        <!-- Form -->
        <form method="POST" action="{{ route('login') }}" class="space-y-5">
            @csrf
            
            <div>
                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-2">Email Address</label>
                <input type="email" name="email" value="{{ old('email') }}" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-slate-900 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-emerald-500 transition font-medium text-sm" placeholder="username@lgagri.com" required autocomplete="email">
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider">Password</label>
                
                <div class="relative">
                    <input id="loginPassword" type="password" name="password" class="w-full px-4 py-3 pr-20 bg-slate-50 border border-slate-200 rounded-xl text-slate-900 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-emerald-500 transition font-medium text-sm" placeholder="••••••••" required autocomplete="current-password">
                    <button type="button" id="togglePassword" class="absolute right-3 top-1/2 -translate-y-1/2 text-xs font-semibold text-emerald-700 hover:text-emerald-900" aria-controls="loginPassword" aria-pressed="false">Show</button>
                </div>
                <div class="text-right mt-2">
                    <a href="#" id="forgotPassword" class="text-xs font-semibold text-emerald-600 hover:text-emerald-700 transition">Forgot password?</a>
            </div>

            <div class="pt-2">
                <button type="submit" class="w-full text-white font-bold py-4 px-4 rounded-xl shadow-lg transition-all duration-200 flex items-center justify-center space-x-2" style="background:var(--color-forest-700)">
                    <span>Log In</span>
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
                </button>
            </div>
        </form>

    </div>
</div>

<script>
    document.getElementById('togglePassword').addEventListener('click', function() {
        const password = document.getElementById('loginPassword');
        const isHidden = password.type === 'password';
        password.type = isHidden ? 'text' : 'password';
        this.textContent = isHidden ? 'Hide' : 'Show';
        this.setAttribute('aria-pressed', String(isHidden));
    });

    document.getElementById('forgotPassword').addEventListener('click', async function(e) {
        e.preventDefault();
        const email = document.querySelector('input[name="email"]').value;
        if (!email) {
            alert('Please enter your email address first.');
            return;
        }

        if (!confirm(`Send password reset link to ${email}?`)) return;

        try {
            const response = await fetch('{{ route("password.email") }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                body: JSON.stringify({ email: email })
            });
            const result = await response.json();
            alert(result.message);
        } catch (error) {
            alert('An error occurred. Please try again.');
        }
    });
</script>
@endsection