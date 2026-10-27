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
            ['name' => 'Ops Manager', 'email' => 'manager@bayn.local', 'role' => 'manager'],
            ['name' => 'Hana', 'email' => 'hana@bayn.local', 'role' => 'member'],
            ['name' => 'Abebe', 'email' => 'abebe@bayn.local', 'role' => 'member'],
            ['name' => 'Sara', 'email' => 'sara@bayn.local', 'role' => 'member'],
            ['name' => 'Yonas', 'email' => 'yonas@bayn.local', 'role' => 'member'],
        ];

        foreach ($users as $row) {
            User::query()->updateOrCreate(
                ['email' => $row['email']],
                [
                    'name' => $row['name'],
                    'role' => $row['role'],
                    'password' => Hash::make('password'),
                    'active' => true,
                ]
            );
        }
    }
}
