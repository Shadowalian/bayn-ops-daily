<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->index(['day_id', 'result'], 'tasks_day_id_result_index');
            $table->index('user_id', 'tasks_user_id_perf_index');
        });

        Schema::table('days', function (Blueprint $table) {
            $table->index('date', 'days_date_perf_index');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->index('telegram_chat_id', 'users_telegram_chat_id_index');
            $table->index(['active', 'role'], 'users_active_role_index');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex('tasks_day_id_result_index');
            $table->dropIndex('tasks_user_id_perf_index');
        });

        Schema::table('days', function (Blueprint $table) {
            $table->dropIndex('days_date_perf_index');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_telegram_chat_id_index');
            $table->dropIndex('users_active_role_index');
        });
    }
};
