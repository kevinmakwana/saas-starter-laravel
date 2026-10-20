<x-mail::message>
# Your trial ends soon

The free trial of the {{ $plan->label() }} plan for **{{ $team->name }}** ends on **{{ $endsAt->toFormattedDateString() }}**.

Unless you cancel before then, your payment method is charged and the plan continues. If you cancel, you keep
the plan until the trial ends and the team then moves to the Free plan.

<x-mail::button :url="$url">
Review your plan
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
