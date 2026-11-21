<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Shared\HrException;
use App\Modules\Hr\Shared\Idempotency\IdempotencyKey;
use App\Modules\Hr\Shared\Idempotency\IdempotentResourceRegistry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\UploadedFile as SymfonyUploadedFile;
use Tests\Feature\ApiTestCase;

class IdempotencyTest extends ApiTestCase
{
    private const URL = '/api/hr-test-idem/5';

    private const KEY = 'test-key-12345678';

    private int $calls = 0;

    /** Handler davranışı: ok | fail | rollback | no_resource | unregistered */
    private string $mode = 'ok';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->userWithPermissions(['hr_self.view']);

        app(IdempotentResourceRegistry::class)->register('thing', function (int $id, Request $r) {
            abort_unless($r->user()->hasPermission('hr_self.view'), 403);

            return response()->json(['id' => $id, 'replayed' => true]);
        });

        Route::middleware(['api', 'auth:sanctum', 'active', 'hr.idempotent'])
            ->post('api/hr-test-idem/{id}', function (Request $r, int $id) {
                $this->calls++;

                match ($this->mode) {
                    'fail' => throw new HrException('test.failed', 'Başarısız.', 409),
                    'rollback' => DB::transaction(fn () => throw new HrException('test.failed', 'Başarısız.', 409)),
                    default => null,
                };

                if ($this->mode !== 'no_resource') {
                    $type = $this->mode === 'unregistered' ? 'unknown' : 'thing';
                    $r->attributes->set('hr.resource', ['type' => $type, 'id' => $id]);
                }

                return response()->json(['id' => $id, 'replayed' => false], 201);
            });
    }

    private function send(array $body = ['a' => 1], string $key = self::KEY, string $url = self::URL)
    {
        return $this->actingAs($this->user, 'sanctum')
            ->postJson($url, $body, ['Idempotency-Key' => $key]);
    }

    public function test_request_without_header_runs_normally(): void
    {
        $this->actingAs($this->user, 'sanctum')->postJson(self::URL, ['a' => 1])->assertCreated();
        $this->actingAs($this->user, 'sanctum')->postJson(self::URL, ['a' => 1])->assertCreated();

        $this->assertSame(2, $this->calls);
        $this->assertSame(0, IdempotencyKey::count());
    }

    public function test_same_key_same_body_replays_without_side_effect(): void
    {
        $this->send()->assertCreated()->assertJsonPath('replayed', false);
        $this->send()->assertCreated()->assertJsonPath('replayed', true)->assertJsonPath('id', 5);

        $this->assertSame(1, $this->calls);
    }

    public function test_json_key_order_does_not_change_the_hash(): void
    {
        $this->send(['a' => 1, 'b' => ['x' => 1, 'y' => [1, 2]]])->assertCreated();
        $this->send(['b' => ['y' => [1, 2], 'x' => 1], 'a' => 1])->assertCreated()->assertJsonPath('replayed', true);

        $this->assertSame(1, $this->calls);
    }

    public function test_list_order_changes_the_hash(): void
    {
        $this->send(['ids' => [1, 2]])->assertCreated();
        $this->send(['ids' => [2, 1]])->assertStatus(422)->assertJsonPath('code', 'idempotency.key_reused');
    }

    public function test_same_key_different_body_returns_422(): void
    {
        $this->send(['a' => 1])->assertCreated();
        $this->send(['a' => 2])->assertStatus(422)
            ->assertJsonPath('code', 'idempotency.key_reused')
            ->assertJsonStructure(['message', 'code', 'errors']);

        $this->assertSame(1, $this->calls);
    }

    public function test_same_route_pattern_with_different_path_returns_422(): void
    {
        $this->send()->assertCreated();
        $this->send(url: '/api/hr-test-idem/6')->assertStatus(422)->assertJsonPath('code', 'idempotency.key_reused');

        $this->assertSame(1, $this->calls);
    }

    public function test_query_string_is_part_of_the_hash_regardless_of_order(): void
    {
        $this->send(url: self::URL.'?a=1&b=2')->assertCreated();
        $this->send(url: self::URL.'?b=2&a=1')->assertCreated()->assertJsonPath('replayed', true);
        $this->send(url: self::URL.'?a=1&b=3')->assertStatus(422)->assertJsonPath('code', 'idempotency.key_reused');
        $this->send()->assertStatus(422)->assertJsonPath('code', 'idempotency.key_reused');

        $this->assertSame(1, $this->calls);
    }

    public function test_prune_deletes_only_expired_rows(): void
    {
        $row = fn (string $key, ?int $status, string $expiresAt) => IdempotencyKey::create([
            'user_id' => $this->user->id, 'key' => $key, 'route' => 'POST test', 'request_hash' => str_repeat('a', 64),
            'status_code' => $status, 'expires_at' => $expiresAt,
        ]);
        $past = now()->subMinute()->toDateTimeString();
        $future = now()->addHour()->toDateTimeString();

        $row('expired-done', 201, $past);
        $row('expired-open', null, $past);
        $row('active-done', 201, $future);
        $row('active-open', null, $future);

        $this->artisan('hr:prune-idempotency')->assertSuccessful();

        $this->assertEqualsCanonicalizing(['active-done', 'active-open'], IdempotencyKey::pluck('key')->all());
    }

    public function test_prune_is_scheduled_daily(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'hr:prune-idempotency'));

        $this->assertNotNull($event);
        $this->assertSame('0 0 * * *', $event->expression);
    }

    public function test_multipart_hash_covers_file_content(): void
    {
        $post = fn (string $content) => $this->actingAs($this->user, 'sanctum')->post(self::URL, [
            'note' => 'aynı',
            'file' => UploadedFile::fake()->createWithContent('belge.txt', $content),
        ], ['Idempotency-Key' => self::KEY, 'Accept' => 'application/json']);

        $post('birinci')->assertCreated();
        $post('birinci')->assertCreated()->assertJsonPath('replayed', true);
        $post('ikinci')->assertStatus(422)->assertJsonPath('code', 'idempotency.key_reused');

        $this->assertSame(1, $this->calls);
    }

    /** Gerçek isteklerde dosya torbası Illuminate değil, Symfony UploadedFile tutar. */
    private function realUpload(string $content, int $error = UPLOAD_ERR_OK): SymfonyUploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'hr-idem-');
        file_put_contents($path, $content);
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        return new SymfonyUploadedFile($path, 'belge.txt', 'text/plain', $error, true);
    }

    private function sendFiles(array $files)
    {
        return $this->actingAs($this->user, 'sanctum')->call('POST', self::URL, ['note' => 'aynı'], [], $files, [
            'HTTP_IDEMPOTENCY_KEY' => self::KEY,
            'HTTP_ACCEPT' => 'application/json',
        ]);
    }

    public function test_real_symfony_upload_is_hashed_by_content(): void
    {
        $this->sendFiles(['file' => $this->realUpload('birinci')])->assertCreated();
        $this->sendFiles(['file' => $this->realUpload('birinci')])->assertCreated()->assertJsonPath('replayed', true);
        $this->sendFiles(['file' => $this->realUpload('ikinci')])
            ->assertStatus(422)->assertJsonPath('code', 'idempotency.key_reused');

        $this->assertSame(1, $this->calls);
    }

    public function test_nested_real_uploads_are_hashed_by_content(): void
    {
        $files = fn (string $second) => ['docs' => ['a' => [$this->realUpload('sabit'), $this->realUpload($second)]]];

        $this->sendFiles($files('birinci'))->assertCreated();
        $this->sendFiles($files('birinci'))->assertCreated()->assertJsonPath('replayed', true);
        $this->sendFiles($files('ikinci'))->assertStatus(422)->assertJsonPath('code', 'idempotency.key_reused');

        $this->assertSame(1, $this->calls);
    }

    public function test_invalid_upload_never_matches_another_request(): void
    {
        $this->sendFiles(['file' => $this->realUpload('aynı', UPLOAD_ERR_PARTIAL)])->assertCreated();
        $this->sendFiles(['file' => $this->realUpload('aynı', UPLOAD_ERR_PARTIAL)])
            ->assertStatus(422)->assertJsonPath('code', 'idempotency.key_reused');

        $this->assertSame(1, $this->calls);
    }

    public function test_failed_request_can_be_retried_with_same_key(): void
    {
        foreach (['fail', 'rollback'] as $mode) {
            $this->mode = $mode;
            $this->send()->assertStatus(409)->assertJsonPath('code', 'test.failed');
            $this->assertSame(0, IdempotencyKey::count());
        }

        $this->mode = 'ok';
        $this->send()->assertCreated()->assertJsonPath('replayed', false);

        $this->assertSame(3, $this->calls);
    }

    public function test_thrown_exception_propagates_unchanged_and_clears_reservation(): void
    {
        $this->withoutExceptionHandling();
        $this->mode = 'fail';

        try {
            $this->send();
            $this->fail('İstisna yayılmalıydı.');
        } catch (HrException $e) {
            $this->assertSame('test.failed', $e->errorCode);
        }

        $this->assertSame(0, IdempotencyKey::count());
    }

    public function test_key_is_scoped_to_user(): void
    {
        $this->send()->assertCreated();

        $this->user = $this->userWithPermissions(['hr_self.view']);
        $this->send()->assertCreated()->assertJsonPath('replayed', false);

        $this->assertSame(2, $this->calls);
    }

    public function test_replay_rechecks_authorization(): void
    {
        $this->send()->assertCreated();

        $this->user->role->permissions()->detach();
        $this->user->flushPermissionCache();

        $this->send()->assertForbidden();
        $this->assertSame(1, $this->calls);
    }

    public function test_invalid_key_length_returns_422(): void
    {
        $this->send(key: 'short')->assertStatus(422)->assertJsonPath('code', 'idempotency.invalid_key');
        $this->send(key: str_repeat('a', 101))->assertStatus(422)->assertJsonPath('code', 'idempotency.invalid_key');

        $this->assertSame(0, $this->calls);
    }

    public function test_keys_differing_only_by_case_are_distinct(): void
    {
        $this->send(key: 'case-key-abcdef')->assertCreated();
        $this->send(key: 'CASE-KEY-ABCDEF')->assertCreated()->assertJsonPath('replayed', false);

        $this->assertSame(2, $this->calls);
    }

    public function test_success_without_resource_is_an_error_and_clears_reservation(): void
    {
        $this->mode = 'no_resource';

        $this->send()->assertStatus(500)->assertJsonPath('code', 'idempotency.resource_missing');

        $this->assertSame(0, IdempotencyKey::count());
    }

    public function test_replay_without_registered_resolver_fails(): void
    {
        $this->mode = 'unregistered';
        $this->send()->assertCreated();

        $this->send()->assertStatus(500)->assertJsonPath('code', 'idempotency.resolver_missing');

        $this->assertSame(1, $this->calls);
    }

    public function test_active_reservation_returns_409(): void
    {
        $this->send()->assertCreated();
        IdempotencyKey::query()->update(['status_code' => null, 'expires_at' => now()->addHour()->toDateTimeString()]);

        $this->send()->assertStatus(409)->assertJsonPath('code', 'idempotency.in_progress');

        $this->assertSame(1, $this->calls);
    }

    public function test_stale_reservation_is_taken_over(): void
    {
        $this->send()->assertCreated();
        IdempotencyKey::query()->update(['status_code' => null, 'expires_at' => now()->subMinute()->toDateTimeString()]);

        $this->send()->assertCreated()->assertJsonPath('replayed', false);

        $this->assertSame(2, $this->calls);
        $row = IdempotencyKey::sole();
        $this->assertSame(201, (int) $row->status_code);
        $this->assertGreaterThan(now()->toDateTimeString(), $row->expires_at);
    }

    public function test_handler_that_lost_its_reservation_does_not_overwrite_the_new_owner(): void
    {
        Route::middleware(['api', 'auth:sanctum', 'active', 'hr.idempotent'])
            ->post('api/hr-test-idem-fence', function (Request $r) {
                // Bu istek çalışırken rezervasyon başka bir isteğe geçmiş olsun.
                IdempotencyKey::query()->update(['expires_at' => now()->addDays(2)->toDateTimeString()]);
                $r->attributes->set('hr.resource', ['type' => 'thing', 'id' => 9]);

                return response()->json(['id' => 9], 201);
            });

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/hr-test-idem-fence', [], ['Idempotency-Key' => self::KEY])
            ->assertCreated();

        $this->assertNull(IdempotencyKey::sole()->status_code);
    }

    public function test_unexpected_exception_clears_reservation(): void
    {
        Route::middleware(['api', 'auth:sanctum', 'active', 'hr.idempotent'])
            ->post('api/hr-test-idem-crash', fn () => throw new RuntimeException('beklenmeyen'));

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/hr-test-idem-crash', [], ['Idempotency-Key' => self::KEY])
            ->assertStatus(500);

        $this->assertSame(0, IdempotencyKey::count());
    }
}
