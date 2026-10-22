<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\PasswordReset;

/** Writes sign-ins and password resets to the audit log of the user's current team. */
class RecordAuthActivity
{
    public function __construct(private AuditLogger $audit) {}

    public function handleLogin(Login $event): void
    {
        if ($event->user instanceof User) {
            $this->audit->recordFor($event->user, 'auth.login', 'Signed in');
        }
    }

    public function handlePasswordReset(PasswordReset $event): void
    {
        if ($event->user instanceof User) {
            $this->audit->recordFor($event->user, 'password.changed', 'Reset their password');
        }
    }
}
