<?php

namespace App\Modules\Hr\Shared\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Kuyruğa yalnızca konu ve metin girer (kullanıcı modeli serileştirilmez).
 * Çağıran, mesaja hassas veri koymaktan sorumludur.
 */
class HrMessageMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public readonly string $subjectLine;

    public function __construct(string $subject, public readonly string $body)
    {
        // Başlık enjeksiyonunu önlemek için CR/LF temizlenir.
        $this->subjectLine = trim(preg_replace('/[\r\n]+/', ' ', $subject));
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.hr-message',
            with: ['body' => $this->body, 'company' => config('app.company')],
        );
    }
}
