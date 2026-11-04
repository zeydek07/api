<?php

use App\Models\Permission;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// config/permissions.php'ye yeni sayfa/işlem eklediğinizde çalıştırın.
Artisan::command('permissions:sync', function () {
    $new = Permission::syncFromConfig();
    $this->info('Yetkiler güncellendi: '.Permission::count().' adet'.($new ? ' (yeni: '.implode(', ', $new).')' : '').'.');
    $this->line('Varsayılan rollere yeni yetkileri eklemek için: php artisan db:seed');
})->purpose('config/permissions.php içindeki sayfa yetkilerini veritabanına yazar');

// Süresi dolan token kayıtlarını her gün temizle.
Schedule::command('sanctum:prune-expired --hours=24')->daily();
