<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class PurgeDemoMembersCommand extends Command
{
    protected $signature = 'ops:purge-demo-members {--hard : Permanently delete instead of deactivate}';

    protected $description = 'Remove seeded demo employees (Hana, Abebe, Sara, Yonas)';

    public function handle(): int
    {
        $query = User::query()
            ->where('role', 'member')
            ->where(function ($q) {
                $q->whereIn('employee_id', ['EMP001', 'EMP002', 'EMP003', 'EMP004'])
                    ->orWhereIn('email', [
                        'hana@bayn.local',
                        'abebe@bayn.local',
                        'sara@bayn.local',
                        'yonas@bayn.local',
                    ])
                    ->orWhereIn('name', ['Hana', 'Abebe', 'Sara', 'Yonas']);
            });

        $count = 0;
        $query->get()->each(function (User $user) use (&$count) {
            $user->tokens()->delete();
            if ($this->option('hard')) {
                $user->delete();
            } else {
                $user->update(['active' => false, 'telegram_chat_id' => null]);
            }
            $this->info(($this->option('hard') ? 'Deleted' : 'Deactivated').": {$user->employee_id} {$user->name}");
            $count++;
        });

        $this->info("Done ({$count}).");

        return self::SUCCESS;
    }
}
