<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('days', function (Blueprint $table) {
            $table->boolean('edit_unlocked')->default(false)->after('plan_submitted_at');
            $table->timestamp('morning_prompted_at')->nullable()->after('edit_unlocked');
            $table->timestamp('evening_prompted_at')->nullable()->after('morning_prompted_at');
        });
    }

    public function down(): void
    {
        Schema::table('days', function (Blueprint $table) {
            $table->dropColumn(['edit_unlocked', 'morning_prompted_at', 'evening_prompted_at']);
        });
    }
};
