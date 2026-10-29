<x-mail::message>
# {{ __('Your trial ends soon') }}

{{ __('The free trial of the :plan plan for **:team** ends on **:date**.', ['plan' => $plan->label(), 'team' => $team->name, 'date' => $endsAt->isoFormat('ll')]) }}

{{ __('Unless you cancel before then, your payment method is charged and the plan continues. If you cancel, you keep the plan until the trial ends and the team then moves to the Free plan.') }}

<x-mail::button :url="$url">
{{ __('Review your plan') }}
</x-mail::button>

{{ __('Thanks,') }}<br>
{{ config('app.name') }}
</x-mail::message>
