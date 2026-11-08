<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Accounts are deactivated instead of deleted, so their records stay.
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true);
            $table->boolean('must_change_password')->default(false);
            $table->timestamp('deactivated_at')->nullable();
        });

        // One truck can only have one driver. Old duplicates keep the first truck only;
        // the other trucks become "no driver" so the CGSO can choose again (no truck is deleted).
        $seenDrivers = [];
        $trucks = DB::table('trucks')->whereNotNull('collector_id')->orderBy('id')->get();
        foreach ($trucks as $truck) {
            if (in_array($truck->collector_id, $seenDrivers)) {
                DB::table('trucks')->where('id', $truck->id)->update(['collector_id' => null]);
            } else {
                $seenDrivers[] = $truck->collector_id;
            }
        }
        Schema::table('trucks', function (Blueprint $table) {
            $table->unique('collector_id');
        });

        Schema::table('reports', function (Blueprint $table) {
            // One token per report form. A second submit with the same token is a duplicate.
            $table->string('submission_token', 64)->nullable()->unique();
            // Which cleanup submission (report_reviews.id) the resident feedback is about.
            $table->unsignedBigInteger('feedback_review_id')->nullable();
            $table->timestamp('feedback_reviewed_at')->nullable();
            $table->string('feedback_followup_note', 500)->nullable();
        });

        // Deleting a user must never delete the reports or notes they wrote.
        Schema::table('reports', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
        });
        Schema::table('collection_notes', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('collection_notes', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
        Schema::table('reports', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->dropUnique(['submission_token']);
            $table->dropColumn(['submission_token', 'feedback_review_id', 'feedback_reviewed_at', 'feedback_followup_note']);
        });
        Schema::table('trucks', function (Blueprint $table) {
            $table->dropUnique(['collector_id']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_active', 'must_change_password', 'deactivated_at']);
        });
    }
};
