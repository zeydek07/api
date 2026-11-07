# Medikal Stok – mobil uygulama (Flutter, Android)

Panelle aynı API'yi kullanır. Personel kendi hesabıyla girer ve yalnızca yetkisi olan işlemleri görür. API de her işlemde yetkiyi ayrıca kontrol eder.

| Ekran | Yetki | Ne yapar |
|---|---|---|
| Stok Girişi | `stock.receive` | Depoya mal kabul. Karekod okutulunca ürün, lot, seri ve SKT kendiliğinden dolar. |
| Stok Çıkışı | `stock.transfer` | Depodan araca veya başka depoya transfer. SKT'si geçmiş lot çıkarılamaz. |
| Hastane Teslimatı | `deliveries.create` | Araçtan hastaneye teslim (imza zorunlu) ve hastaneden geri alma. Konum kaydet anında alınır; alınamazsa belge "konum alınamadı" olarak işaretlenir. |
| Ürün Sorgula | `products.view` | Barkod okut; ürünün hangi depoda ve lotta ne kadar olduğunu gösterir. |

Barkod kamerayla okunur (Google ML Kit, `mobile_scanner`): GS1 DataMatrix, QR, EAN-13/8, Code 128/39, ITF-14. Kamerası olmayan cihazda "Elle gir" ile kod yazılabilir.

## Çalıştırma

API'nin çalışıyor olması gerekir (bkz. kök README).

```sh
cd mobile
flutter pub get
flutter run                     # emülatör: API adresi http://10.0.2.2:8010/api
```

Gerçek telefonda bilgisayarın ağdaki adresini verin (telefon ve bilgisayar aynı ağda olmalı; API `127.0.0.1` yerine `0.0.0.0` adresini dinlemeli, ör. `php -S 0.0.0.0:8010 ...`):

```sh
flutter run --dart-define=API_URL=http://192.168.1.20:8010/api
```

Debug sürümü yerel geliştirme için `http` bağlantısına izin verir. Canlıda API yalnızca `https` ile yayınlanmalıdır.

## Testler

```sh
flutter analyze
flutter test        # karekod çözücü ve okutma kuralları
```
