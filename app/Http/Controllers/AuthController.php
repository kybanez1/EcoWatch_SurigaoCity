<?php

namespace App\Http\Controllers;

use App\Models\Barangay;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function showLogin()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|string|email|max:150',
            'password' => 'required|string|max:255',
        ], [
            'email.string' => 'The email must be plain text.',
            'password.string' => 'The password must be plain text.',
        ]);

        // Stop password guessing: 5 tries per email and IP address, then wait one minute.
        $limitKey = strtolower($data['email']) . '|' . $request->ip();
        if (RateLimiter::tooManyAttempts($limitKey, 5)) {
            $seconds = RateLimiter::availableIn($limitKey);
            return back()->withErrors(['email' => 'Too many login attempts. Please try again in ' . $seconds . ' seconds.'])->onlyInput('email');
        }

        // Only active accounts can log in.
        $credentials = ['email' => $data['email'], 'password' => $data['password'], 'is_active' => true];
        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($limitKey, 60);

            // Tell the owner of a deactivated account why the login fails (only when the password is right).
            $user = User::where('email', $data['email'])->first();
            if ($user && ! $user->is_active && Hash::check($data['password'], $user->password)) {
                return back()->withErrors(['email' => 'This account is deactivated. Please contact the CGSO.'])->onlyInput('email');
            }
            return back()->withErrors(['email' => 'These credentials do not match our records.'])->onlyInput('email');
        }

        RateLimiter::clear($limitKey);
        $request->session()->regenerate();

        /** @var \App\Models\User $user */
        $user = Auth::user();
        return redirect($this->landingUrl($request, $user));
    }

    /**
     * Where to go after login. The page the user first asked for is used only when this role may open it.
     * A resident who was sent to login from a CGSO page lands on the resident dashboard instead of a 403 page.
     */
    private function landingUrl(Request $request, User $user)
    {
        $intended = $request->session()->pull('url.intended');
        if (! is_string($intended) || $intended === '') {
            return $user->homeRoute();
        }

        try {
            $matched = app('router')->getRoutes()->match(Request::create($intended, 'GET'));
        } catch (\Exception $error) {
            return $user->homeRoute();
        }

        // Look at the "role:..." middleware of that page.
        foreach ($matched->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'role:')) {
                $allowedRoles = explode(',', substr($middleware, 5));
                if (! in_array($user->role, $allowedRoles, true)) {
                    return $user->homeRoute();
                }
            }
        }
        return $intended;
    }

    public function showRegister()
    {
        return view('auth.register', ['barangays' => Barangay::orderBy('name')->get()]);
    }

    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email|max:150|unique:users,email',
            'password' => 'required|string|min:8|max:255|confirmed',
            'barangay_id' => 'required|exists:barangays,id',
            'phone' => 'nullable|string|max:30',
        ]);
        $data['role'] = 'resident';
        $user = User::create($data);

        Auth::login($user);
        $request->session()->regenerate();
        return redirect($user->homeRoute())->with('success', 'Welcome to EcoWatch!');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('home');
    }

    // ---------- Forgot password (Laravel's built-in password broker) ----------

    public function showForgotPassword()
    {
        return view('auth.forgot-password');
    }

    public function sendResetLink(Request $request)
    {
        $data = $request->validate(['email' => 'required|string|email|max:150'], ['email.string' => 'The email must be plain text.']);

        // The same message is shown whether or not the email exists, so nobody can test which emails are registered.
        // Laravel's broker also limits how often a link can be requested.
        Password::sendResetLink(['email' => $data['email'], 'is_active' => true]);

        return back()->with('success', 'If that email is registered, a password reset link has been sent.');
    }

    public function showResetPassword(Request $request, $token)
    {
        // The email in the link must be plain text (?email[]=x would break the form).
        $email = $request->query('email');
        if (! is_string($email)) {
            $email = '';
        }
        return view('auth.reset-password', ['token' => $token, 'email' => $email]);
    }

    public function resetPassword(Request $request)
    {
        $data = $request->validate([
            'token' => 'required|string',
            'email' => 'required|string|email|max:150',
            'password' => 'required|string|min:8|max:255|confirmed',
        ], ['email.string' => 'The email must be plain text.']);

        $status = Password::reset(
            ['email' => $data['email'], 'password' => $data['password'], 'password_confirmation' => $request->input('password_confirmation'), 'token' => $data['token'], 'is_active' => true],
            function (User $user, string $password) {
                $user->password = $password;
                $user->must_change_password = false;
                $user->setRememberToken(Str::random(60));
                $user->save();
                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return redirect()->route('login')->with('success', 'Your password was changed. You can log in now.');
        }
        return back()->withErrors(['email' => 'This reset link is invalid or has expired. Please request a new one.'])->onlyInput('email');
    }
}
