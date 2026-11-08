<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            // roles: cgso, resident, official (barangay official), collector
            $t->string('role', 20)->default('resident')->index()->after('password');
            $t->foreignId('barangay_id')->nullable()->after('role')->constrained()->nullOnDelete();
            $t->string('phone', 30)->nullable()->after('barangay_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropConstrainedForeignId('barangay_id');
            $t->dropColumn(['role', 'phone']);
        });
    }
};
