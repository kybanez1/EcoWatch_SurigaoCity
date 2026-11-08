<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // New columns for the assign -> cleanup -> verify workflow.
        Schema::table('reports', function (Blueprint $table) {
            $table->foreignId('collector_id')->nullable()->after('assigned_truck_id')->constrained('users')->nullOnDelete();
            $table->date('target_date')->nullable();
            $table->text('instructions')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('collector_remarks', 500)->nullable();
            $table->string('rejection_reason', 255)->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->string('resident_feedback', 20)->nullable();
            $table->timestamp('feedback_at')->nullable();
        });

        // One row for every time a collector is assigned or reassigned.
        Schema::create('report_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained()->cascadeOnDelete();
            $table->foreignId('collector_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('truck_id')->nullable()->constrained('trucks')->nullOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('target_date')->nullable();
            $table->text('instructions')->nullable();
            $table->string('reason', 255)->nullable();
            $table->timestamps();
        });

        // One row for every cleanup submission and every approve/reject decision.
        Schema::create('report_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained()->cascadeOnDelete();
            $table->string('action', 20); // Submitted, Approved, Rejected
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('remarks', 500)->nullable();
            $table->string('before_photo')->nullable();
            $table->string('after_photo')->nullable();
            $table->timestamps();
        });

        // Old statuses are renamed (no records are deleted).
        DB::table('reports')->where('status', 'Submitted')->update(['status' => 'Pending']);
        DB::table('reports')->where('status', 'Reviewed')->update(['status' => 'Pending']);
        DB::table('reports')->where('status', 'Closed')->update(['status' => 'Resolved']);
        DB::table('report_logs')->where('status', 'Submitted')->update(['status' => 'Pending']);
        DB::table('report_logs')->where('status', 'Reviewed')->update(['status' => 'Pending']);
        DB::table('report_logs')->where('status', 'Closed')->update(['status' => 'Resolved']);
    }

    public function down(): void
    {
        DB::table('reports')->where('status', 'Pending')->update(['status' => 'Submitted']);
        DB::table('reports')->where('status', 'Resolved')->update(['status' => 'Closed']);
        DB::table('reports')->where('status', 'Reopened')->update(['status' => 'In Progress']);
        Schema::dropIfExists('report_reviews');
        Schema::dropIfExists('report_assignments');
        Schema::table('reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('collector_id');
            $table->dropConstrainedForeignId('verified_by');
            $table->dropColumn(['target_date', 'instructions', 'assigned_at', 'started_at', 'submitted_at', 'verified_at',
                'collector_remarks', 'rejection_reason', 'rejected_at', 'resident_feedback', 'feedback_at']);
        });
    }
};
