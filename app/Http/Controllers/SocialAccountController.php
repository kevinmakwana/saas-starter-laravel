<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use App\Services\SocialProviders;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Disconnecting a Google or GitHub login from the profile page. The account and its password stay. */
class SocialAccountController extends Controller
{
    public function destroy(Request $request, int $account, AuditLogger $audit, SocialProviders $providers): RedirectResponse
    {
        $user = $this->user($request);
        $link = $user->socialAccounts()->whereKey($account)->firstOrFail();
        $label = $providers->label($link->provider);

        $link->delete();
        $audit->recordFor($user, 'social.unlinked', "Disconnected their {$label} login");

        return redirect()->route('profile.edit')->with('success', "{$label} disconnected.");
    }
}
