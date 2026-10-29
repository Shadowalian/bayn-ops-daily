<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('code_series', function (Blueprint $table) {
            $table->id();
            $table->string('prefix', 16)->unique();
            $table->string('name', 120);
            $table->enum('access_role', ['member', 'manager'])->default('member');
            $table->unsignedInteger('next_number')->default(1);
            $table->unsignedTinyInteger('pad_length')->default(3);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('code_series_id')->nullable()->after('employee_id')->constrained('code_series')->nullOnDelete();
        });

        $now = now();
        DB::table('code_series')->insert([
            [
                'prefix' => 'EMP',
                'name' => 'Employees',
                'access_role' => 'member',
                'next_number' => 1,
                'pad_length' => 3,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'prefix' => 'MGR',
                'name' => 'Admins',
                'access_role' => 'manager',
                'next_number' => 2,
                'pad_length' => 3,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('code_series_id');
        });
        Schema::dropIfExists('code_series');
    }
};
