<?php

namespace App\Support;

/** Reads TRUSTED_PROXIES: the load balancers whose X-Forwarded-* headers (client IP, https) may be believed. */
class TrustedProxies
{
    /** @return array<int, string>|string|null `*` trusts every proxy, a list trusts those addresses or CIDR ranges, null trusts none */
    public static function parse(?string $value): array|string|null
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if ($value === '*') {
            return '*';
        }

        return array_values(array_filter(array_map('trim', explode(',', $value)))) ?: null;
    }
}
