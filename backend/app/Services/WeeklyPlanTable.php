<?php

namespace App\Services;

use App\Models\Task;
use App\Models\WeeklyGoal;
use App\Models\WeeklyPlan;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class WeeklyPlanTable
{
    /**
     * @param  Collection<int, WeeklyGoal>  $goals
     * @param  array<int, array<string, string>>  $marksByGoal  goalId => ['Mon'=>'✓', ...]
     * @param  array<int, string>  $todayByGoal  goalId => today cell
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
        $range = $weekStart->format('j M').' – '.$weekEnd->format('j M');
        $status = ucfirst($plan->status);

        $header = '<b>Weekly checkpoints</b> · '.$range.' · <i>'.e($status).'</i>';
        if ($caption) {
            $header .= "\n".$caption;
        }

        $rows = '';
        $i = 0;
        foreach ($goals as $goal) {
            $i++;
            $title = $this->pad($this->truncate($goal->title, 26), 28);
            $src = $this->pad($goal->srcLabel(), 3);
            $week = $this->pad($this->weekStrip($marksByGoal[$goal->id] ?? []), 11);
            $today = $this->pad($todayByGoal[$goal->id] ?? '·', 5);
            $num = $this->pad((string) $i, 2);
            $rows .= '│ '.$num.' │ '.$title.' │ '.$src.' │ '.$week.' │ '.$today." │\n";
        }

        if ($rows === '') {
            $rows = '│  — │ ' . $this->pad('(no checkpoints yet)', 28) . " │     │             │       │\n";
        }

        $pre = '<pre>'
            ."┌────┬──────────────────────────────┬─────┬─────────────┬───────┐\n"
            ."│  # │ Checkpoint                   │ Src │ M T W T F   │ Today │\n"
            ."├────┼──────────────────────────────┼─────┼─────────────┼───────┤\n"
            .$rows
            .'└────┴──────────────────────────────┴─────┴─────────────┴───────┘'
            .'</pre>';

        // Escape only after building fixed-width cells would break alignment;
        // escape special HTML in the raw title already truncated into cells via htmlspecialchars in truncate.
        return $header."\n\n".$pre;
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
     * Build Mon–Fri marks for goals in a week from tasks.
     *
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
            $dow = (string) $date->dayOfWeekIso; // 1=Mon … 5=Fri
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

        // holidays without tasks
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

    /** @param  array<string, string>  $marks */
    private function weekStrip(array $marks): string
    {
        $parts = [];
        foreach (['1', '2', '3', '4', '5'] as $d) {
            $parts[] = $marks[$d] ?? '·';
        }

        return implode(' ', $parts);
    }

    private function truncate(string $title, int $width): string
    {
        $clean = preg_replace('/\s+/', ' ', trim($title)) ?: '';
        if (mb_strlen($clean) > $width) {
            $clean = mb_substr($clean, 0, max(1, $width - 1)).'…';
        }

        return htmlspecialchars($clean, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function pad(string $value, int $width): string
    {
        $len = mb_strlen(html_entity_decode($value, ENT_QUOTES, 'UTF-8'));
        if ($len >= $width) {
            return $value;
        }

        return $value.str_repeat(' ', $width - $len);
    }
}
