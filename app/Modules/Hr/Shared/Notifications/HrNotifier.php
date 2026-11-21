<?php

namespace App\Modules\Hr\Shared\Notifications;

use App\Models\User;
use Illuminate\Support\Facades\Mail;

class HrNotifier
{
    /**
     * İş transaction'ı içinden çağrılabilir; e-posta yalnızca transaction commit olursa kuyruğa girer.
     * Pasif, silinmiş veya e-postası olmayan alıcı sessizce atlanır. Konu/metin loglanmaz.
     */
    public static function send(User $to, string $subject, string $body): void
    {
        if (! $to->is_active || $to->trashed() || blank($to->email)) {
            return;
        }

        Mail::to($to->email)->queue(new HrMessageMail($subject, $body));
    }
}
