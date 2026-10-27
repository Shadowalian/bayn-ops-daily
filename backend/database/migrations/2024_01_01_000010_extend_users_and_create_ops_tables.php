<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('telegram_chat_id')->nullable()->after('email');
            $table->enum('role', ['member', 'manager'])->default('member')->after('telegram_chat_id');
            $table->boolean('active')->default(true)->after('role');
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value');
            $table->timestamps();
        });

        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('leave_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->enum('type', ['leave', 'off', 'half_day']);
            $table->string('note', 500)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'date']);
        });

        Schema::create('weekly_goals', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->date('week_start');
            $table->string('success_measure')->nullable();
            $table->enum('status', ['on_track', 'at_risk', 'done', 'dropped'])->default('on_track');
            $table->timestamps();
        });

        Schema::create('days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->enum('work_status', ['working', 'remote', 'half_day', 'off', 'leave', 'absent'])->default('working');
            $table->timestamp('check_in_at')->nullable();
            $table->timestamp('check_out_at')->nullable();
            $table->boolean('late')->default(false);
            $table->boolean('late_excused')->default(false);
            $table->timestamp('plan_submitted_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->text('day_note')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'date']);
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('day_id')->constrained('days')->cascadeOnDelete();
            $table->string('title', 200);
            $table->enum('tier', ['must', 'support'])->default('must');
            $table->unsignedTinyInteger('position')->default(0);
            $table->enum('result', ['planned', 'done', 'partial', 'not_done', 'dropped'])->default('planned');
            $table->string('reason_code')->nullable();
            $table->string('reason_note', 500)->nullable();
            $table->boolean('blocked')->default(false);
            $table->string('blocker_note', 500)->nullable();
            $table->enum('effort', ['S', 'M', 'L'])->nullable();
            $table->boolean('added_after_cutoff')->default(false);
            $table->foreignId('carried_from_task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->unsignedInteger('carry_count')->default(0);
            $table->foreignId('weekly_goal_id')->nullable()->constrained('weekly_goals')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('entity');
            $table->unsignedBigInteger('entity_id');
            $table->string('field');
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->string('note')->nullable();
            $table->timestamp('changed_at');
            $table->index(['entity', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('days');
        Schema::dropIfExists('weekly_goals');
        Schema::dropIfExists('leave_days');
        Schema::dropIfExists('holidays');
        Schema::dropIfExists('settings');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['telegram_chat_id', 'role', 'active']);
        });
    }
};
