<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_audit_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('actor_user_id')->nullable();
            $t->string('actor_channel', 20);
            $t->string('action', 100);
            $t->string('subject_type', 100);
            $t->unsignedBigInteger('subject_id');
            $t->unsignedBigInteger('employee_id')->nullable();
            $t->json('changed_fields');
            $t->string('ip', 45)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->char('request_id', 36)->nullable();
            $t->timestamp('created_at')->useCurrent();

            $t->index(['subject_type', 'subject_id']);
            $t->index(['employee_id', 'created_at']);
            $t->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_audit_logs');
    }
};
