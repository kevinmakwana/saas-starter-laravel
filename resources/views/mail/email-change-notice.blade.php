<x-mail::message>
@if ($completed)
# {{ __('Your email address was changed') }}

{{ __('Hi :name, the email address of your :app account is now **:email**. You will no longer receive messages at this address, and you sign in with the new one.', ['name' => $name, 'app' => config('app.name'), 'email' => $newEmail]) }}

{{ __('If this was not you, reset your password straight away and contact support.') }}
@else
# {{ __('Someone asked to change your email address') }}

{{ __('Hi :name, a request was made to change the email address of your :app account to **:email**. Nothing has changed yet: it only takes effect once the link sent to that address is opened.', ['name' => $name, 'app' => config('app.name'), 'email' => $newEmail]) }}

{{ __('If this was not you, change your password now.') }}

<x-mail::button :url="$url">
{{ __('Review your account') }}
</x-mail::button>
@endif

{{ __('Thanks,') }}<br>
{{ config('app.name') }}
</x-mail::message>
