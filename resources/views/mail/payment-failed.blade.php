<x-mail::message>
# {{ __("We couldn't process your payment") }}

{{ __('The latest payment for **:team** (:plan plan) failed.', ['team' => $team->name, 'plan' => $plan->label()]) }}

{{ __('Your plan stays active until **:date**. Please update your payment method before then, otherwise the team will move to the Free plan until the payment goes through.', ['date' => $deadline?->isoFormat('ll')]) }}

<x-mail::button :url="$url">
{{ __('Update payment method') }}
</x-mail::button>

{{ __('Thanks,') }}<br>
{{ config('app.name') }}
</x-mail::message>
