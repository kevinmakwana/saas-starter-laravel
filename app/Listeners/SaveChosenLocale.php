<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Login;

/** A language chosen on the sign-in page, before there was an account to keep it on, becomes the user's own choice. */
class SaveChosenLocale
{
    public function handle(Login $event): void
    {
        $chosen = request()->hasSession() ? request()->session()->get('locale') : null;

        if ($event->user instanceof User && is_string($chosen) && array_key_exists($chosen, (array) config('locales.available')) && $event->user->locale !== $chosen) {
            $event->user->forceFill(['locale' => $chosen])->save();
        }
    }
}
