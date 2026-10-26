<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Support\Csv;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Downloads the current team's audit log as CSV. Gated by the `audit_log` feature on the route. */
class AuditLogExportController extends Controller
{
    private const HEADER = ['id', 'at', 'actor', 'action', 'subject_type', 'subject_id', 'summary'];

    public function __invoke(Request $request): StreamedResponse
    {
        Gate::authorize('viewAuditLog', $this->team($request));

        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w') ?: throw new RuntimeException('Could not open the response stream.');
            fputcsv($out, self::HEADER, escape: '');

            foreach (AuditLog::query()->with('user:id,name')->lazyById() as $entry) {
                /** @var AuditLog $entry */
                fputcsv($out, [
                    $entry->id,
                    $entry->created_at->toIso8601String(),
                    Csv::safe($entry->user->name ?? 'System'),
                    $entry->action,
                    $entry->subject_type,
                    $entry->subject_id,
                    Csv::safe($entry->summary),
                ], escape: '');
            }

            fclose($out);
        }, 'audit-log.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
