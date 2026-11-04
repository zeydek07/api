<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Konsinye teslimat belgesi: mümessilin aracından hastanedeki lokasyona ürün bırakma
 * (delivery) ya da hastaneden araca geri alma (return). Ürün satırları stock_movements'tadır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->nullable()->unique();     // TS-2026-000001 / GA-2026-000001
            $table->string('type', 10);                             // delivery | return
            $table->string('status', 12)->default('completed');     // completed | cancelled
            $table->foreignId('hospital_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained('warehouses')->restrictOnDelete();   // hastanedeki konsinye lokasyonu
            $table->foreignId('vehicle_id')->constrained('warehouses')->restrictOnDelete();    // mümessilin aracı / depo
            $table->foreignId('contact_id')->nullable()->constrained('hospital_contacts')->nullOnDelete();
            // Teslim alanın (geri almada: teslim edenin) o anki adı ve görevi; kişi kaydı değişse de belge değişmez
            $table->string('receiver_name');
            $table->string('receiver_title', 120)->nullable();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();                   // teslim eden firma personeli
            // dateTime: MariaDB/MySQL'de NOT NULL timestamp her güncellemede o anki saate dönebilir
            $table->dateTime('delivered_at');
            $table->dateTime('device_time')->nullable();          // cihazın saati (internetsiz girişte farklı olabilir)
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('signature_path')->nullable();
            $table->string('photo_path')->nullable();
            $table->string('note', 1000)->nullable();
            $table->uuid('batch_uuid')->index();
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamps();
            $table->index(['hospital_id', 'delivered_at']);
            $table->index(['user_id', 'delivered_at']);
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('delivery_id')->nullable()->after('hospital_id')->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('delivery_id');
        });
        Schema::dropIfExists('deliveries');
    }
};
