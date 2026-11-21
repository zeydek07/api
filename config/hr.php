<?php

return [
    // İK belgelerinin saklandığı özel disk yolu (boşsa storage/app/hr)
    'storage_path' => env('HR_STORAGE_PATH') ?: storage_path('app/hr'),

    // Hassas alanlarda (TCKN vb.) eşitlik araması için HMAC anahtarı
    'blind_index_key' => env('HR_BLIND_INDEX_KEY'),
];
