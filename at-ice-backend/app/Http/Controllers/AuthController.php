<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\LoginLockout;
use App\Support\SessionIdle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    // Login with progressive account/IP lockout while retaining the timing
    // protection and deactivated-account guard below.
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $ip = (string) $request->ip();
        if ($seconds = LoginLockout::secondsLeft($request->email, $ip)) {
            return $this->lockedResponse($seconds);
        }

        // Attempt login
        $user = User::where('email', $request->email)->first();

        // Unknown email still pays for one hash check, so response time does
        // not reveal which emails have an account.
        if ($user) {
            $passwordOk = Hash::check($request->password, $user->password);
        } else {
            Hash::make($request->password);
            $passwordOk = false;
        }

        if (! $user || ! $passwordOk) {
            if ($seconds = LoginLockout::recordFailure($request->email, $ip)) {
                return $this->lockedResponse($seconds);
            }

            return response()->json(['message' => 'Invalid credentials.'], 401);
        }

        // A valid sign-in resets the lockout history for this email/IP pair.
        LoginLockout::clear($request->email, $ip);

        // Block deactivated accounts (a disabled login, or an employee
        // deactivated by HR; see User::isDeactivated). Checked only after the
        // password is correct, so this message does not reveal which emails
        // exist.
        if ($user->isDeactivated()) {
            return response()->json([
                'message' => 'This account has been deactivated. Please contact HR or the Admin.',
            ], 403);
        }

        $newToken = $user->createToken('auth_token');
        // Only the sign-ins that have an idle limit are tracked; nothing is kept
        // for the employees on their phones.
        if (SessionIdle::minutesFor($user)) {
            SessionIdle::touch($newToken->accessToken);
        }

        return response()->json([
            'token' => $newToken->plainTextToken,
            // minutes of inactivity before sign-out; null = no limit for this role
            'idle_minutes' => SessionIdle::minutesFor($user),
            'role' => $user->role,
            'sub_role' => $user->sub_role ?? null,
            'must_change_password' => (bool) $user->must_change_password,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ]);
    }

    private function lockedResponse(int $seconds)
    {
        $minutes = (int) ceil($seconds / 60);

        return response()->json([
            'message' => "Too many login attempts. Please try again in {$minutes} minute(s).",
            'retry_after_seconds' => $seconds,
            'locked_until' => now()->addSeconds($seconds)->toIso8601String(),
        ], 429)->header('Retry-After', (string) $seconds);
    }

    // LOGOUT
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }

    // GET CURRENT USER
    public function me(Request $request)
    {
        return response()->json($request->user());
    }

    // CHANGE PASSWORD (any logged-in role: admin, hr, employee, driver, customer)
    // Requires the current password, and signs out every OTHER device/session
    // so a leaked initial password stops working right after it is changed.
    public function changePassword(Request $request)
    {
        $user = $request->user();

        // On the forced first-login change the one-time password was already
        // checked at login, so the form does not ask for it again.
        $firstLogin = (bool) $user->must_change_password;

        $validated = $request->validate([
            'current_password' => $firstLogin ? 'nullable|string' : 'required|string',
            // At least 8 characters with uppercase, lowercase, a number and a special character.
            'password' => ['required', 'string', 'max:100', 'confirmed', 'different:current_password',
                Password::min(8)->mixedCase()->numbers()->symbols()],
        ]);

        if ($firstLogin) {
            if (Hash::check($validated['password'], $user->password)) {
                return response()->json([
                    'message' => 'Choose a password different from your one-time password.',
                    'errors' => ['password' => ['Choose a password different from your one-time password.']],
                ], 422);
            }
        } elseif (! Hash::check($validated['current_password'], $user->password)) {
            return response()->json([
                'message' => 'Your current password is incorrect.',
                'errors' => ['current_password' => ['Your current password is incorrect.']],
            ], 422);
        }

        $user->update([
            'password' => Hash::make($validated['password']),
            'must_change_password' => false,
        ]);

        $currentTokenId = $request->user()->currentAccessToken()->id;
        $user->tokens()->where('id', '!=', $currentTokenId)->delete();

        return response()->json(['message' => 'Password changed successfully.']);
    }

    // SIGN OUT OTHER DEVICES (all roles)
    // Deletes every token of this user except the one used for this request,
    // so the current device stays signed in.
    public function logoutOthers(Request $request)
    {
        $currentTokenId = $request->user()->currentAccessToken()->id;
        $count = $request->user()->tokens()->where('id', '!=', $currentTokenId)->delete();

        return response()->json([
            'message' => $count > 0
                ? "Signed out of {$count} other device(s)."
                : 'No other devices were signed in.',
            'signed_out' => $count,
        ]);
    }
}
