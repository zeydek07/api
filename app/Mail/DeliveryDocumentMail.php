<?php

namespace App\Mail;

use App\Models\Delivery;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Hastane personeline giden belge kopyası (PDF ekli) */
class DeliveryDocumentMail extends Mailable
{
    public function __construct(
        public readonly Delivery $delivery,
        private readonly string $pdf,
        private readonly string $filename,
    ) {}

    public function envelope(): Envelope
    {
        $kind = $this->delivery->type === Delivery::TYPE_RETURN ? 'Geri alma belgesi' : 'Teslim belgesi';

        return new Envelope(subject: "{$kind} {$this->delivery->number} · {$this->delivery->hospital?->name}");
    }

    public function content(): Content
    {
        return new Content(view: 'mail.delivery', with: [
            'd' => $this->delivery,
            'company' => config('app.company'),
            'units' => $this->delivery->lines->sum(fn ($l) => (float) $l->quantity),
        ]);
    }

    public function attachments(): array
    {
        return [Attachment::fromData(fn () => $this->pdf, $this->filename)->withMime('application/pdf')];
    }
}
