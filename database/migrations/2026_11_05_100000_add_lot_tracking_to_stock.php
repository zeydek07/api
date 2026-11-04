<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stok artık "depo + ürün" değil "depo + ürün + lot/seri + SKT" bazında tutulur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Karekoddaki ürün numarası (GS1 GTIN / UDI-DI), 14 hane
            $table->string('gtin', 14)->nullable()->unique()->after('barcode');
            // none: takipsiz · lot: lot numarasıyla · serial: her birim seri numarasıyla
            $table->string('tracking', 10)->default('lot')->after('gtin');
        });

        Schema::table('stocks', function (Blueprint $table) {
            $table->string('lot_number', 100)->nullable()->after('product_id');
            $table->string('serial_number', 100)->nullable()->after('lot_number');
            $table->date('expiry_date')->nullable()->after('serial_number');
            // lot/seri/SKT'nin birleşik anahtarı; NULL'lu benzersiz indeks sorununu önler
            $table->string('lot_key', 220)->default('L:|')->after('expiry_date');
            $table->index(['expiry_date']);
        });

        // Yeni benzersiz indeksi önce ekle (warehouse_id yabancı anahtarı buna dayanabilsin), sonra eskisini kaldır
        Schema::table('stocks', function (Blueprint $table) {
            $table->unique(['warehouse_id', 'product_id', 'lot_key']);
        });
        Schema::table('stocks', function (Blueprint $table) {
            $table->dropUnique(['warehouse_id', 'product_id']);
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->string('serial_number', 100)->nullable()->after('lot_number');
            $table->index(['serial_number']);
        });

        DB::table('stocks')->update(['lot_key' => 'L:|']);
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex(['serial_number']);
            $table->dropColumn('serial_number');
        });
        Schema::table('stocks', function (Blueprint $table) {
            $table->unique(['warehouse_id', 'product_id']);
        });
        Schema::table('stocks', function (Blueprint $table) {
            $table->dropUnique(['warehouse_id', 'product_id', 'lot_key']);
            $table->dropIndex(['expiry_date']);
            $table->dropColumn(['lot_number', 'serial_number', 'expiry_date', 'lot_key']);
        });
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['gtin']);
            $table->dropColumn(['gtin', 'tracking']);
        });
    }
};
