@extends('layouts.app', ['title' => 'Manage Accounts'])
@section('content')

<div class="flex items-center justify-between mb-4">
    <h3 class="font-display text-base font-semibold" style="color:var(--color-ink-900)">Accounts</h3>
    <button onclick="openCreateModal()" class="btn-primary px-4 py-2.5 text-sm flex items-center gap-2">
        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 5v14M5 12h14"/></svg>
        Create New Account
    </button>
</div>


@if(session('inviteLink'))
    <div class="bg-white border-l-4 p-4 mb-6 rounded-xl shadow-sm" style="border-color:var(--color-amber-600)">
        <p class="text-sm font-semibold mb-1" style="color:var(--color-amber-600)">Email could not be confirmed as sent — here's the setup link as a backup</p>
        <p class="text-xs text-gray-500 mb-2">If your mail isn't configured yet (check <code>.env</code> → <code>MAIL_MAILER</code>), share this link with {{ session('inviteName') }} directly. It only lets them set their own password — it does not reveal it to you.</p>
        <div class="flex items-center gap-2">
            <input type="text" readonly value="{{ session('inviteLink') }}" class="input-field text-xs" onclick="this.select()">
            <button onclick="navigator.clipboard.writeText('{{ session('inviteLink') }}')" class="btn-ghost px-3 py-2 text-xs whitespace-nowrap">Copy</button>
        </div>
    </div>
@endif

<div class="ledger-card overflow-hidden">
    <div class="overflow-x-auto">
    <table class="min-w-full ledger-table">
        <thead>
            <tr>
                <th class="p-3 text-left">Name</th>
                <th class="p-3 text-left">Email</th>
                <th class="p-3 text-left">Role</th>
                <th class="p-3 text-left">Last Login</th>
                <th class="p-3 text-left">Status</th>
                <th class="p-3 text-left">Actions</th>
            </tr>
        </thead>
        <tbody>
            @foreach($accounts as $account)
            <tr class="border-b border-[#F1ECDC]">
                <td class="p-3 align-middle text-sm font-medium text-gray-800">{{ $account['name'] }}</td>
                <td class="p-3 align-middle text-sm text-gray-600">{{ $account['email'] }}</td>
                <td class="p-3 align-middle">
                    @php
                        $roleDisplay = $account['role'] === 'president' ? 'TVI President' : 'Secretary';
                        $stampClass = $account['role'] === 'president' ? 'stamp-fish' : 'stamp-supplies';
                    @endphp
                    <span class="stamp {{ $stampClass }}">{{ $roleDisplay }}</span>
                </td>
                <td class="p-3 align-middle text-sm text-gray-600">
                    @if($account['lastLogin'])
                        {{ \Carbon\Carbon::parse($account['lastLogin'])->setTimezone('Asia/Manila')->format('M d, Y h:i A') }}
                    @else
                        <span class="text-gray-400 italic">Never logged in</span>
                    @endif
                </td>
                <td class="p-3 align-middle">
                    @if($account['disabled'])
                        <span class="text-[11px] font-bold uppercase tracking-wide px-2 py-1 rounded" style="background:var(--color-rust-100); color:var(--color-rust-600)">Disabled</span>
                    @else
                        <span class="text-[11px] font-bold uppercase tracking-wide px-2 py-1 rounded" style="background:var(--color-moss-100); color:var(--color-forest-700)">Active</span>
                    @endif
                </td>
                <td class="p-3 align-middle">
                    @if($account['role'] === 'admin')
                        <span class="text-xs text-gray-400 italic">Primary account</span>
                    @else
                        <form method="POST" action="{{ route('admin.toggle', $account['uid']) }}" onsubmit="return confirm('{{ $account['disabled'] ? 'Re-enable' : 'Disable' }} this account?')">
                            @csrf
                            <button type="submit" class="text-sm font-medium hover:underline" style="color: {{ $account['disabled'] ? 'var(--color-forest-700)' : 'var(--color-rust-600)' }}">
                                {{ $account['disabled'] ? 'Re-enable' : 'Disable' }}
                            </button>
                        </form>
                    @endif
                </td>
            </tr>
            @endforeach
            @if(count($accounts) === 0)
            <tr><td colspan="6" class="p-8 text-center text-sm text-gray-400">No accounts created yet.</td></tr>
            @endif
        </tbody>
    </table>
    </div>
</div>

<div id="createAccountModal" class="fixed inset-0 modal-backdrop hidden items-center justify-center z-50 p-4">
    <div class="modal-panel p-6 w-full max-w-md">
        <h2 class="font-display text-xl font-semibold mb-1" style="color:var(--color-ink-900)">Create New Account</h2>
        <p class="text-xs text-gray-500 mb-4">Enter the new account's details below. An email will be sent to them with a link to set their password.</p>
        <form method="POST" action="{{ route('create-admin') }}" class="space-y-3">
            @csrf
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Last Name</label>
                    <input type="text" name="last_name" required class="input-field" placeholder="Dela Cruz">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">First Name</label>
                    <input type="text" name="first_name" required class="input-field" placeholder="Juan">
                </div>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Middle Name <span class="font-normal text-gray-400">(optional)</span></label>
                    <input type="text" name="middle_name" class="input-field" placeholder="Santos">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Suffix <span class="font-normal text-gray-400">(optional)</span></label>
                    <input type="text" name="suffix" class="input-field" placeholder="Jr., Sr., III">
                </div>
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">Email Address</label>
                <input type="email" name="email" required class="input-field" placeholder="name@lgagri.com">
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">Role</label>
                <select name="role" class="input-field" required>
                    <option value="">-- Select Role --</option>
                    <option value="president">TVI President</option>
                    <option value="secretary">Secretary</option>
                </select>
            </div>
            <div class="pt-2 flex gap-2">
                <button type="submit" class="btn-primary w-full py-2.5 text-sm">Send Account Invite</button>
                <button type="button" onclick="closeCreateModal()" class="btn-ghost w-full py-2.5 text-sm">Cancel</button>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
    function openCreateModal() {
        const m = document.getElementById('createAccountModal');
        m.classList.remove('hidden'); m.classList.add('flex');
    }
    function closeCreateModal() {
        const m = document.getElementById('createAccountModal');
        m.classList.add('hidden'); m.classList.remove('flex');
    }
</script>
@endpush
