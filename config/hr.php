<?php

return [
    // İK belgelerinin saklandığı özel klasör. Bilerek varsayılan yok: proje OneDrive gibi
    // senkronize bir klasörde olabilir. Boşsa Belgeler modülü (Aşama 2) kapalı kalmalıdır (fail closed).
    'storage_path' => env('HR_STORAGE_PATH') ?: null,

    // Hassas alanlarda (TCKN vb.) eşitlik araması için HMAC anahtarı
    'blind_index_key' => env('HR_BLIND_INDEX_KEY'),
];
