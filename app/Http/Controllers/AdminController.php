<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Kreait\Firebase\Contract\Auth as FirebaseAuth;
use Kreait\Firebase\Contract\Firestore;
use Illuminate\Support\Facades\Mail;
use App\Mail\AccountInviteMail;

class AdminController extends Controller
{
    protected $auth;
    protected $firestore;
    
    public function __construct(FirebaseAuth $auth, Firestore $firestore)
    {
        $this->auth = $auth;
        $this->firestore = $firestore->database();
    }
    
    public function store(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'last_name' => 'required|string',
            'first_name' => 'required|string',
            'middle_name' => 'nullable|string',
            'suffix' => 'nullable|string',
            'role' => 'required|in:president,secretary',
        ]);

        $namePieces = array_filter([
            trim($validated['first_name']),
            trim($validated['middle_name'] ?? ''),
            trim($validated['suffix'] ?? ''),
        ]);
        $validated['name'] = trim($validated['last_name']) . ', ' . implode(' ', $namePieces);

        try {
            $randomPassword = bin2hex(random_bytes(16));

            $createdUser = $this->auth->createUser([
                'email' => $validated['email'],
                'emailVerified' => false,
                'password' => $randomPassword,
                'displayName' => $validated['name'],
                'disabled' => false,
            ]);

            $this->auth->setCustomUserClaims($createdUser->uid, ['role' => $validated['role']]);

            $this->firestore->collection('users')->document($createdUser->uid)->set([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'role' => $validated['role'],
                'createdBy' => request()->attributes->get('firebase_user'),
                'createdAt' => new \DateTime(),
            ]);

            // Generate a Firebase-hosted password-reset link and email it via our own mailer.
            $resetLink = $this->auth->getPasswordResetLink($validated['email']);
            
            // Map role to display name
            $roleDisplay = $validated['role'] === 'president' ? 'TVI President' : 'Secretary';
            
            Mail::to($validated['email'])->send(new AccountInviteMail($validated['name'], $roleDisplay, $resetLink));

            $this->firestore->collection('notifications')->newDocument()->set([
                'userId' => 'all',
                'title' => 'Account Created',
                'message' => "{$validated['name']} was added as a {$roleDisplay}. An email invite has been sent.",
                'read' => false,
                'createdAt' => new \DateTime(),
                'type' => 'account_created',
            ]);

            $response = redirect()->route('admin.manage')
                ->with('success', "Account created for {$validated['name']}.");

            // MAIL_MAILER=log (the default) doesn't actually deliver anything — it just
            // writes the email to storage/logs/laravel.log. Surface the link directly
            // on-screen too so account creation isn't blocked on mail being configured.
            if (config('mail.default') === 'log' || config('mail.default') === 'array') {
                $response->with('inviteLink', $resetLink)->with('inviteName', $validated['name']);
            }

            return $response;
        } catch (\Exception $e) {
            return back()->withErrors(['email' => $e->getMessage()]);
        }
    }

    /**
     * Admin-only: list every account (president + secretary) with last login + status.
     */
    public function manage()
    {
        $userDocs = $this->firestore->collection('users')
            ->where('role', 'in', ['president', 'secretary'])
            ->documents();

        $accounts = [];
        foreach ($userDocs as $doc) {
            $data = $doc->data();
            $uid = $doc->id();

            $lastLogin = null;
            $disabled = false;
            try {
                $firebaseUser = $this->auth->getUser($uid);
                $disabled = $firebaseUser->disabled;
                $metadata = $firebaseUser->metadata;
                if ($metadata && $metadata->lastLoginAt) {
                    $lastLogin = $metadata->lastLoginAt;
                }
            } catch (\Exception $e) {
                // user might have been removed directly from Firebase Console
            }

            $accounts[] = [
                'uid' => $uid,
                'name' => $data['name'] ?? 'Unnamed',
                'email' => $data['email'] ?? '—',
                'role' => $data['role'] ?? 'secretary',
                'createdAt' => $data['createdAt'] ?? null,
                'lastLogin' => $lastLogin,
                'disabled' => $disabled,
            ];
        }

        return view('admin.manage', compact('accounts'));
    }

    /**
     * Admin-only: instantly disable or re-enable an account's Firebase login.
     * Disabling blocks future logins immediately (an already-open session
     * still expires naturally within the hour).
     */
    public function toggleDisable(string $uid)
    {
        $userDoc = $this->firestore->collection('users')->document($uid)->snapshot();
        if ($userDoc->exists() && ($userDoc->data()['role'] ?? null) === 'admin') {
            return redirect()->route('admin.manage')->with('success', 'The CAC Manager account can\'t be disabled — the system always needs one.');
        }

        $firebaseUser = $this->auth->getUser($uid);
        if ($firebaseUser->disabled) {
            $this->auth->enableUser($uid);
            $message = 'Account re-enabled.';
        } else {
            $this->auth->disableUser($uid);
            $message = 'Account disabled.';
        }

        return redirect()->route('admin.manage')->with('success', $message);
    }
}
