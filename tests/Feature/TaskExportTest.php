<?php

use App\Models\Project;
use App\Models\Task;

/** Parses a streamed CSV response body into rows. */
function csvRows(string $body): array
{
    return array_map('str_getcsv', array_values(array_filter(explode("\n", $body))));
}

function exportedBody($response): string
{
    return $response->streamedContent();
}

beforeEach(function () {
    $this->user = ownerWithTeam('pro');
    $this->team = $this->user->currentTeam;
    $this->project = asTenant($this->team, fn () => Project::factory()->create(['name' => 'Launch plan']));
});

it('downloads every task of a project as a CSV', function () {
    asTenant($this->team, function () {
        Task::factory()->count(12)->create(['project_id' => $this->project->id]);
        Task::factory()->create(['project_id' => Project::factory()->create()->id, 'title' => 'Other project task']);
    });

    $response = $this->actingAs($this->user)->get("/projects/{$this->project->id}/tasks/export")->assertOk();

    expect($response->headers->get('Content-Type'))->toStartWith('text/csv')
        ->and($response->headers->get('Content-Disposition'))->toContain('attachment')->toContain('launch-plan-tasks.csv');

    $rows = csvRows(exportedBody($response));
    expect($rows[0])->toBe(['id', 'title', 'status', 'created_at'])
        ->and($rows)->toHaveCount(13)
        ->and(collect($rows)->flatten()->contains('Other project task'))->toBeFalse();
});

it('escapes commas and quotes', function () {
    asTenant($this->team, fn () => Task::factory()->create(['project_id' => $this->project->id, 'title' => 'Say "hi", then leave']));

    $rows = csvRows(exportedBody($this->actingAs($this->user)->get("/projects/{$this->project->id}/tasks/export")));

    expect($rows[1][1])->toBe('Say "hi", then leave');
});

it('neutralises spreadsheet formulas in titles', function () {
    asTenant($this->team, fn () => Task::factory()->create(['project_id' => $this->project->id, 'title' => '=HYPERLINK("http://evil.test","click")']));

    $rows = csvRows(exportedBody($this->actingAs($this->user)->get("/projects/{$this->project->id}/tasks/export")));

    expect($rows[1][1])->toStartWith("'=");
});

it('sends teams without the feature to the billing page', function () {
    $free = ownerWithTeam('free');
    $project = asTenant($free->currentTeam, fn () => Project::factory()->create());

    $this->actingAs($free)->get("/projects/{$project->id}/tasks/export")
        ->assertRedirect(route('billing.show'))
        ->assertSessionHas('upgrade');
});

it("cannot export another team's project", function () {
    $other = ownerWithTeam('pro');
    $project = asTenant($other->currentTeam, fn () => Project::factory()->create());

    $this->actingAs($this->user)->get("/projects/{$project->id}/tasks/export")->assertNotFound();
});

it('tells the project page whether export is available', function () {
    $this->actingAs($this->user)->get("/projects/{$this->project->id}")
        ->assertInertia(fn ($page) => $page->where('auth.features.task_export', true));
});
