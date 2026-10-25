<?php

namespace App\Services;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use PragmaRX\Google2FA\Google2FA;

/**
 * Time-based one-time codes (the kind authenticator apps show) and single-use recovery codes.
 *
 * A setup is "pending" while the user holds a secret they have not confirmed with a code yet. Only a confirmed
 * setup changes how signing in works. The secret and the codes are encrypted in the database by User's casts.
 */
class TwoFactor
{
    public const RECOVERY_CODES = 8;

    public function __construct(private Google2FA $google2fa) {}

    /** Starts (or restarts) a setup: a fresh secret that is not active until confirm() accepts a code from it. */
    public function begin(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => $this->google2fa->generateSecretKey(),
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_step' => null,
        ])->save();
    }

    /** The otpauth:// text an authenticator app scans, drawn as an SVG. Null without a pending setup. */
    public function qrSvg(User $user): ?string
    {
        if ($user->two_factor_secret === null) {
            return null;
        }

        $url = $this->google2fa->getQRCodeUrl(config('app.name'), $user->email, $user->two_factor_secret);
        $svg = (new Writer(new ImageRenderer(new RendererStyle(192, 1), new SvgImageBackEnd)))->writeString($url);

        // Drop the XML declaration so the SVG can be placed straight into a page.
        return trim(substr($svg, strpos($svg, "\n") + 1));
    }

    /**
     * Turns 2FA on if the code is right for the pending secret.
     *
     * @return list<string>|null the recovery codes, shown to the user once, or null when the code was wrong
     */
    public function confirm(User $user, string $code): ?array
    {
        if ($user->hasTwoFactorEnabled() || ! $this->accept($user, $code)) {
            return null;
        }

        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        return $this->regenerateRecoveryCodes($user);
    }

    /** Whether the code is right for a confirmed setup. Each code works once. */
    public function verify(User $user, string $code): bool
    {
        return $user->hasTwoFactorEnabled() && $this->accept($user, $code);
    }

    /** Uses up one recovery code. Case and surrounding spaces do not matter. */
    public function useRecoveryCode(User $user, string $code): bool
    {
        if (! $user->hasTwoFactorEnabled()) {
            return false;
        }

        $code = strtolower(trim($code));
        $remaining = [];
        $matched = false;
        foreach ($user->two_factor_recovery_codes ?? [] as $stored) {
            if (! $matched && hash_equals($stored, $code)) {
                $matched = true;
            } else {
                $remaining[] = $stored;
            }
        }

        if ($matched) {
            $user->forceFill(['two_factor_recovery_codes' => $remaining])->save();
        }

        return $matched;
    }

    /**
     * Replaces all recovery codes with new ones.
     *
     * @return list<string>
     */
    public function regenerateRecoveryCodes(User $user): array
    {
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            $codes[] = bin2hex(random_bytes(5)).'-'.bin2hex(random_bytes(5));
        }

        $user->forceFill(['two_factor_recovery_codes' => $codes])->save();

        return $codes;
    }

    /** Removes the secret, the codes and the confirmation: back to password-only sign-in. */
    public function disable(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_step' => null,
        ])->save();
    }

    /** Checks the code against the secret and remembers its time step, so the same code cannot be used again. */
    private function accept(User $user, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if ($user->two_factor_secret === null || preg_match('/^\d{6}$/', $code) !== 1) {
            return false;
        }

        // Starting from 0 rather than null makes the library return the exact time step that matched, not just `true`.
        $step = $this->google2fa->verifyKeyNewer($user->two_factor_secret, $code, $user->two_factor_last_step ?? 0);
        if (! is_int($step)) {
            return false;
        }

        $user->forceFill(['two_factor_last_step' => $step])->save();

        return true;
    }
}
