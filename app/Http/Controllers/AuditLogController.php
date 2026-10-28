<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAuditLog', $this->team($request));

        $entries = AuditLog::query()
            ->with('user:id,name')
            ->latest('id')
            ->paginate(20)
            ->through(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'summary' => $log->summary,
                'actor' => $log->user->name ?? 'System',
                'at' => $log->created_at->toIso8601String(),
            ]);

        return Inertia::render('AuditLog/Index', ['entries' => $entries]);
    }
}
