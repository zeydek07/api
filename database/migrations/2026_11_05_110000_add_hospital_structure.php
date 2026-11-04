<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hastane yapısı:
 * - Konsinye lokasyonu: hastanede konsinye ürünlerin durduğu yer (bir depo türü: "consignment")
 * - Hastane personeli: teslim alan / muhatap kişiler (tekrar kullanılabilir kayıt)
 * - Sorumlu mümessil: hastaneye bakan firma personeli
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->foreignId('hospital_id')->nullable()->after('type')->constrained()->restrictOnDelete();
        });

        Schema::table('hospitals', function (Blueprint $table) {
            $table->foreignId('responsible_user_id')->nullable()->after('type')->constrained('users')->nullOnDelete();
        });

        Schema::create('hospital_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->string('name');
            $table->string('title', 120)->nullable();   // görevi: sorumlu hemşire, satın alma...
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['hospital_id', 'name']);
        });

        // Mevcut her hastaneye bir "Ana konsinye deposu"
        foreach (DB::table('hospitals')->get(['id']) as $h) {
            DB::table('warehouses')->insert([
                'name' => 'Ana konsinye deposu', 'code' => 'KNS-'.$h->id.'-1', 'type' => 'consignment',
                'hospital_id' => $h->id, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hospital_contacts');
        Schema::table('hospitals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('responsible_user_id');
        });
        DB::table('warehouses')->where('type', 'consignment')->delete();
        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('hospital_id');
        });
    }
};
