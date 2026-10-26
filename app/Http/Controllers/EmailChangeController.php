<?php

namespace App\Http\Controllers;

use App\Mail\EmailChangeNoticeMail;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

/** Completes or drops a pending email change. Asking for one is part of ProfileController::update. */
class EmailChangeController extends Controller
{
    /** Signed, expiring link mailed to the new address. The signed-in user must be the one who asked. */
    public function confirm(Request $request, AuditLogger $audit): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless((int) $request->query('id') === $user->getKey(), 403);

        $pending = $user->pendingEmail();
        abort_if($pending === null || ! hash_equals(sha1($pending), (string) $request->query('hash')), 410, 'This link is no longer valid. Ask for the change again from your profile.');

        if (User::where('email', $pending)->whereKeyNot($user->getKey())->exists()) {
            $user->clearPendingEmail();

            return redirect()->route('profile.edit')->with('error', 'That address is now used by another account, so your email was not changed.');
        }

        $old = $user->email;
        $user->forceFill(['email' => $pending, 'email_verified_at' => now()])->save();
        $user->clearPendingEmail();

        $audit->recordFor($user, 'profile.updated', "Updated their profile: email (now {$pending})");
        Mail::to($old)->send(new EmailChangeNoticeMail($user->name, $pending, completed: true));

        return redirect()->route('profile.edit')->with('success', 'Your email address was changed.');
    }

    public function cancel(Request $request): RedirectResponse
    {
        $this->user($request)->clearPendingEmail();

        return redirect()->route('profile.edit')->with('success', 'The email change was cancelled.');
    }
}
