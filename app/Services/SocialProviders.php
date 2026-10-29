<?php

namespace App\Services;

use Laravel\Socialite\Contracts\User as ProviderUser;
use Laravel\Socialite\Two\User;

/** The outside logins the app knows, and which of them have credentials. A provider without credentials has no button and no routes. */
class SocialProviders
{
    public const LABELS = ['google' => 'Google', 'github' => 'GitHub'];

    /** @return list<array{key: string, label: string}> */
    public function enabled(): array
    {
        $enabled = [];
        foreach (self::LABELS as $key => $label) {
            if ($this->isEnabled($key)) {
                $enabled[] = ['key' => $key, 'label' => $label];
            }
        }

        return $enabled;
    }

    public function isEnabled(string $provider): bool
    {
        return isset(self::LABELS[$provider])
            && filled(config("services.{$provider}.client_id"))
            && filled(config("services.{$provider}.client_secret"));
    }

    public function label(string $provider): string
    {
        return self::LABELS[$provider] ?? $provider;
    }

    /**
     * The person's email address if the provider vouches for it, in lowercase. Accounts are matched by this address,
     * so an unverified one would let anyone claim somebody else's account.
     *
     * Google says in its profile whether the address is verified. GitHub only returns a verified primary address.
     */
    public function verifiedEmail(string $provider, ProviderUser $user): ?string
    {
        $email = strtolower(trim((string) $user->getEmail()));
        if ($email === '') {
            return null;
        }

        if ($provider === 'google') {
            $raw = $user instanceof User ? $user->user : [];
            $verified = $raw['email_verified'] ?? false;

            return $verified === true || $verified === 'true' ? $email : null;
        }

        return $email;
    }
}
