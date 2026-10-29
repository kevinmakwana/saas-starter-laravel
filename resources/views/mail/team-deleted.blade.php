<x-mail::message>
# {{ __(':team was deleted', ['team' => $teamName]) }}

{{ __(':user deleted the team **:team**. Its projects, tasks and activity history were deleted with it, and you no longer have access to it.', ['user' => $deletedBy, 'team' => $teamName]) }}

{{ __('Your own account and any other teams you belong to are not affected.') }}

<x-mail::button :url="$url">
{{ __('Go to your dashboard') }}
</x-mail::button>

{{ __('Thanks,') }}<br>
{{ config('app.name') }}
</x-mail::message>
