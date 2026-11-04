@echo off
REM API'yi http://127.0.0.1:8010 adresinde baslatir.
REM PHP 8.5'in yerlesik sunucusunda OPcache hatasi oldugu icin "php artisan serve" yerine bu kullanilir.
REM Telefondan erismek icin 127.0.0.1 yerine 0.0.0.0 yazin ve bilgisayarin IP adresini kullanin.
cd /d "%~dp0public"
php -d opcache.enable=0 -S 127.0.0.1:8010 ..\vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php
