<?php

/**
 * ============================================================
 * AUTH CONTROLLER — Handles Login / Register / Logout
 * ============================================================
 * What this file does:
 *   Handles user authentication:
 *   - Register new accounts
 *   - Login (returns API token)
 *   - Logout (revokes token)
 *   - Get current user profile
 *
 * API endpoints handled:
 *   POST /api/register       → Create new account
 *   POST /api/login          → Login & get token
 *   POST /api/logout         → Logout & revoke token
 *   GET  /api/user           → Get current user info
 * ============================================================
 */

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Profile;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    // POST /api/auth/signup
    public function signup(Request $request)
    {
        $request->validate([
            'email' => 'required|email|max:255|unique:users',
            'password' => 'required|min:8|max:100',
            'fullName' => 'required|string|min:1|max:100',
        ]);

        $user = User::create([
            'name' => $request->fullName,
            'email' => strtolower($request->email),
            'password' => Hash::make($request->password),
        ]);

        Profile::create([
            'user_id' => $user->id,
            'email' => $user->email,
            'full_name' => $request->fullName,
        ]);

        UserRole::create(['user_id' => $user->id, 'role' => 'user']);

        // Claim any orders this person placed as a guest with the same email,
        // so they appear under "My Orders" the moment the account exists.
        Order::where('customer_email', $user->email)
            ->whereNull('user_id')
            ->update(['user_id' => $user->id]);

        // Fire the verification email (optional/non-blocking — the account is
        // usable right away; this is just a nudge to confirm the address).
        $user->sendEmailVerificationNotification();

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'full_name' => $request->fullName,
                'phone' => null,
                'isAdmin' => $user->isAdmin(),
                'email_verified' => $user->hasVerifiedEmail(),
            ],
            'accessToken' => $token,
        ], 201);
    }

    // POST /api/auth/signin
    public function signin(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', strtolower($request->email))->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages(['email' => ['Invalid credentials']]);
        }

        // If this account has 2FA on, the password alone is not enough. Hand back a
        // short-lived challenge instead of a token — the client must finish at
        // /auth/2fa/verify with a code. The challenge is just a cache key pointing at
        // the user id; it grants no access on its own.
        if ($user->hasTwoFactorEnabled()) {
            $challenge = Str::random(40);
            Cache::put('2fa_challenge:'.$challenge, $user->id, now()->addMinutes(5));

            return response()->json([
                'requires_2fa' => true,
                'challenge' => $challenge,
            ]);
        }

        $token = $user->createToken('auth-token')->plainTextToken;
        $profile = $user->profile;

        return response()->json([
            'success' => true,
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'full_name' => $profile?->full_name,
                'phone' => $profile?->phone,
                'isAdmin' => $user->isAdmin(),
                'email_verified' => $user->hasVerifiedEmail(),
            ],
            'accessToken' => $token,
        ]);
    }

    // POST /api/auth/signout
    public function signout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['success' => true]);
    }

    // GET /api/auth/me
    public function me(Request $request)
    {
        $user = $request->user();
        $profile = $user->profile;

        return response()->json([
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'full_name' => $profile?->full_name,
                'phone' => $profile?->phone,
                'avatar_url' => $profile?->avatar_url,
                'isAdmin' => $user->isAdmin(),
                'email_verified' => $user->hasVerifiedEmail(),
            ],
        ]);
    }

    // PUT /api/auth/profile — update the signed-in user's name & phone
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'fullName' => 'required|string|min:1|max:100',
            'phone' => 'nullable|string|max:30',
            'email' => [
                'required',
                'email',
                'max:255',
                // Allow keeping the current email; reject if taken by anyone else.
                Rule::unique('users', 'email')->ignore($user->id),
            ],
        ]);

        $email = strtolower($request->email);

        // A profile row is created at signup, but create one defensively in
        // case of legacy accounts that predate that.
        $profile = $user->profile ?: Profile::create([
            'user_id' => $user->id,
            'email' => $email,
        ]);

        $profile->update([
            'full_name' => $request->fullName,
            'phone' => $request->phone,
            'email' => $email,
        ]);

        // Keep the users table in sync (name + email — email is the login id).
        $user->update([
            'name' => $request->fullName,
            'email' => $email,
        ]);

        return response()->json([
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'full_name' => $profile->full_name,
                'phone' => $profile->phone,
                'avatar_url' => $profile->avatar_url,
                'isAdmin' => $user->isAdmin(),
                'email_verified' => $user->hasVerifiedEmail(),
            ],
        ]);
    }

    // POST /api/auth/forgot-password — email a reset link.
    // Always returns the same success response so we never reveal whether an
    // email is registered (account-enumeration defence).
    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        Password::sendResetLink(['email' => strtolower($request->email)]);

        return response()->json([
            'success' => true,
            'message' => 'If that email is registered, a reset link is on its way.',
        ]);
    }

    // POST /api/auth/reset-password — set a new password using the emailed token.
    public function resetPassword(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
            'password' => 'required|min:8|max:100|confirmed',
        ]);

        $status = Password::reset(
            [
                'email' => strtolower($request->email),
                'password' => $request->password,
                'password_confirmation' => $request->password_confirmation,
                'token' => $request->token,
            ],
            function (User $user, string $password) {
                // The 'password' cast hashes this on save. setRememberToken()
                // returns void, so it can't be chained into save() — doing so
                // threw "save() on null" and every password reset failed.
                $user->forceFill(['password' => $password]);
                $user->setRememberToken(Str::random(60));
                $user->save();
                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PasswordReset) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Your password has been reset. You can now sign in.',
        ]);
    }

    // GET /api/auth/email/verify/{id}/{hash} — signed link from the email.
    // Marks the address verified, then redirects back to the storefront.
    public function verifyEmail(Request $request, string $id, string $hash)
    {
        $frontend = rtrim(config('app.url'), '/');
        $user = User::find($id);

        // Bad/expired link or hash mismatch → bounce back with a failure flag.
        if (! $user || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return redirect()->away($frontend.'/account?verified=0');
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        return redirect()->away($frontend.'/account?verified=1');
    }

    // POST /api/auth/email/resend — re-send the verification email (auth required).
    public function resendVerification(Request $request)
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json(['success' => true, 'already_verified' => true]);
        }

        $user->sendEmailVerificationNotification();

        return response()->json(['success' => true]);
    }
}
