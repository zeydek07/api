<?php

namespace Tests\Feature\Hr;

use App\Modules\Hr\Shared\Audit\AuditLog;
use App\Modules\Hr\Shared\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Tests\Feature\ApiTestCase;

class AuditLogTest extends ApiTestCase
{
    public function test_record_stores_actor_ip_and_field_names_only(): void
    {
        $user = $this->userWithRole('ik-yoneticisi');
        $this->actingAs($user, 'sanctum');
        $this->app['request']->server->set('REMOTE_ADDR', '10.1.2.3');
        $this->app['request']->headers->set('User-Agent', 'TestAgent/1.0');

        $log = app(AuditLogger::class)->record('employee.updated', 'employee', 7, 7, ['iban', 'phone']);

        $this->assertSame($user->id, $log->actor_user_id);
        $this->assertSame('panel', $log->actor_channel);
        $this->assertSame('employee.updated', $log->action);
        $this->assertSame(['iban', 'phone'], $log->fresh()->changed_fields);
        $this->assertSame('10.1.2.3', $log->ip);
        $this->assertSame('TestAgent/1.0', $log->user_agent);
        $this->assertNotEmpty($log->request_id);

        $second = app(AuditLogger::class)->record('employee.viewed', 'employee', 7, 7);
        $this->assertSame($log->request_id, $second->request_id);
    }

    public function test_record_works_without_request_context(): void
    {
        $log = AuditLogger::record('system.job', 'employee', 1);

        $this->assertNull($log->actor_user_id);
        $this->assertSame([], $log->fresh()->changed_fields);
    }

    public function test_audit_row_cannot_be_updated(): void
    {
        $log = AuditLogger::record('employee.created', 'employee', 1);

        try {
            $log->update(['action' => 'x']);
            $this->fail('Güncelleme engellenmedi.');
        } catch (\RuntimeException) {
        }

        $this->assertSame('employee.created', $log->fresh()->action);
    }

    public function test_audit_row_cannot_be_deleted(): void
    {
        $log = AuditLogger::record('employee.created', 'employee', 1);

        $this->expectException(\RuntimeException::class);
        try {
            $log->delete();
        } finally {
            $this->assertDatabaseHas('hr_audit_logs', ['id' => $log->id]);
        }
    }

    public function test_audit_is_rolled_back_with_business_transaction(): void
    {
        try {
            DB::transaction(function () {
                AuditLogger::record('employee.created', 'employee', 1);
                throw new \LogicException('iş hatası');
            });
        } catch (\LogicException) {
        }

        $this->assertDatabaseCount('hr_audit_logs', 0);
    }

    public function test_audit_list_requires_permission(): void
    {
        $uzman = $this->userWithRole('ik-uzmani');
        $this->withToken($this->tokenFor($uzman))->getJson('/api/hr/audit-logs')->assertStatus(403);

        $yonetici = $this->userWithRole('ik-yoneticisi');
        $this->withToken($this->tokenFor($yonetici))->getJson('/api/hr/audit-logs')->assertOk();
    }

    public function test_audit_list_filters_by_employee_and_action(): void
    {
        AuditLogger::record('leave.approved', 'leave_request', 1, 10);
        AuditLogger::record('leave.rejected', 'leave_request', 2, 10);
        AuditLogger::record('leave.approved', 'leave_request', 3, 20);

        $token = $this->tokenFor($this->userWithRole('ik-yoneticisi'));

        $this->withToken($token)->getJson('/api/hr/audit-logs?employee_id=10&action=leave.approved')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.subject_id', 1);

        $this->withToken($token)->getJson('/api/hr/audit-logs')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.subject_id', 3);
    }

    public function test_audit_list_validates_dates_and_caps_per_page(): void
    {
        $token = $this->tokenFor($this->userWithRole('ik-yoneticisi'));

        $this->withToken($token)->getJson('/api/hr/audit-logs?from=not-a-date')->assertStatus(422);
        $this->withToken($token)->getJson('/api/hr/audit-logs?per_page=500')
            ->assertOk()->assertJsonPath('per_page', 100);
    }
}
