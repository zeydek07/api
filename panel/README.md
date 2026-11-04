# Medikal Stok – Yönetim Paneli

Laravel merkez API'si (`../`) için Angular 22 + Tailwind CSS 4 yönetim paneli.
Pencere ve açılır menü davranışları için görünümsüz Angular CDK kullanılır; tüm görünüm Tailwind ile yazılmıştır.

- Renkler ve açık/koyu tema: [src/styles.css](src/styles.css) (`--primary` vb. değişkenler)
- Ortak parçalar: [src/app/ui/](src/app/ui/) (pencere, onay, bildirim, sayfalama, ürün seçici, yetki tablosu, grafik)

## Çalıştırma

1. API'yi başlatın: `../baslat.bat` (http://127.0.0.1:8010)
2. Paneli başlatın: `../panel-baslat.bat` veya `npm start`
3. Tarayıcı: http://localhost:4200

Geliştirmede `/api` istekleri [proxy.conf.json](proxy.conf.json) ile API'ye yönlendirilir; CORS ayarı gerekmez.

## Yetkiler

Panel, girişte API'den gelen yetki listesine göre çalışır:

- Menüde yalnızca yetkili olunan sayfalar görünür ([src/app/layout/menu.ts](src/app/layout/menu.ts)).
- Adres çubuğundan yetkisiz sayfaya gidilirse "yetkiniz yok" sayfası açılır ([src/app/core/guards.ts](src/app/core/guards.ts)).
- Ekle / düzenle / sil butonları `*appCan="'products.create'"` ile gizlenir.
- Asıl koruma API'dedir; panel yalnızca görünürlüğü yönetir.
- Token süresi dolarsa veya hesap pasife alınırsa kullanıcı giriş ekranına döner.

Yeni bir sayfa eklerken: API'de `config/permissions.php`, panelde `app.routes.ts` (`data.permission`) ve `menu.ts`.

## Canlıya alma

```bash
npm run build      # çıktı: dist/panel/browser
```

Çıktıyı API ile aynı alan adında yayınlayın (ör. `https://alanadi.com/` panel, `https://alanadi.com/api` Laravel).
Farklı alan adı kullanılacaksa [src/app/core/api.ts](src/app/core/api.ts) içindeki `API_URL` değerini ve
Laravel `config/cors.php` ayarını güncelleyin.
