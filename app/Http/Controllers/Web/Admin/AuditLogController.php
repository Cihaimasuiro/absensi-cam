<?php

namespace App\Http\Controllers\Web\Admin;

use App\Domain\User\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('audit.view');

        $query = $this->buildFilteredQuery($request);

        $logs = $query->paginate(50)->withQueryString();

        $logNames = Activity::distinct()->pluck('log_name')->filter()->values();
        $users = User::orderBy('name')->get(['id', 'name', 'email']);

        $selectedLogName = $request->query('log_name');
        $selectedUserId = $request->query('user_id');
        $search = $request->query('search');
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');

        return view('admin.audit.index', compact(
            'logs',
            'logNames',
            'users',
            'selectedLogName',
            'selectedUserId',
            'search',
            'startDate',
            'endDate'
        ));
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        $this->authorize('audit.view');

        $query = $this->buildFilteredQuery($request);

        $filename = 'audit_logs_' . now()->format('Ymd_His') . '.csv';

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['ID', 'Waktu (UTC)', 'Kategori', 'Deskripsi', 'Pengguna', 'Target', 'ID Target', 'Perubahan / Properti']);

            $query->chunk(500, function ($logs) use ($handle) {
                foreach ($logs as $log) {
                    $causer = $log->causer ? "{$log->causer->name} ({$log->causer->email})" : 'Sistem / Anonim';
                    $properties = !empty($log->properties) ? json_encode($log->properties, JSON_UNESCAPED_UNICODE) : '—';
                    $subjectType = $log->subject_type ? class_basename($log->subject_type) : '—';

                    fputcsv($handle, [
                        $log->id,
                        $log->created_at->format('Y-m-d H:i:s'),
                        $log->log_name ?? 'default',
                        $log->description,
                        $causer,
                        $subjectType,
                        $log->subject_id ?? '—',
                        $properties,
                    ]);
                }
            });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }

    private function buildFilteredQuery(Request $request)
    {
        return Activity::with('causer')
            ->when($request->query('log_name'), function ($q, $logName) {
                $q->where('log_name', $logName);
            })
            ->when($request->query('user_id'), function ($q, $userId) {
                $q->where('causer_id', $userId)->where('causer_type', User::class);
            })
            ->when($request->query('search'), function ($q, $search) {
                $q->where('description', 'like', "%{$search}%");
            })
            ->when($request->query('start_date'), function ($q, $start) {
                $q->whereDate('created_at', '>=', $start);
            })
            ->when($request->query('end_date'), function ($q, $end) {
                $q->whereDate('created_at', '<=', $end);
            })
            ->latest('id');
    }
}
