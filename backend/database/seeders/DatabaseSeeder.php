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

        $users = [
            ['employee_id' => 'MGR001', 'name' => 'Ops Manager', 'email' => 'manager@bayn.local', 'role' => 'manager'],
            ['employee_id' => 'EMP001', 'name' => 'Hana', 'email' => 'hana@bayn.local', 'role' => 'member'],
            ['employee_id' => 'EMP002', 'name' => 'Abebe', 'email' => 'abebe@bayn.local', 'role' => 'member'],
            ['employee_id' => 'EMP003', 'name' => 'Sara', 'email' => 'sara@bayn.local', 'role' => 'member'],
            ['employee_id' => 'EMP004', 'name' => 'Yonas', 'email' => 'yonas@bayn.local', 'role' => 'member'],
        ];

        foreach ($users as $row) {
            User::query()->updateOrCreate(
                ['email' => $row['email']],
                [
                    'employee_id' => $row['employee_id'],
                    'name' => $row['name'],
                    'role' => $row['role'],
                    'password' => Hash::make('password'),
                    'active' => true,
                ]
            );
        }
    }
}
