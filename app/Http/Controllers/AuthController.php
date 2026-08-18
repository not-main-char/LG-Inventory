<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Kreait\Firebase\Contract\Auth as FirebaseAuth;
use Kreait\Firebase\Contract\Firestore;
use Exception;

class AuthController extends Controller
{
    /**
     * Show the login form layout.
     */
    public function showLogin()
    {
        return view('auth.login');
    }

    public function sendPasswordResetLink(Request $request, FirebaseAuth $firebaseAuth)
    {
        $request->validate(['email' => 'required|email']);
        try {
            $firebaseAuth->sendPasswordResetLink($request->email);
            return response()->json(['success' => true, 'message' => 'Password reset link sent to your email.']);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error: ' . $e->getMessage()], 400);
        }
    }
    
    public function login(Request $request, FirebaseAuth $firebaseAuth, Firestore $firestore)
    {
        // Validate user inputs from the login card form
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        try {
            //Verify credentials against Firebase Authentication servers
            $signInResult = $firebaseAuth->signInWithEmailAndPassword(
                $credentials['email'], 
                $credentials['password']
            );

            $idToken = $signInResult->idToken();
            $firebaseUid = $signInResult->firebaseUserId();

            //Look up the user's assigned permission role inside Firestore database
            $userDoc = $firestore->database()
                ->collection('users')
                ->document($firebaseUid)
                ->snapshot();

            // Default to 'admin' if document doesn't exist or doesn't have a role set yet
            $role = 'admin'; 
            $mustChangePassword = false;
            if ($userDoc->exists()) {
                $userData = $userDoc->data();
                if (isset($userData['role'])) {
                    $role = $userData['role'];
                }
                $mustChangePassword = $userData['mustChangePassword'] ?? false;
            }

            //Save everything to Laravel Session storage memory maps
            session([
                'firebase_id_token' => $idToken,
                'firebase_uid'      => $firebaseUid,
                'firebase_role'     => $role, // Used by layouts/app.blade.php sidebar check!
                'email'             => $credentials['email'],
                'must_change_password' => $mustChangePassword,
            ]);

            $request->attributes->set('firebase_role', $role);
            $request->attributes->set('firebase_user', $firebaseUid);

            if ($mustChangePassword) {
                return redirect()->route('change-password')->with('success', 'Welcome! Please set a new password to continue.');
            }

            //Redirect user to the main entry point dashboard cleanly
            return redirect()->route('dashboard')->with('success', 'Logged in successfully! Welcome to LG Agri-Tourism.');

        } catch (Exception $e) {
            // If Firebase rejects the login, send back a clean error text message
            return back()->withInput()->withErrors([
                'email' => 'Authentication Error: ' . $e->getMessage(),
            ]);
        }
    }

    public function showChangePassword()
    {
        return view('auth.change-password');
    }

    public function updatePassword(Request $request, FirebaseAuth $firebaseAuth, Firestore $firestore)
    {
        $validated = $request->validate([
            'password' => 'required|min:6|confirmed',
        ]);

        $uid = session('firebase_uid');

        try {
            $firebaseAuth->changeUserPassword($uid, $validated['password']);

            $firestore->database()->collection('users')->document($uid)->set([
                'mustChangePassword' => false,
            ], ['merge' => true]);

            session(['must_change_password' => false]);

            return redirect()->route('dashboard')->with('success', 'Password updated. Welcome to LG Agri-Tourism!');
        } catch (Exception $e) {
            return back()->withErrors(['password' => 'Could not update password: ' . $e->getMessage()]);
        }
    }

    public function logout()
    {
        session()->forget(['firebase_id_token', 'firebase_uid', 'firebase_role', 'email']);
        session()->flush();

        return redirect()->route('login')->with('success', 'You have been securely logged out.');
    }

    public function showSetup()
    {
        return view('auth.setup');
    }

    public function completeSetup(Request $request)
    {
        return redirect()->route('dashboard');
    }
}