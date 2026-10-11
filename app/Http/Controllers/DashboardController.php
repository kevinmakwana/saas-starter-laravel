<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Task;
use App\Services\PlanLimits;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, PlanLimits $limits): Response
    {
        $team = $request->user()->currentTeam;
        $plan = $team->currentPlan();

        return Inertia::render('Dashboard', [
            'plan' => [
                'name' => $plan->label(),
                'projects' => ['used' => $limits->usage($team, 'projects'), 'limit' => $plan->limit('projects')],
                'members' => ['used' => $limits->usage($team, 'members'), 'limit' => $plan->limit('members')],
            ],
            'taskCounts' => Task::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'recentProjects' => Project::query()->latest('id')->limit(5)->get(['id', 'name']),
        ]);
    }
}
