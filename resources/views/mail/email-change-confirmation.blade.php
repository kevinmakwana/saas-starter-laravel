<x-mail::message>
# Confirm your new email address

Hi {{ $name }}, you asked to use this address for your {{ config('app.name') }} account. Confirm it to finish the change.

<x-mail::button :url="$url">
Confirm email address
</x-mail::button>

This link works for {{ $minutes }} minutes, and you need to be signed in to your account when you open it. If you did not ask for this, ignore this email and nothing will change.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
