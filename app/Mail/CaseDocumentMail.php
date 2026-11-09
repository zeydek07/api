<?php

namespace App\Mail;

use App\Models\SurgeryCase;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Hastane personeline giden vaka kullanım tutanağı (PDF ekli) */
class CaseDocumentMail extends Mailable
{
    public function __construct(
        public readonly SurgeryCase $case,
        private readonly string $pdf,
        private readonly string $filename,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Kullanım tutanağı {$this->case->number} · {$this->case->hospital?->name}");
    }

    public function content(): Content
    {
        return new Content(view: 'mail.case', with: ['c' => $this->case, 'company' => config('app.company')]);
    }

    public function attachments(): array
    {
        return [Attachment::fromData(fn () => $this->pdf, $this->filename)->withMime('application/pdf')];
    }
}
