<?php

return [
    // SKT'ye bu kadar gün kala "yaklaşıyor" uyarısı verilir
    'expiry_warning_days' => (int) env('EXPIRY_WARNING_DAYS', 90),

    // SKT'ye bu kadar gün kala "kritik" sayılır (konsinyeye bırakılması önerilmez)
    'expiry_critical_days' => (int) env('EXPIRY_CRITICAL_DAYS', 30),
];
