<?php

namespace App\Exceptions;

use RuntimeException;

class TenancyException extends RuntimeException
{
    public static function missingTenant(string $model): self
    {
        return new self("Cannot write {$model} without a current tenant.");
    }

    public static function noCurrentTeam(): self
    {
        return new self('The signed-in user has no current team.');
    }

    public static function crossTenantWrite(string $model): self
    {
        return new self("Refusing cross-tenant write on {$model}.");
    }
}
