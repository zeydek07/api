<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('description')->nullable();
            $table->boolean('is_system')->default(false); // silinemez roller
            $table->timestamps();
        });

        // Her kayıt bir "sayfa + işlem" yetkisidir: products.view, products.create ...
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('page');
            $table->string('action');
            $table->string('label');
            $table->timestamps();
        });

        Schema::create('permission_role', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->primary(['role_id', 'permission_id']);
        });

        // Rolden bağımsız, kişiye özel ek yetkiler
        Schema::create('permission_user', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->primary(['user_id', 'permission_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 30)->nullable()->after('email');
            $table->foreignId('role_id')->nullable()->after('password')->constrained()->nullOnDelete();
            $table->foreignId('manager_id')->nullable()->after('role_id')->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true)->after('manager_id');
            $table->timestamp('last_login_at')->nullable();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('role_id');
            $table->dropConstrainedForeignId('manager_id');
            $table->dropColumn(['phone', 'is_active', 'last_login_at', 'deleted_at']);
        });
        Schema::dropIfExists('permission_user');
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
