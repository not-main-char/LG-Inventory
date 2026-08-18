<?php

namespace App\Http\Middleware;

use Closure;
use Kreait\Firebase\Contract\Auth as FirebaseAuth;
use Illuminate\Http\Request;

class CheckFirebaseAuth
{
    public function handle(Request $request, Closure $next)
    {
        $idToken = $request->session()->get('firebase_id_token');
        
        if (!$idToken) {
            return redirect()->route('login');
        }

        if ($request->session()->has('firebase_user') && $request->session()->has('firebase_role')) {
            $request->attributes->set('firebase_user', $request->session()->get('firebase_user'));
            $request->attributes->set('firebase_role', $request->session()->get('firebase_role'));
            return $next($request);
        }

        try {
            $auth = app(FirebaseAuth::class);
            $verifiedIdToken = $auth->verifyIdToken($idToken, false, 60);
            
            $uid = $verifiedIdToken->claims()->get('sub');
            $role = $verifiedIdToken->claims()->get('role') ?? 'admin';
            
            // Save to request attributes (for current page load)
            $request->attributes->set('firebase_user', $uid);
            $request->attributes->set('firebase_role', $role);

            // Fetch user details from Firestore to get the display name
            $firestore = app('firebase.firestore')->database();
            $userDoc = $firestore->collection('users')->document($uid)->snapshot();
            $userName = $userDoc->exists() ? ($userDoc->data()['name'] ?? $verifiedIdToken->claims()->get('email')) : $verifiedIdToken->claims()->get('email');
            
            // Save to PHP session (so next clicks bypass Google completely)
            $request->session()->put('firebase_user', $uid);
            $request->session()->put('firebase_role', $role);
            $request->session()->put('firebase_user_name', $userName);

            $request->attributes->set('firebase_user_name', $userName);
            
            return $next($request);
            
        } catch (\Exception $e) {
            $request->session()->forget(['firebase_user', 'firebase_role', 'firebase_id_token']);
            return redirect()->route('login')->withErrors([
                'email' => 'Session expired. Please log in again.'
            ]);
        }
    }
}