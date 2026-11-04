<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hastane kaydındaki "hastanedeki ilgili kişi" ve "telefon" alanları,
 * firma personeliyle karışabileceği için kaldırıldı.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hospitals', function (Blueprint $table) {
            $table->dropColumn(['contact_person', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::table('hospitals', function (Blueprint $table) {
            $table->string('phone', 30)->nullable()->after('address');
            $table->string('contact_person')->nullable()->after('phone');
        });
    }
};
