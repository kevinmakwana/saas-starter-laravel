<x-mail::message>
# {{ __('Confirm your new email address') }}

{{ __('Hi :name, you asked to use this address for your :app account. Confirm it to finish the change.', ['name' => $name, 'app' => config('app.name')]) }}

<x-mail::button :url="$url">
{{ __('Confirm email address') }}
</x-mail::button>

{{ __('This link works for :minutes minutes, and you need to be signed in to your account when you open it. If you did not ask for this, ignore this email and nothing will change.', ['minutes' => $minutes]) }}

{{ __('Thanks,') }}<br>
{{ config('app.name') }}
</x-mail::message>
