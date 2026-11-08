<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trucks', function (Blueprint $t) {
            $t->id();
            $t->string('truck_code', 20)->unique();      // e.g. TRUCK-001
            $t->string('plate_number', 20)->unique();
            $t->decimal('capacity_tons', 5, 1)->default(0);
            $t->string('status', 20)->default('Available')->index(); // Available, On Route, Maintenance
            $t->string('color', 7)->default('#2F9E44');
            $t->foreignId('collector_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trucks');
    }
};
