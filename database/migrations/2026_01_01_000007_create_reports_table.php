<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $t) {
            $t->id();
            $t->string('code', 20)->unique();   // RPT-1001
            $t->string('category', 40)->index();
            $t->foreignId('barangay_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('location_text');
            $t->text('description')->nullable();
            $t->decimal('lat', 10, 7);
            $t->decimal('lng', 10, 7);
            $t->string('photo_path')->nullable();
            $t->string('before_photo')->nullable();
            $t->string('after_photo')->nullable();
            $t->string('status', 20)->default('Submitted')->index();
            $t->foreignId('assigned_truck_id')->nullable()->constrained('trucks')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('report_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('report_id')->constrained()->cascadeOnDelete();
            $t->string('status', 20);
            $t->string('message');
            $t->date('logged_on');
            $t->timestamps();
        });

        Schema::create('collection_notes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('truck_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('note');
            $t->string('photo_path')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_notes');
        Schema::dropIfExists('report_logs');
        Schema::dropIfExists('reports');
    }
};
