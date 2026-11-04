<?php

// Sayfa => işlemler. Yeni bir sayfa eklediğinizde buraya yazıp
// `php artisan permissions:sync` çalıştırmanız yeterli.
return [
    // Bu roldeki kullanıcılar her yetkiye sahiptir.
    'super_admin_role' => 'super-admin',

    'pages' => [
        'dashboard' => ['label' => 'Kontrol Paneli', 'actions' => ['view' => 'Görüntüle']],
        'users' => ['label' => 'Personeller', 'actions' => [
            'view' => 'Listele', 'create' => 'Ekle', 'update' => 'Düzenle', 'delete' => 'Sil',
        ]],
        'roles' => ['label' => 'Roller ve Yetkiler', 'actions' => [
            'view' => 'Listele', 'create' => 'Ekle', 'update' => 'Düzenle', 'delete' => 'Sil',
        ]],
        'products' => ['label' => 'Ürünler', 'actions' => [
            'view' => 'Listele', 'create' => 'Ekle', 'update' => 'Düzenle', 'delete' => 'Sil',
        ]],
        'hospitals' => ['label' => 'Hastaneler', 'actions' => [
            'view' => 'Listele', 'create' => 'Ekle', 'update' => 'Düzenle', 'delete' => 'Sil',
        ]],
        'warehouses' => ['label' => 'Depolar', 'actions' => [
            'view' => 'Listele', 'create' => 'Ekle', 'update' => 'Düzenle', 'delete' => 'Sil',
        ]],
        'stock' => ['label' => 'Stok', 'actions' => [
            'view' => 'Stok durumunu gör',
            'receive' => 'Stok girişi',
            'transfer' => 'Depolar arası transfer',
            'adjust' => 'Sayım düzeltmesi',
        ]],
        'usage' => ['label' => 'Saha Kullanımı', 'actions' => [
            'create' => 'Stoktan ürün düş / hastaneye teslim',
        ]],
        'movements' => ['label' => 'Stok Hareketleri', 'actions' => [
            'view_team' => 'Ekibinin hareketlerini gör',
            'view_all' => 'Tüm hareketleri gör',
        ]],
        'reports' => ['label' => 'Raporlar', 'actions' => ['view' => 'Görüntüle']],
    ],

    // Kurulumda oluşturulan varsayılan roller (sonradan panelden değiştirilebilir)
    'default_roles' => [
        'super-admin' => ['name' => 'Süper Yönetici', 'permissions' => '*'],
        'yonetici' => ['name' => 'Yönetici', 'permissions' => [
            'dashboard.view', 'users.view', 'users.create', 'users.update',
            'products.view', 'products.create', 'products.update',
            'hospitals.view', 'hospitals.create', 'hospitals.update',
            'warehouses.view', 'stock.view', 'stock.receive', 'stock.transfer', 'stock.adjust',
            'usage.create', 'movements.view_team', 'reports.view',
        ]],
        'personel' => ['name' => 'Saha Personeli', 'permissions' => [
            'products.view', 'usage.create',
        ]],
        'depocu' => ['name' => 'Depo Sorumlusu', 'permissions' => [
            'dashboard.view', 'products.view', 'products.create', 'products.update',
            'warehouses.view', 'stock.view', 'stock.receive', 'stock.transfer', 'stock.adjust',
            'movements.view_all', 'reports.view',
        ]],
        'mumessil' => ['name' => 'Mümessil', 'permissions' => [
            'products.view', 'hospitals.view', 'usage.create',
        ]],
    ],
];
