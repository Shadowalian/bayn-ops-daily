<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Setting::defaults() as $key => $value) {
            Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        }

        // Web app is admin-only. Employees are created by admin and use Telegram only.
        User::query()->updateOrCreate(
            ['employee_id' => 'MGR001'],
            [
                'name' => 'Ops Manager',
                'email' => 'manager@bayn.local',
                'role' => 'manager',
                'password' => Hash::make('password'),
                'active' => true,
            ]
        );

        User::query()
            ->where('role', 'member')
            ->where(function ($q) {
                $q->whereIn('employee_id', ['EMP001', 'EMP002', 'EMP003', 'EMP004'])
                    ->orWhereIn('email', [
                        'hana@bayn.local',
                        'abebe@bayn.local',
                        'sara@bayn.local',
                        'yonas@bayn.local',
                    ]);
            })
            ->each(function (User $user) {
                $user->tokens()->delete();
                $user->update(['active' => false, 'telegram_chat_id' => null]);
            });
    }
}
