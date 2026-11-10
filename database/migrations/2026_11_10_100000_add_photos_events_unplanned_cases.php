<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * - proof_photos: vaka ve teslimat belgelerinde birden fazla etiketli fotoğraf
 *   (eski tek fotoğraf kolonundaki kayıtlar buraya taşınır)
 * - surgery_cases.is_unplanned: plan yapılmadan doğrudan kaydedilen (acil) vaka
 * - surgery_case_events: vaka geçmişi (kim, ne zaman, ne yaptı)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proof_photos', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type', 30);
            $table->unsignedBigInteger('owner_id');
            $table->string('kind', 30)->default('other');
            $table->string('path');
            $table->unsignedInteger('bytes')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['owner_type', 'owner_id']);
        });

        Schema::table('surgery_cases', function (Blueprint $table) {
            $table->boolean('is_unplanned')->default(false)->after('status');
        });

        Schema::create('surgery_case_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('surgery_case_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 30);
            $table->json('data')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['surgery_case_id', 'id']);
        });

        // Eski tek fotoğrafları taşı
        foreach (['surgery_case' => 'surgery_cases', 'delivery' => 'deliveries'] as $type => $table) {
            DB::table($table)->whereNotNull('photo_path')->orderBy('id')->each(function ($row) use ($type, $table) {
                DB::table('proof_photos')->insert([
                    'owner_type' => $type,
                    'owner_id' => $row->id,
                    'kind' => 'other',
                    'path' => $row->photo_path,
                    'bytes' => 0,
                    'created_by' => $table === 'surgery_cases' ? ($row->recorded_by ?? $row->user_id) : $row->user_id,
                    'created_at' => $row->updated_at,
                    'updated_at' => $row->updated_at,
                ]);
                DB::table($table)->where('id', $row->id)->update(['photo_path' => null]);
            });
        }
    }

    public function down(): void
    {
        // İlk fotoğrafı eski kolona geri yaz
        foreach (['surgery_case' => 'surgery_cases', 'delivery' => 'deliveries'] as $type => $table) {
            DB::table('proof_photos')->where('owner_type', $type)->orderBy('id')->get()->unique('owner_id')
                ->each(fn ($p) => DB::table($table)->where('id', $p->owner_id)->update(['photo_path' => $p->path]));
        }
        Schema::dropIfExists('surgery_case_events');
        Schema::table('surgery_cases', fn (Blueprint $table) => $table->dropColumn('is_unplanned'));
        Schema::dropIfExists('proof_photos');
    }
};
