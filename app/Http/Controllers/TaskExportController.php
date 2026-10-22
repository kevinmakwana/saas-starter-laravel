<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Task;
use App\Support\Csv;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Downloads all tasks of a project as CSV. Gated by the `task_export` feature on the route. */
class TaskExportController extends Controller
{
    private const HEADER = ['id', 'title', 'status', 'created_at'];

    public function __invoke(Project $project): StreamedResponse
    {
        Gate::authorize('view', $project);

        $filename = (Str::slug($project->name) ?: 'project').'-tasks.csv';

        return response()->streamDownload(function () use ($project) {
            $out = fopen('php://output', 'w') ?: throw new RuntimeException('Could not open the response stream.');
            fputcsv($out, self::HEADER, escape: '');

            foreach ($project->tasks()->orderBy('id')->cursor() as $task) {
                /** @var Task $task */
                fputcsv($out, [
                    $task->id,
                    Csv::safe($task->title),
                    $task->status,
                    $task->created_at?->toIso8601String(),
                ], escape: '');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
