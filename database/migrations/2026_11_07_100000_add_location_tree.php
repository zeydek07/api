<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hastane konsinye lokasyonları ağaç yapısına geçer:
 * hastane › bölüm › oda / depo › dolap › raf.
 * path: "Ortopedi › Ameliyathane 2 › Dolap A" (listelerde tam yol için saklanır).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->after('hospital_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('level', 20)->nullable()->after('parent_id');
            $table->string('path', 500)->nullable()->after('name');
        });

        // Mevcut lokasyonlar hastanenin en üst seviyesinde "depo" olarak kalır
        DB::table('warehouses')->where('type', 'consignment')->update(['level' => 'depo', 'path' => DB::raw('name')]);
    }

    public function down(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn(['level', 'path']);
        });
    }
};
