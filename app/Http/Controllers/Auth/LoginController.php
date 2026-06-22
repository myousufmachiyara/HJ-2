<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\AuthenticatesUsers;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use App\Models\User;

class LoginController extends Controller
{
    use AuthenticatesUsers;

    /**
     * Where to redirect users after login.
     *
     * @var string
     */
    protected $redirectTo = '/';

    public function __construct()
    {
        $this->middleware('guest')->except('logout');
        $this->middleware('auth')->only('logout');
    }

    public function username()
    {
        return 'username'; // 👤 use username instead of email
    }

    /**
     * Attempt to log the user into the application.
     */
    protected function attemptLogin(Request $request)
    {
        if ($this->hasTooManyLoginAttempts($request)) {
            $this->fireLockoutEvent($request);
            return $this->sendLockoutResponse($request);
        }

        // Add 'is_active' => 1 to credentials to only allow active users to login
        $credentials = $this->credentials($request);
        $credentials['is_active'] = 1;

        $login = $this->guard()->attempt(
            $credentials,
            $request->filled('remember')
        );

        if (! $login) {
            $this->incrementLoginAttempts($request);
        } else {
            $this->clearLoginAttempts($request);
        }

        return $login;
    }

    /**
     * Define login throttling rules.
     */
    protected function hasTooManyLoginAttempts(Request $request)
    {
        // Allow 4 attempts within 1 minute
        return $this->limiter()->tooManyAttempts(
            $this->throttleKey($request), 4, 1
        );
    }

    /**
     * Response when user is locked out.
     */
    protected function sendLockoutResponse(Request $request)
    {
        $seconds = $this->limiter()->availableIn(
            $this->throttleKey($request)
        );

        \Log::warning('🚫 User locked out due to too many login attempts.', [
            'username' => $request->input($this->username()),
            'ip'       => $request->ip(),
            'wait'     => $seconds . 's',
        ]);

        throw ValidationException::withMessages([
            $this->username() => [trans('auth.throttle', ['seconds' => $seconds])],
        ]);
    }

    /**
     * Generic failed login response.
     */
    protected function sendFailedLoginResponse(Request $request)
    {
        $user = User::where($this->username(), $request->input($this->username()))->first();

        if ($user && !\Hash::check($request->input('password'), $user->password)) {
            // Password is incorrect, show generic message
            throw ValidationException::withMessages([
                $this->username() => [__('auth.failed')],
            ]);
        }

        if ($user && $user->is_active == 0) {
            // Account is deactivated
            throw ValidationException::withMessages([
                $this->username() => ['Your account is deactivated. Please contact the Administrator.'],
            ]);
        }

        // Generic fallback message
        throw ValidationException::withMessages([
            $this->username() => [__('auth.failed')],
        ]);
    }

}
