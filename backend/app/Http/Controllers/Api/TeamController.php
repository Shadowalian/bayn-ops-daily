<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AppTime;
use App\Services\DayService;
use Illuminate\Http\Request;

class TeamController extends Controller
{
    public function __construct(private DayService $days) {}

    public function today(Request $request)
    {
        $date = $request->query('date', AppTime::today());
        $rows = $this->days->teamToday($date, includeTasks: true);

        $needsAttention = [
            'missing_plans' => [],
            'not_closed' => [],
            'open_blockers' => [],
            'not_linked' => [],
        ];

        foreach ($rows as $row) {
            $state = $row['day']['state'] ?? 'not_started';
            if (empty($row['user']['telegram_chat_id'])) {
                $needsAttention['not_linked'][] = $row['user'];
            }
            if (in_array($state, ['not_started', 'awaiting_plan'], true)) {
                $needsAttention['missing_plans'][] = $row['user'];
            }
            if (in_array($state, ['planned', 'edit_unlocked', 'awaiting_plan'], true)) {
                $needsAttention['not_closed'][] = $row['user'];
            }
            foreach ($row['blockers'] as $blocker) {
                $needsAttention['open_blockers'][] = [
                    'user' => $row['user'],
                    'blocker' => $blocker,
                ];
            }
        }

        return response()->json([
            'date' => $date,
            'timezone' => AppTime::timezone(),
            'members' => $rows,
            'needs_attention' => $needsAttention,
        ]);
    }

    public function users(Request $request)
    {
        abort_unless($request->user()->isManager(), 403);

        return response()->json(
            User::query()->orderBy('employee_id')->get([
                'id', 'employee_id', 'name', 'email', 'role', 'active', 'telegram_chat_id',
            ])
        );
    }
}
