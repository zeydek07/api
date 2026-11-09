<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Teslimat belgesinin PDF kopyası hastane personeline e-postayla gider.
 * Son gönderimin sonucu belgede saklanır: kime, ne zaman, başarılı mı.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->string('email_to')->nullable()->after('photo_path');
            $table->string('email_status', 10)->nullable()->after('email_to');   // sent | failed | null (gönderilmedi)
            $table->dateTime('emailed_at')->nullable()->after('email_status');
            $table->string('email_error', 500)->nullable()->after('emailed_at');
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropColumn(['email_to', 'email_status', 'emailed_at', 'email_error']);
        });
    }
};
