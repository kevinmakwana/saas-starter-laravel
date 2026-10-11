<x-mail::message>
# We couldn't process your payment

The latest payment for **{{ $team->name }}** ({{ $plan->label() }} plan) failed.

Your plan stays active until **{{ $deadline?->toFormattedDateString() }}**. Please update your payment method before then,
otherwise the team will move to the Free plan until the payment goes through.

<x-mail::button :url="$url">
Review billing
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
