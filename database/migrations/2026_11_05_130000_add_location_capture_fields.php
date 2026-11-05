<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sahadaki işlemlerde (teslimat, geri alma, kullanım) konum işlem anında alınır.
 * Alınamazsa nedeni yazılır; böylece konumsuz kayıt raporda ayrıca görünür.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['deliveries', 'stock_movements'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->unsignedInteger('location_accuracy')->nullable()->after('longitude');   // metre
                $t->string('location_error', 20)->nullable()->after('location_accuracy');  // denied | unavailable | timeout | unsupported | not_recorded
                $t->index('location_error');
            });
        }

        // Bu kuraldan önce konumsuz kaydedilmiş saha işlemleri
        DB::table('deliveries')->whereNull('latitude')->update(['location_error' => 'not_recorded']);
        DB::table('stock_movements')->whereNull('latitude')
            ->whereIn('type', ['usage', 'consign_out', 'consign_in', 'return_out', 'return_in'])
            ->update(['location_error' => 'not_recorded']);
    }

    public function down(): void
    {
        foreach (['deliveries', 'stock_movements'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropIndex(['location_error']);
                $t->dropColumn(['location_accuracy', 'location_error']);
            });
        }
    }
};
