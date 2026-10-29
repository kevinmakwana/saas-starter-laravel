<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Choosing the language. Kept on the account when signed in, and in the session so it also holds before signing in. */
class LocaleController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'locale' => ['required', 'string', Rule::in(array_keys((array) config('locales.available')))],
        ]);

        $request->session()->put('locale', $data['locale']);

        $user = $request->user();
        if ($user instanceof User) {
            $user->forceFill(['locale' => $data['locale']])->save();
        }

        return back();
    }
}
