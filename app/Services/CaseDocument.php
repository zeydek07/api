<?php

namespace App\Services;

use App\Mail\CaseDocumentMail;
use App\Models\Delivery;
use App\Models\SurgeryCase;
use App\Support\ProofImage;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Vaka kullanım tutanağının PDF'i ve hastane personeline e-postayla gönderimi.
 * Gönderim hatası kaydı bozmaz; sonuç vakaya yazılır, yeniden gönderilebilir.
 */
class CaseDocument
{
    public function pdf(SurgeryCase $case): string
    {
        $case->loadMissing([
            'hospital:id,name,code,city,district,address',
            'location:id,name,path',
            'doctor:id,name,title,branch',
            'contact:id,name,title,phone,email',
            'user:id,name,email,phone',
            'canceller:id,name',
            'items' => fn ($q) => $q->orderByRaw("CASE status WHEN 'used' THEN 0 WHEN 'opened' THEN 1 ELSE 2 END")->orderBy('product_id')->orderBy('id'),
            'items.product:id,name,code,gtin,unit',
            'items.warehouse:id,name,path,type',
        ]);

        return Pdf::loadView('pdf.case', [
            'c' => $case,
            'company' => config('app.company'),
            'signature' => ProofImage::dataUri($case->signature_path, 600),
            'photos' => \App\Support\ProofPhotos::forPdf($case),
        ])->setPaper('a4')->setOption('isFontSubsettingEnabled', true)->output();
    }

    public function filename(SurgeryCase $case): string
    {
        return ($case->number ?? 'vaka-'.$case->id).'.pdf';
    }

    public function email(SurgeryCase $case, string $to): bool
    {
        try {
            Mail::to($to)->send(new CaseDocumentMail($case, $this->pdf($case), $this->filename($case)));
            $case->update(['email_to' => $to, 'email_status' => Delivery::EMAIL_SENT, 'emailed_at' => now(), 'email_error' => null]);

            return true;
        } catch (Throwable $e) {
            Log::warning("Vaka tutanağı e-postası gönderilemedi ({$case->number})", ['to' => $to, 'error' => $e->getMessage()]);
            $case->update([
                'email_to' => $to, 'email_status' => Delivery::EMAIL_FAILED, 'emailed_at' => now(),
                'email_error' => mb_substr($e->getMessage(), 0, 500),
            ]);

            return false;
        }
    }
}
