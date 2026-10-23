<x-mail::message>
# {{ $teamName }} was deleted

{{ $deletedBy }} deleted the team **{{ $teamName }}**. Its projects, tasks and activity history were deleted with it, and you no longer have access to it.

Your own account and any other teams you belong to are not affected.

<x-mail::button :url="$url">
Go to your dashboard
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
