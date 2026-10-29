<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('job_title', 120)->nullable()->after('role');
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->string('source', 20)->default('self')->after('tier');
            $table->foreignId('assigned_by')->nullable()->after('source')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_by');
            $table->dropColumn('source');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('job_title');
        });
    }
};
