<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Day;
use App\Models\Task;
use App\Services\AppTime;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    public function csv(Request $request, string $table): StreamedResponse
    {
        abort_unless($request->user()->isManager(), 403);

        $from = $request->query('from', AppTime::now()->startOfMonth()->toDateString());
        $to = $request->query('to', AppTime::today());

        return match ($table) {
            'tasks' => $this->exportTasks($from, $to),
            'attendance' => $this->exportAttendance($from, $to),
            'reasons' => $this->exportReasons($from, $to),
            default => abort(404, 'Unknown export table.'),
        };
    }

    private function exportTasks(string $from, string $to): StreamedResponse
    {
        $rows = Task::query()
            ->with(['user', 'day'])
            ->whereHas('day', fn ($q) => $q->whereBetween('date', [$from, $to]))
            ->orderBy('id')
            ->get();

        return $this->stream('tasks.csv', [
            'id', 'date', 'user', 'title', 'tier', 'result', 'reason_code', 'reason_note',
            'blocked', 'effort', 'added_after_cutoff', 'carry_count',
        ], $rows->map(fn (Task $t) => [
            $t->id,
            $t->day?->date?->toDateString(),
            $t->user?->name,
            $t->title,
            $t->tier,
            $t->result,
            $t->reason_code,
            $t->reason_note,
            $t->blocked ? 1 : 0,
            $t->effort,
            $t->added_after_cutoff ? 1 : 0,
            $t->carry_count,
        ]));
    }

    private function exportAttendance(string $from, string $to): StreamedResponse
    {
        $rows = Day::query()
            ->with('user')
            ->whereBetween('date', [$from, $to])
            ->orderBy('date')
            ->get();

        return $this->stream('attendance.csv', [
            'date', 'user', 'work_status', 'check_in_at', 'check_out_at', 'late', 'late_excused', 'state',
        ], $rows->map(fn (Day $d) => [
            $d->date->toDateString(),
            $d->user?->name,
            $d->work_status,
            AppTime::toLocalIso($d->check_in_at),
            AppTime::toLocalIso($d->check_out_at),
            $d->late ? 1 : 0,
            $d->late_excused ? 1 : 0,
            $d->state(),
        ]));
    }

    private function exportReasons(string $from, string $to): StreamedResponse
    {
        $rows = Task::query()
            ->with(['user', 'day'])
            ->whereIn('result', ['partial', 'not_done', 'dropped'])
            ->whereHas('day', fn ($q) => $q->whereBetween('date', [$from, $to]))
            ->get();

        return $this->stream('reasons.csv', [
            'date', 'user', 'title', 'result', 'reason_code', 'reason_note',
        ], $rows->map(fn (Task $t) => [
            $t->day?->date?->toDateString(),
            $t->user?->name,
            $t->title,
            $t->result,
            $t->reason_code,
            $t->reason_note,
        ]));
    }

    private function stream(string $filename, array $headers, $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, is_array($row) ? $row : $row->toArray());
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
