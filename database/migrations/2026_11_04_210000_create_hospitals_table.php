<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hospitals', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 50)->nullable()->unique();
            $table->string('type', 20)->default('public'); // public | private | university
            $table->string('city', 100)->nullable();
            $table->string('district', 100)->nullable();
            $table->string('address')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('contact_person')->nullable();   // satın alma / ilgili kişi
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['city', 'name']);
        });

        // Saha kullanımının hangi hastaneye yapıldığı
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('hospital_id')->nullable()->after('user_id')->constrained()->restrictOnDelete();
            $table->index(['hospital_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex(['hospital_id', 'created_at']);
            $table->dropConstrainedForeignId('hospital_id');
        });
        Schema::dropIfExists('hospitals');
    }
};
