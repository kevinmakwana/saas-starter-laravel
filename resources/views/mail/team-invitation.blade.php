<x-mail::message>
# {{ __('Join :team', ['team' => $team->name]) }}

{{ __(':inviter invited you to join **:team** on Acme as :role.', ['inviter' => $inviter?->name ?? __('A teammate'), 'team' => $team->name, 'role' => $role->label()]) }}

<x-mail::button :url="$url">
{{ __('Accept invitation') }}
</x-mail::button>

{{ __('This link expires on :date. If you were not expecting it, you can ignore this email.', ['date' => now()->addDays(7)->isoFormat('ll')]) }}

{{ __('Thanks,') }}<br>
{{ config('app.name') }}
</x-mail::message>
