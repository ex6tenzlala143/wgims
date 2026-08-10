<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function showLogin()
    {
        if (Auth::check()) return redirect()->route('dashboard');
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        // Manual rate limiting: max 10 attempts per minute per username+IP
        $throttleKey = Str::lower($request->username).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 10)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            return back()
                ->withErrors(['username' => "Too many login attempts. Please try again in {$seconds} seconds."])
                ->withInput($request->only('username'));
        }

        $credentials = [
            'username'  => $request->username,
            'password'  => $request->password,
            'is_active' => true,
        ];

        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::clear($throttleKey);

            // Pull the intended URL BEFORE regenerating the session — regenerate()
            // writes a new session ID and the old data (including url.intended) would
            // otherwise be lost on the very next read.
            $intended  = $request->session()->pull('url.intended');
            $dashboard = route('dashboard');

            $request->session()->regenerate();

            if ($intended) {
                $user = Auth::user();

                // Routes are already middleware-protected, so any 403 will be caught
                // if the user tries to access something they can't. The only thing to
                // prevent here is bouncing a non-admin back into an admin-only URL
                // they happened to visit before their session expired, which would
                // just give them an immediate 403. Send them to the dashboard instead.
                if (! $user->hasAdminAccess()) {
                    return redirect($dashboard);
                }

                return redirect($intended);
            }

            return redirect($dashboard);
        }

        RateLimiter::hit($throttleKey, 60); // decay 60 seconds

        return back()
            ->withErrors(['username' => 'Invalid credentials or account is inactive.'])
            ->withInput($request->only('username'));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('login');
    }
}
