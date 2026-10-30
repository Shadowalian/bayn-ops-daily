<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weekly_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('week_start');
            $table->string('status', 20)->default('pending');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'week_start']);
            $table->index('status');
        });

        Schema::table('weekly_goals', function (Blueprint $table) {
            $table->foreignId('weekly_plan_id')->nullable()->after('id')->constrained('weekly_plans')->cascadeOnDelete();
            $table->string('source', 20)->default('self')->after('success_measure');
            $table->foreignId('assigned_by')->nullable()->after('source')->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('position')->default(0)->after('assigned_by');
            $table->text('review_note')->nullable()->after('position');
            $table->text('remove_request_note')->nullable()->after('review_note');
        });

        DB::statement('ALTER TABLE weekly_goals DROP CONSTRAINT IF EXISTS weekly_goals_status_check');

        DB::table('weekly_goals')->whereIn('status', ['on_track', 'at_risk', 'done'])->update(['status' => 'approved']);
        DB::table('weekly_goals')->where('status', 'dropped')->update(['status' => 'removed']);

        DB::statement("ALTER TABLE weekly_goals ALTER COLUMN status TYPE varchar(32) USING status::text");
        DB::statement("ALTER TABLE weekly_goals ALTER COLUMN status SET DEFAULT 'pending'");
        DB::statement("ALTER TABLE weekly_goals ADD CONSTRAINT weekly_goals_status_check CHECK (status IN ('pending','approved','rejected','remove_requested','removed'))");

        Schema::table('weekly_goals', function (Blueprint $table) {
            $table->index(['owner_id', 'week_start']);
            $table->index('weekly_plan_id');
        });
    }

    public function down(): void
    {
        Schema::table('weekly_goals', function (Blueprint $table) {
            $table->dropIndex(['owner_id', 'week_start']);
            $table->dropIndex(['weekly_plan_id']);
            $table->dropConstrainedForeignId('weekly_plan_id');
            $table->dropConstrainedForeignId('assigned_by');
            $table->dropColumn(['source', 'position', 'review_note', 'remove_request_note']);
        });

        DB::statement('ALTER TABLE weekly_goals DROP CONSTRAINT IF EXISTS weekly_goals_status_check');
        Schema::dropIfExists('weekly_plans');
    }
};
