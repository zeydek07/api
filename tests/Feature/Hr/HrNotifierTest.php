<?php

namespace Tests\Feature\Hr;

use App\Modules\Hr\Shared\Notifications\HrMessageMail;
use App\Modules\Hr\Shared\Notifications\HrNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\Feature\ApiTestCase;

class HrNotifierTest extends ApiTestCase
{
    public function test_message_is_queued_not_sent_synchronously(): void
    {
        Mail::fake();
        $user = $this->userWithRole('admin', ['email' => 'ali@example.com']);

        HrNotifier::send($user, 'Konu', 'Mesaj');

        Mail::assertQueued(HrMessageMail::class, fn (HrMessageMail $m) => $m->hasTo('ali@example.com'));
        Mail::assertNothingSent();
    }

    public function test_message_is_not_queued_when_transaction_rolls_back(): void
    {
        // Mail::fake() after-commit davranışını taklit etmez. Gerçek kuyruk (sync) + array transport
        // kullanılır: afterCommit olmasaydı mail transaction içinde anında gönderilirdi.
        $user = $this->userWithRole('admin', ['email' => 'ali@example.com']);
        $sent = fn () => count($this->app['mailer']->getSymfonyTransport()->messages());

        try {
            DB::transaction(function () use ($user, $sent) {
                HrNotifier::send($user, 'Konu', 'Mesaj');
                $this->assertSame(0, $sent(), 'Commit olmadan gönderilmemeli');
                throw new RuntimeException('iş işlemi başarısız');
            });
        } catch (RuntimeException) {
            // Beklenen.
        }

        $this->assertSame(0, $sent());

        DB::transaction(fn () => HrNotifier::send($user, 'Konu', 'Mesaj'));

        $this->assertSame(1, $sent());
    }

    public function test_inactive_user_is_skipped(): void
    {
        Mail::fake();
        $user = $this->userWithRole('admin', ['email' => 'ali@example.com', 'is_active' => false]);

        HrNotifier::send($user, 'Konu', 'Mesaj');

        Mail::assertNothingQueued();
    }

    public function test_soft_deleted_user_is_skipped(): void
    {
        Mail::fake();
        $user = $this->userWithRole('admin', ['email' => 'ali@example.com']);
        $user->delete();

        HrNotifier::send($user, 'Konu', 'Mesaj');

        Mail::assertNothingQueued();
    }

    public function test_user_without_email_is_skipped(): void
    {
        Mail::fake();
        $user = $this->userWithRole('admin');
        $user->email = '';

        HrNotifier::send($user, 'Konu', 'Mesaj');

        Mail::assertNothingQueued();
    }

    public function test_html_in_body_is_escaped_in_rendered_mail(): void
    {
        $html = (new HrMessageMail('Konu', "<script>alert(1)</script>\nİkinci <b>satır</b>"))->render();

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('<br', $html);
    }

    public function test_subject_newlines_are_stripped(): void
    {
        $mail = new HrMessageMail("Konu\r\nBcc: evil@example.com", 'Mesaj');

        $this->assertStringNotContainsString("\n", $mail->envelope()->subject);
        $this->assertStringNotContainsString("\r", $mail->envelope()->subject);
        $this->assertSame('Konu Bcc: evil@example.com', $mail->envelope()->subject);
    }

    public function test_mailable_has_retry_settings(): void
    {
        $mail = new HrMessageMail('Konu', 'Mesaj');

        $this->assertSame(3, $mail->tries);
        $this->assertSame(60, $mail->backoff);
    }
}
