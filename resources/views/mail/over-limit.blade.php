<x-mail::message>
# {{ $team->name }} is over its plan limits

The team is now on the **{{ $plan->label() }}** plan, which includes less than the team currently uses:

@foreach ($overages as $overage)
- **{{ $overage['used'] }} {{ $overage['key'] }}**, the plan includes {{ $overage['limit'] }}
@endforeach

Nothing was deleted or locked: everyone keeps working with what exists. Until the team is back within the limits,
or on a plan that fits, nobody can add more projects or members, and pending invitations cannot be accepted.

<x-mail::button :url="$url">
Review the plan
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
