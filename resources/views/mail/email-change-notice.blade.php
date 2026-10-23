<x-mail::message>
@if ($completed)
# Your email address was changed

Hi {{ $name }}, the email address of your {{ config('app.name') }} account is now **{{ $newEmail }}**. You will no longer receive messages at this address, and you sign in with the new one.

If this was not you, reset your password straight away and contact support.
@else
# Someone asked to change your email address

Hi {{ $name }}, a request was made to change the email address of your {{ config('app.name') }} account to **{{ $newEmail }}**. Nothing has changed yet: it only takes effect once the link sent to that address is opened.

If this was not you, change your password now.

<x-mail::button :url="$url">
Review your account
</x-mail::button>
@endif

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
