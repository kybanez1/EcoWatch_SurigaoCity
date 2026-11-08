<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('truck_id')->constrained()->cascadeOnDelete();
            $t->foreignId('barangay_id')->constrained()->cascadeOnDelete();
            $t->foreignId('truck_route_id')->nullable()->constrained('truck_routes')->nullOnDelete();
            $t->json('days');                   // ["Mon","Wed","Fri"]
            $t->string('time_range', 40);       // 8:00 AM - 12:00 PM
            $t->boolean('is_active')->default(true)->index();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedules');
    }
};
