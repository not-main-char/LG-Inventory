<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LG Agri-Tourism | @yield('title', 'System')</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500;600&display=swap" rel="stylesheet">
</head>
<body class="antialiased">

    @if(!Route::is('login') && !Route::is('setup-profile') && !Route::is('change-password'))
        @php
            $currentRole = session('firebase_role', request()->attributes->get('firebase_role', 'admin'));
            $roleLabels = ['admin' => 'CAC Manager', 'viewer' => 'Viewer'];
            $roleLabel = $roleLabels[$currentRole] ?? ucfirst($currentRole);
        @endphp
        <div class="flex h-screen overflow-hidden">

            <aside id="sidebar" class="sidebar sidebar-collapsed text-white flex flex-col flex-shrink-0 shadow-2xl relative z-30">
                <div class="p-4 flex items-center justify-center border-b border-white/10">
                    <button id="sidebarToggle" class="p-1.5 rounded-lg hover:bg-white/10 transition" title="Toggle sidebar">
                        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M3 12h18M3 18h18"/></svg>
                    </button>
                </div>

                <nav class="flex-1 mt-5 px-3 space-y-1 overflow-y-auto overflow-x-hidden">
                    <a href="{{ route('dashboard') }}" class="sidebar-link flex items-center gap-3 py-2.5 px-4 rounded-r-lg nav-transition {{ Request::is('/') ? 'active' : '' }}">
                        <svg class="nav-ic w-[18px] h-[18px] flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/></svg>
                        <span class="text-sm font-medium sidebar-label whitespace-nowrap">Dashboard</span>
                    </a>

                    <a href="{{ route('inventory.index') }}" class="sidebar-link flex items-center gap-3 py-2.5 px-4 rounded-r-lg nav-transition {{ Request::is('inventory*') ? 'active' : '' }}">
                        <svg class="nav-ic w-[18px] h-[18px] flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 8L12 3 3 8l9 5 9-5z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M12 13v8"/></svg>
                        <span class="text-sm font-medium sidebar-label whitespace-nowrap">Inventory</span>
                    </a>

                    <a href="{{ route('income.index') }}" class="sidebar-link flex items-center gap-3 py-2.5 px-4 rounded-r-lg nav-transition {{ Request::is('income*') ? 'active' : '' }}">
                        <svg class="nav-ic w-[18px] h-[18px] flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3v18h18"/><path d="M7 16l4-4 3 3 5-6"/></svg>
                        <span class="text-sm font-medium sidebar-label whitespace-nowrap">Income Ledger</span>
                    </a>

                    @if($currentRole === 'admin')
                        <div class="pt-4 mt-4 border-t border-white/10">
                            <p class="px-4 text-[10px] font-semibold text-amber-200/80 uppercase tracking-wider mb-2 sidebar-label whitespace-nowrap">Management</p>
                            <a href="{{ route('admin.manage') }}" class="sidebar-link flex items-center gap-3 py-2.5 px-4 rounded-r-lg nav-transition {{ Request::is('manage-admins*') ? 'active' : '' }}">
                                <svg class="nav-ic w-[18px] h-[18px] flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
                                <span class="text-sm font-medium sidebar-label whitespace-nowrap">Manage Accounts</span>
                            </a>

                            <a href="{{ route('archives.index') }}" class="sidebar-link flex items-center gap-3 py-2.5 px-4 rounded-r-lg nav-transition {{ Request::is('archives*') ? 'active' : '' }}">
                                <svg class="nav-ic w-[18px] h-[18px] flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><path d="M17 21v-8H7v8"/><path d="M7 3v5h8"/><path d="M15 15l3 3m0-3l-3 3" stroke-width="2.5"/></svg>
                                <span class="text-sm font-medium sidebar-label whitespace-nowrap">Archives</span>
                            </a>
                        </div>
                    @endif
                </nav>

                <div class="p-4 border-t border-white/10 overflow-hidden">
                    <div class="px-2 mb-3 sidebar-label whitespace-nowrap">
                        <span class="block text-[10px] uppercase tracking-widest text-emerald-200/60">Signed in as</span>
                        <span class="block text-sm font-semibold">{{ session('firebase_user_name', 'User') }}</span>
                        <span class="block text-xs text-gray-400">{{ $roleLabel }}</span>
                    </div>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="w-full text-white font-semibold py-2.5 px-4 rounded-xl shadow-md transition flex items-center justify-center gap-2 cursor-pointer" style="background:var(--color-rust-600)" title="Log out">
                            <svg class="w-4 h-4 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/></svg>
                            <span class="text-sm sidebar-label whitespace-nowrap">Log out</span>
                        </button>
                    </form>
                </div>
            </aside>

            <!-- MAIN -->
            <div class="flex-1 flex flex-col min-w-0">
                <header class="bg-white/90 backdrop-blur border-b border-[#ECE5CE] px-6 py-4 flex justify-between items-center sticky top-0 z-20">
                    <div class="flex items-center gap-3 min-w-0">
                        <img src="{{ asset('images/logo.png') }}" alt="LG Agri-Tourism" class="h-9 w-9 object-contain rounded-lg flex-shrink-0">
                        <div class="leading-tight whitespace-nowrap hidden sm:block">
                            <h1 class="font-display text-sm font-semibold" style="color:var(--color-ink-900)">LG Agri-Tourism</h1>
                            <p class="text-[10px] text-gray-400 tracking-wide">Inventory Management</p>
                        </div>
                        <span class="w-px h-8 bg-[#ECE5CE] mx-1 hidden sm:block"></span>
                        <h2 class="font-display text-lg font-semibold truncate" style="color:var(--color-ink-900)">@yield('title')</h2>
                    </div>

                    <div class="flex items-center gap-4">
                        <span class="hidden sm:inline-block text-xs font-semibold uppercase tracking-wide px-3 py-1 rounded-full border" style="color:var(--color-forest-700); background:var(--color-moss-100); border-color:var(--color-moss-300)">
                            {{ $roleLabel }}
                        </span>

                        <div class="relative">
                            <button id="notificationBell" class="p-2.5 rounded-xl hover:bg-[#EFE9D8] transition relative" style="color:var(--color-forest-700)">
                                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 8a6 6 0 1112 0c0 4 1.5 5.5 1.5 6.5H4.5C4.5 13.5 6 12 6 8z"/><path d="M9.5 18a2.5 2.5 0 005 0"/></svg>
                                <span id="notifBadge" class="absolute top-1 right-1 text-white font-bold text-[10px] rounded-full h-4 min-w-4 px-1 flex items-center justify-center hidden" style="background:var(--color-rust-600)">0</span>
                            </button>

                            <div id="notifPanel" class="hidden absolute right-0 mt-2 w-80 notif-panel z-30 overflow-hidden">
                                <div class="px-4 py-3 flex items-center justify-between border-b border-[#F1ECDC]">
                                    <span class="font-display font-semibold text-sm" style="color:var(--color-ink-900)">Notifications</span>
                                    <span id="notifCountLabel" class="text-xs text-gray-400">0 new</span>
                                </div>
                                <div id="notifList" class="max-h-80 overflow-y-auto divide-y divide-[#F1ECDC]">
                                    <p class="text-sm text-gray-400 px-4 py-6 text-center">Loading…</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </header>

                <div class="flex-1 overflow-y-auto p-6 md:p-8">
                    @if(session('success'))
                        <div class="bg-white border-l-4 p-4 mb-6 rounded-xl shadow-sm flex items-center gap-3" style="border-color:var(--color-forest-600)" role="alert">
                            <span style="color:var(--color-forest-600)">●</span>
                            <div class="text-sm font-medium" style="color:var(--color-forest-800)">{{ session('success') }}</div>
                        </div>
                    @endif

                    @if($errors->any())
                        <div class="bg-white border-l-4 p-4 mb-6 rounded-xl shadow-sm" style="border-color:var(--color-rust-600)">
                            <div class="flex items-center gap-3 mb-2">
                                <span style="color:var(--color-rust-600)">●</span>
                                <div class="text-sm font-bold" style="color:var(--color-rust-600)">Please check the input fields</div>
                            </div>
                            <ul class="list-disc list-inside text-xs pl-5 space-y-0.5" style="color:#7a3328">
                                @foreach($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @yield('content')
                </div>
            </div>
        </div>

    @else
        <div class="min-h-screen">
            @yield('content')
        </div>
    @endif

    <script>
        function timeColor(type) {
            return { low_stock: '#AE4332', harvest: '#C2862C', account_created: '#29677D' }[type] || '#356B45';
        }

        async function loadNotifications() {
            const badge = document.getElementById('notifBadge');
            if (!badge) return;
            try {
                const res = await fetch('/notifications');
                if (res.redirected) return;
                const notifs = await res.json();
                const unread = notifs.filter(n => !n.read);

                if (unread.length > 0) {
                    badge.innerText = unread.length;
                    badge.classList.remove('hidden');
                } else {
                    badge.classList.add('hidden');
                }

                const list = document.getElementById('notifList');
                const countLabel = document.getElementById('notifCountLabel');
                if (countLabel) countLabel.innerText = unread.length + ' new';

                if (list) {
                    if (notifs.length === 0) {
                        list.innerHTML = '<p class="text-sm text-gray-400 px-4 py-6 text-center">No notifications yet.</p>';
                    } else {
                        list.innerHTML = notifs.map(n => `
                            <div class="notif-item px-4 py-3 flex gap-3 ${n.read ? 'opacity-60' : ''} cursor-pointer hover:bg-[#FBF9F1]" onclick="markNotifRead('${n.id}')">
                                <span class="notif-dot mt-1.5 flex-shrink-0" style="background:${timeColor(n.type)}"></span>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-semibold text-gray-800">${n.title}</p>
                                    <p class="text-xs text-gray-500 mt-0.5">${n.message}</p>
                                    <p class="text-[11px] text-gray-400 mt-1">${n.createdAt}</p>
                                </div>
                            </div>
                        `).join('');
                    }
                }
            } catch (e) {
                console.log('Notification processing error', e);
                const list = document.getElementById('notifList');
                if (list) {
                    list.innerHTML = '<p class="text-sm text-red-400 px-4 py-6 text-center">Couldn\'t load notifications.</p>';
                list.onclick = () => loadNotifications();
                }
            }
        }

        async function markNotifRead(id) {
            await fetch(`/notifications/${id}/read`, { method: 'POST', headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' } });
            loadNotifications();
        }

        if (document.getElementById('notifBadge')) {
            const bell = document.getElementById('notificationBell');
            const panel = document.getElementById('notifPanel');

            bell?.addEventListener('click', (e) => {
                e.stopPropagation();
                panel.classList.toggle('hidden');
                loadNotifications();
            });
            document.addEventListener('click', (e) => {
                if (panel && !panel.contains(e.target) && e.target !== bell) panel.classList.add('hidden');
            });

            loadNotifications();
            let pollTimer = setInterval(loadNotifications, 60000);

            // Don't keep polling while the tab is in the background — cuts needless
            // requests competing with real navigation on a single-threaded dev server.
            document.addEventListener('visibilitychange', () => {
                if (document.hidden) {
                    clearInterval(pollTimer);
                } else {
                    loadNotifications();
                    pollTimer = setInterval(loadNotifications, 60000);
                }
            });
        }

        // ---- Sidebar: collapsed icon-rail, expands on hover, or pinned open via hamburger ----
        const sidebarEl = document.getElementById('sidebar');
        const sidebarToggleBtn = document.getElementById('sidebarToggle');
        if (sidebarEl && sidebarToggleBtn) {
            const pinned = localStorage.getItem('lg_sidebar_pinned') === 'true';
            if (pinned) {
                sidebarEl.classList.remove('sidebar-collapsed');
                sidebarEl.classList.add('sidebar-pinned');
            }

            sidebarToggleBtn.addEventListener('click', () => {
                const nowPinned = !sidebarEl.classList.contains('sidebar-pinned');
                sidebarEl.classList.toggle('sidebar-pinned', nowPinned);
                sidebarEl.classList.toggle('sidebar-collapsed', !nowPinned);
                localStorage.setItem('lg_sidebar_pinned', nowPinned ? 'true' : 'false');
            });
        }
    </script>
    @stack('scripts')
</body>
</html>
