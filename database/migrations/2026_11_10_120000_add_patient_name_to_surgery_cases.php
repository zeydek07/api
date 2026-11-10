<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('surgery_cases', function (Blueprint $table) {
            $table->string('patient_name', 120)->nullable()->after('protocol_no'); // hasta adı soyadı (isteğe bağlı)
        });
    }

    public function down(): void
    {
        Schema::table('surgery_cases', function (Blueprint $table) {
            $table->dropColumn('patient_name');
        });
    }
};
