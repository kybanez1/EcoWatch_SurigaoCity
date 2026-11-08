<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            // One exact pickup time such as 07:00:00. Old schedules stay NULL until the CGSO sets the time.
            $table->time('pickup_time')->nullable()->after('days');
            // The old time range text is kept only as a reference for old records.
            $table->string('time_range', 40)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            $table->dropColumn('pickup_time');
        });
    }
};
