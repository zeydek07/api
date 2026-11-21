<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_sequences', function (Blueprint $t) {
            $t->string('key', 100)->primary();
            $t->unsignedBigInteger('next_value');
        });

        Schema::create('hr_idempotency_keys', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $key = $t->string('key', 100);
            $t->string('route', 255);
            $t->char('request_hash', 64);
            $t->unsignedSmallInteger('status_code')->nullable();
            $t->string('resource_type', 100)->nullable();
            $t->unsignedBigInteger('resource_id')->nullable();
            $t->dateTime('expires_at')->index();

            // MySQL varsayılan karşılaştırması büyük/küçük harfi yok sayar; anahtarlar birebir eşleşmeli.
            if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                $key->collation('utf8mb4_bin');
            }

            $t->unique(['user_id', 'key', 'route']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_idempotency_keys');
        Schema::dropIfExists('hr_sequences');
    }
};
