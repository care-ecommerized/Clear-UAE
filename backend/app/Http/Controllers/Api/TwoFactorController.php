<?php

/**
 * ============================================================
 * TWO-FACTOR CONTROLLER — Admin Authenticator-App 2FA (TOTP)
 * ============================================================
 * What this file does:
 *   Manages optional time-based one-time-password (TOTP) two-factor
 *   authentication for admin accounts (Google Authenticator, Authy, etc.).
 *
 *   Setup is opt-in: an admin enables it from Settings, scans a QR code,
 *   and confirms one code. Only after confirmation does 2FA take effect.
 *
 * API endpoints handled (all under /api/auth/2fa):
 *   GET  /status   → is 2FA enabled for the current admin?
 *   POST /enable   → generate a secret + QR (not yet active)
 *   POST /confirm  → verify first code, activate, return recovery codes
 *   POST /disable  → turn 2FA off (requires current password)
 *   POST /verify   → PUBLIC: finish a login challenge with a code
 * ============================================================
 */

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FAQRCode\Google2FA;

class TwoFactorController extends Controller
{
    public function __construct(private Google2FA $google2fa) {}

    // GET /api/auth/2fa/status
    public function status(Request $request)
    {
        return response()->json(['enabled' => $request->user()->hasTwoFactorEnabled()]);
    }

    // POST /api/auth/2fa/enable — stage a secret + return a QR to scan (not active yet)
    public function enable(Request $request)
    {
        $user = $request->user();

        // Already fully enabled — don't silently overwrite the secret.
        if ($user->hasTwoFactorEnabled()) {
            return response()->json(['message' => 'Two-factor authentication is already enabled.'], 409);
        }

        $secret = $this->google2fa->generateSecretKey();
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        $qr = $this->google2fa->getQRCodeInline(
            \App\Support\Brand::name(),
            $user->email,
            $secret
        );

        return response()->json([
            'secret' => $secret,        // shown for manual entry as a fallback
            'qr_svg' => $qr,            // inline SVG data URI for the authenticator app
        ]);
    }

    // POST /api/auth/2fa/confirm — verify the first code, then switch 2FA on
    public function confirm(Request $request)
    {
        $request->validate(['code' => 'required|string']);
        $user = $request->user();

        if (is_null($user->two_factor_secret)) {
            return response()->json(['message' => 'Start setup first.'], 422);
        }

        if (! $this->google2fa->verifyKey($user->two_factor_secret, $request->code)) {
            throw ValidationException::withMessages(['code' => ['That code is incorrect. Try again.']]);
        }

        $recoveryCodes = $this->generateRecoveryCodes();
        $user->forceFill([
            'two_factor_recovery_codes' => $recoveryCodes,
            'two_factor_confirmed_at' => now(),
        ])->save();

        return response()->json([
            'success' => true,
            'recovery_codes' => $recoveryCodes,
        ]);
    }

    // POST /api/auth/2fa/disable — requires re-entering the current password
    public function disable(Request $request)
    {
        $request->validate(['password' => 'required|string']);
        $user = $request->user();

        if (! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages(['password' => ['Incorrect password.']]);
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return response()->json(['success' => true]);
    }

    // POST /api/auth/2fa/verify — PUBLIC: completes the login challenge from signin()
    public function verify(Request $request)
    {
        $request->validate([
            'challenge' => 'required|string',
            'code' => 'required|string',
        ]);

        $userId = Cache::get('2fa_challenge:'.$request->challenge);
        if (! $userId) {
            throw ValidationException::withMessages(['code' => ['This login attempt expired. Please sign in again.']]);
        }

        $user = User::find($userId);
        if (! $user || ! $user->hasTwoFactorEnabled()) {
            throw ValidationException::withMessages(['code' => ['This login attempt is no longer valid. Please sign in again.']]);
        }

        $code = trim($request->code);
        $verified = $this->google2fa->verifyKey($user->two_factor_secret, $code);

        // Fall back to a one-time recovery code (and burn it on use).
        if (! $verified) {
            $verified = $this->consumeRecoveryCode($user, $code);
        }

        if (! $verified) {
            throw ValidationException::withMessages(['code' => ['Invalid code. Try again.']]);
        }

        // Success — burn the challenge so it can't be replayed, then issue the real token.
        Cache::forget('2fa_challenge:'.$request->challenge);
        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'isAdmin' => $user->isAdmin(),
            ],
            'accessToken' => $token,
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function generateRecoveryCodes(): array
    {
        return collect(range(1, 10))
            ->map(fn () => Str::upper(Str::random(5).'-'.Str::random(5)))
            ->all();
    }

    private function consumeRecoveryCode(User $user, string $code): bool
    {
        $codes = $user->two_factor_recovery_codes ?? [];
        $normalized = Str::upper(trim($code));

        if (! in_array($normalized, $codes, true)) {
            return false;
        }

        $user->forceFill([
            'two_factor_recovery_codes' => array_values(array_filter($codes, fn ($c) => $c !== $normalized)),
        ])->save();

        return true;
    }
}
