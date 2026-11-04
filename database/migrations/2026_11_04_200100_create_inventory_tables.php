<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();          // ürün / stok kodu
            $table->string('barcode')->nullable()->unique();
            $table->string('category')->nullable();
            $table->string('brand')->nullable();
            $table->string('unit', 20)->default('adet');
            $table->text('description')->nullable();
            $table->unsignedInteger('min_stock_level')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        // Merkez depo, şube deposu veya bir personelin çantası/aracı
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('type', 20)->default('central'); // central | branch | field
            $table->string('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Hangi personel hangi depodan ürün düşebilir
        Schema::create('user_warehouse', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->primary(['user_id', 'warehouse_id']);
        });

        Schema::create('stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 12, 2)->default(0);
            $table->timestamps();
            $table->unique(['warehouse_id', 'product_id']);
        });

        // Stoktaki her değişikliğin kaydı. Güncellenmez, silinmez.
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->uuid('batch_uuid')->index();       // aynı işlemdeki satırları gruplar
            $table->string('type', 20);                // in | usage | transfer_out | transfer_in | adjustment
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 12, 2);        // + giriş, - çıkış
            $table->decimal('balance_after', 12, 2);
            $table->string('lot_number')->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('reference')->nullable();   // hasta / kurum / fatura no
            $table->string('note', 1000)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
            $table->index(['product_id', 'created_at']);
            $table->index(['type', 'created_at']);
        });

        // Mobil uygulama bağlantı koptuğunda aynı isteği tekrar gönderirse
        // ikinci kez stok düşülmesin diye istemcinin ürettiği anahtar.
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->uuid('batch_uuid');
            $table->timestamps();
            $table->unique(['user_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stocks');
        Schema::dropIfExists('user_warehouse');
        Schema::dropIfExists('warehouses');
        Schema::dropIfExists('products');
    }
};
