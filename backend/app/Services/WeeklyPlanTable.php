<?php

namespace App\Services;

use App\Models\Task;
use App\Models\WeeklyGoal;
use App\Models\WeeklyPlan;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class WeeklyPlanTable
{
    private const DAY_LABELS = ['1' => 'Mon', '2' => 'Tue', '3' => 'Wed', '4' => 'Thu', '5' => 'Fri'];

    /**
     * @param  Collection<int, WeeklyGoal>  $goals
     * @param  array<int, array<string, string>>  $marksByGoal  goalId => ['1'=>'✓', ...]
     * @param  array<int, string>  $todayByGoal  goalId => today cell (· ✓ ✗ – ~)
     */
    public function render(
        WeeklyPlan $plan,
        Collection $goals,
        array $marksByGoal = [],
        array $todayByGoal = [],
        ?string $caption = null,
    ): string {
        $weekStart = Carbon::parse($plan->week_start)->timezone(AppTime::timezone());
        $weekEnd = $weekStart->copy()->addDays(6);
        $range = $weekStart->format('j M').' – '.$weekEnd->format('j M Y');

        $statusEmoji = match ($plan->status) {
            WeeklyPlan::STATUS_APPROVED => '✅',
            WeeklyPlan::STATUS_PENDING => '⏳',
            WeeklyPlan::STATUS_REJECTED => '↩️',
            default => '📋',
        };

        $lines = [
            '🗓 <b>Your week</b>',
            $range.' · '.$statusEmoji.' <i>'.e(ucfirst($plan->status)).'</i>',
        ];

        if ($caption) {
            $lines[] = $caption;
        }

        $lines[] = '';

        if ($goals->isEmpty()) {
            $lines[] = '<i>No checkpoints on this week yet.</i>';

            return implode("\n", $lines);
        }

        $i = 0;
        foreach ($goals as $goal) {
            $i++;
            $title = e(trim(preg_replace('/\s+/', ' ', $goal->title) ?: ''));
            $lines[] = '<b>'.$i.'.</b> '.$title;
            $lines[] = '   '.$this->sourceLine($goal).' · '.$this->weekProgressLine($marksByGoal[$goal->id] ?? []);
            $lines[] = '   Today: '.$this->todayLabel($todayByGoal[$goal->id] ?? '·');
            $lines[] = '';
        }

        // drop trailing blank
        while ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        return implode("\n", $lines);
    }

    /** @param  Collection<int, WeeklyGoal>  $goals */
    public function positionMap(Collection $goals): array
    {
        $map = [];
        $i = 0;
        foreach ($goals as $goal) {
            $i++;
            $map[$i] = $goal->id;
        }

        return $map;
    }

    /**
     * @param  Collection<int, WeeklyGoal>  $goals
     * @return array<int, array<string, string>>
     */
    public function marksFor(Collection $goals, string $weekStart): array
    {
        $ids = $goals->pluck('id')->all();
        if ($ids === []) {
            return [];
        }

        $start = Carbon::parse($weekStart)->timezone(AppTime::timezone())->startOfDay();
        $end = $start->copy()->addDays(6)->endOfDay();

        $tasks = Task::query()
            ->whereIn('weekly_goal_id', $ids)
            ->where('result', '!=', 'dropped')
            ->whereHas('day', fn ($q) => $q->whereBetween('date', [$start->toDateString(), $end->toDateString()]))
            ->with('day:id,date')
            ->get();

        $out = [];
        foreach ($ids as $id) {
            $out[$id] = ['1' => '·', '2' => '·', '3' => '·', '4' => '·', '5' => '·'];
        }

        foreach ($tasks as $task) {
            $date = Carbon::parse($task->day->date)->timezone(AppTime::timezone());
            $dow = (string) $date->dayOfWeekIso;
            if (! isset($out[$task->weekly_goal_id][$dow])) {
                continue;
            }
            if (! AppTime::isWorkingDay($date)) {
                $out[$task->weekly_goal_id][$dow] = '–';

                continue;
            }
            $out[$task->weekly_goal_id][$dow] = match ($task->result) {
                'done' => '✓',
                'not_done' => '✗',
                'partial' => '~',
                default => '·',
            };
        }

        for ($d = 1; $d <= 5; $d++) {
            $day = $start->copy()->addDays($d - 1);
            if (! AppTime::isWorkingDay($day)) {
                foreach ($ids as $id) {
                    if (($out[$id][(string) $d] ?? '·') === '·') {
                        $out[$id][(string) $d] = '–';
                    }
                }
            }
        }

        return $out;
    }

    private function sourceLine(WeeklyGoal $goal): string
    {
        if ($goal->status === WeeklyGoal::STATUS_REMOVE_REQUESTED) {
            return '✂️ <i>Removal requested</i>';
        }

        return $goal->source === 'admin'
            ? '📌 Admin'
            : '👤 Yours';
    }

    /** @param  array<string, string>  $marks */
    private function weekProgressLine(array $marks): string
    {
        $parts = [];
        foreach (self::DAY_LABELS as $key => $label) {
            $parts[] = $label.' '.$this->markEmoji($marks[$key] ?? '·');
        }

        return implode('  ', $parts);
    }

    private function markEmoji(string $mark): string
    {
        return match ($mark) {
            '✓' => '✅',
            '✗' => '❌',
            '~' => '🔸',
            '–' => '—',
            default => '○',
        };
    }

    private function todayLabel(string $mark): string
    {
        return match ($mark) {
            '✓' => '✅ done',
            '✗' => '❌ not done',
            '~' => '🔸 partial',
            '–' => 'not on today’s list',
            default => '○ not started',
        };
    }
}
