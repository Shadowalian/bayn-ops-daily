<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('employee_id', 32)->nullable()->after('id');
        });

        $known = [
            'manager@bayn.local' => 'MGR001',
            'hana@bayn.local' => 'EMP001',
            'abebe@bayn.local' => 'EMP002',
            'sara@bayn.local' => 'EMP003',
            'yonas@bayn.local' => 'EMP004',
        ];

        $used = [];
        $users = DB::table('users')->orderBy('id')->get(['id', 'role', 'email']);
        foreach ($users as $user) {
            $email = strtolower((string) $user->email);
            if (isset($known[$email])) {
                $employeeId = $known[$email];
            } else {
                $prefix = $user->role === 'manager' ? 'MGR' : 'EMP';
                $employeeId = sprintf('%s%03d', $prefix, $user->id);
                while (isset($used[$employeeId]) || in_array($employeeId, $known, true)) {
                    $employeeId = sprintf('%s%03d', $prefix, $user->id + count($used) + 100);
                }
            }
            $used[$employeeId] = true;
            DB::table('users')->where('id', $user->id)->update([
                'employee_id' => $employeeId,
            ]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->unique('employee_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['employee_id']);
            $table->dropColumn('employee_id');
        });
    }
};
