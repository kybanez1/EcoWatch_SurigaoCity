<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('truck_routes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('truck_id')->constrained()->cascadeOnDelete();
            $t->foreignId('barangay_id')->constrained()->cascadeOnDelete();
            $t->string('name');                 // e.g. Route A
            $t->json('path')->nullable();       // [[lat,lng], ...] drawn on the map
            $t->timestamps();
            $t->unique(['truck_id', 'name']);
        });

        Schema::create('collection_areas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('truck_route_id')->constrained()->cascadeOnDelete();
            $t->string('name');                 // street / area
            $t->unsignedSmallInteger('sort')->default(0);
            $t->string('status', 12)->default('Pending'); // Pending, Collecting, Done
            $t->date('status_date')->nullable();          // status only counts for this day
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_areas');
        Schema::dropIfExists('truck_routes');
    }
};
