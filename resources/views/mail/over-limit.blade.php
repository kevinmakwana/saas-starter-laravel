<x-mail::message>
# {{ __(':team is over its plan limits', ['team' => $team->name]) }}

{{ __('The team is now on the **:plan** plan, which includes less than the team currently uses:', ['plan' => $plan->label()]) }}

@foreach ($overages as $overage)
- {{ __('**:used :what**, the plan includes :limit', ['used' => $overage['used'], 'what' => __($overage['key']), 'limit' => $overage['limit']]) }}
@endforeach

{{ __('Nothing was deleted or locked: everyone keeps working with what exists. Until the team is back within the limits, or on a plan that fits, nobody can add more projects or members, and pending invitations cannot be accepted.') }}

<x-mail::button :url="$url">
{{ __('Review the plan') }}
</x-mail::button>

{{ __('Thanks,') }}<br>
{{ config('app.name') }}
</x-mail::message>
