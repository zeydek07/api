<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vaka (ameliyat) döngüsü:
 * planlandı → (ameliyat) kaydedildi / onay bekliyor → onaylandı → satırlar faturalanır.
 * Kullanılan ve açılan ürünler stoktan düşer ve faturalanır; kullanılmayanlar yalnızca kayıttır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('title', 40)->nullable();       // Op. Dr., Doç. Dr., Prof. Dr.
            $table->string('branch', 120)->nullable();     // Ortopedi, Kardiyoloji…
            $table->string('phone', 40)->nullable();
            $table->string('email')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['hospital_id', 'is_active']);
        });

        Schema::create('surgery_cases', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->nullable()->unique();       // VK-2026-000001
            $table->string('status', 20)->default('planned');         // planned | recorded | approved | cancelled
            $table->foreignId('hospital_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('warehouses')->nullOnDelete();  // bölüm / ameliyathane
            $table->foreignId('doctor_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();          // sorumlu saha personeli
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('scheduled_at');
            $table->string('procedure', 200)->nullable();             // ameliyat türü
            $table->string('protocol_no', 60)->nullable();            // hasta protokol no (hasta adı tutulmaz)
            $table->string('note', 1000)->nullable();

            // Kayıt (ameliyat sonrası)
            $table->dateTime('performed_at')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('device_time')->nullable();
            $table->foreignId('contact_id')->nullable()->constrained('hospital_contacts')->nullOnDelete();
            $table->string('signer_name')->nullable();                // tutanağı imzalayan hemşire / doktor
            $table->string('signer_title', 120)->nullable();
            $table->string('signature_path')->nullable();
            $table->string('photo_path')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('location_accuracy')->nullable();
            $table->string('location_error', 20)->nullable();
            $table->uuid('batch_uuid')->nullable()->index();

            // Tutanağın e-posta kopyası
            $table->string('email_to')->nullable();
            $table->string('email_status', 10)->nullable();
            $table->dateTime('emailed_at')->nullable();
            $table->string('email_error', 500)->nullable();

            $table->dateTime('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'scheduled_at']);
            $table->index(['hospital_id', 'scheduled_at']);
            $table->index(['status', 'scheduled_at']);
        });

        Schema::create('surgery_case_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('surgery_case_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();    // ürünün alındığı yer: hastane dolabı ya da araç
            $table->string('status', 10);                                           // used | opened | unused
            $table->decimal('quantity', 12, 2);
            $table->string('lot_number', 100)->nullable();
            $table->string('serial_number', 100)->nullable();
            $table->date('expiry_date')->nullable();
            $table->boolean('expired_at_use')->default(false);                      // SKT'si geçmiş ürün kullanıldı (uyarı)

            // Faturalama (yalnızca used / opened satırlar)
            $table->string('invoice_number', 60)->nullable();
            $table->date('invoice_date')->nullable();
            $table->dateTime('invoiced_at')->nullable();
            $table->foreignId('invoiced_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'invoice_number']);
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('surgery_case_id')->nullable()->after('delivery_id')->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('surgery_case_id');
        });
        Schema::dropIfExists('surgery_case_items');
        Schema::dropIfExists('surgery_cases');
        Schema::dropIfExists('doctors');
    }
};
