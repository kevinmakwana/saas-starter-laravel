<x-mail::message>
# Join {{ $team->name }}

{{ $inviter?->name ?? 'A teammate' }} invited you to join **{{ $team->name }}** on Acme as {{ $role->label() }}.

<x-mail::button :url="$url">
Accept invitation
</x-mail::button>

This link expires on {{ now()->addDays(7)->toFormattedDateString() }}. If you were not expecting it, you can ignore this email.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
