<?php

namespace App\Services;

use App\Mail\DeliveryDocumentMail;
use App\Models\Delivery;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Teslimat / geri alma belgesinin PDF'i ve hastane personeline e-postayla gönderimi.
 * Gönderim hatası belgeyi bozmaz; sonuç belgeye yazılır, panelden yeniden gönderilebilir.
 */
class DeliveryDocument
{
    private const DISK = 'local';

    public function pdf(Delivery $delivery): string
    {
        $delivery->loadMissing([
            'hospital:id,name,code,city,district,address',
            'location:id,name,path',
            'vehicle:id,name,code',
            'contact:id,name,title,phone,email',
            'user:id,name,email,phone',
            'canceller:id,name',
            'lines' => fn ($q) => $q->orderBy('product_id')->orderBy('id'),
            'lines.product:id,name,code,gtin,unit',
        ]);

        return Pdf::loadView('pdf.delivery', [
            'd' => $delivery,
            'company' => config('app.company'),
            'signature' => $this->image($delivery->signature_path, 600),
            'photo' => $this->image($delivery->photo_path, 1000),
        ])->setPaper('a4')->setOption('isFontSubsettingEnabled', true)->output();
    }

    public function filename(Delivery $delivery): string
    {
        return ($delivery->number ?? 'belge-'.$delivery->id).'.pdf';
    }

    /** Belgeyi gönderir ve sonucu belgeye yazar. Başarılıysa true. */
    public function email(Delivery $delivery, string $to): bool
    {
        try {
            Mail::to($to)->send(new DeliveryDocumentMail($delivery, $this->pdf($delivery), $this->filename($delivery)));
            $delivery->update([
                'email_to' => $to, 'email_status' => Delivery::EMAIL_SENT, 'emailed_at' => now(), 'email_error' => null,
            ]);

            return true;
        } catch (Throwable $e) {
            Log::warning("Belge e-postası gönderilemedi ({$delivery->number})", ['to' => $to, 'error' => $e->getMessage()]);
            $delivery->update([
                'email_to' => $to, 'email_status' => Delivery::EMAIL_FAILED, 'emailed_at' => now(),
                'email_error' => mb_substr($e->getMessage(), 0, 500),
            ]);

            return false;
        }
    }

    /** Kanıt görselini PDF'e gömülecek data URI'ye çevirir; büyük fotoğraflar küçültülür. */
    private function image(?string $path, int $maxWidth): ?string
    {
        if (! $path || ! Storage::disk(self::DISK)->exists($path)) {
            return null;
        }
        $bytes = Storage::disk(self::DISK)->get($path);
        $mime = (getimagesizefromstring($bytes) ?: [])['mime'] ?? 'image/png';

        if (function_exists('imagecreatefromstring') && $mime !== 'image/png') {
            $src = @imagecreatefromstring($bytes);
            if ($src !== false) {
                if (imagesx($src) > $maxWidth) {
                    $src = imagescale($src, $maxWidth) ?: $src;
                }
                ob_start();
                imagejpeg($src, null, 75);
                $bytes = ob_get_clean();
                $mime = 'image/jpeg';
            }
        }

        return "data:{$mime};base64,".base64_encode($bytes);
    }
}
