import 'package:flutter_test/flutter_test.dart';
import 'package:medikal_mobil/core/gs1.dart';
import 'package:medikal_mobil/core/models.dart';
import 'package:medikal_mobil/ui/stock_picker.dart';

void main() {
  // 08691234567890: kontrol hanesi doğru GTIN-14 (0869123456789 → 0)
  group('parseGs1 · GS1', () {
    test('parantezli karekod', () {
      final g = parseGs1('(01)08691234567890(17)271231(10)LOT42(21)SN001');
      expect(g.gtin, '08691234567890');
      expect(g.expiry, '2027-12-31');
      expect(g.lot, 'LOT42');
      expect(g.serial, 'SN001');
      expect(g.error, isNull);
    });

    test('ham karekod, değişken alanlar GS ile biter; tarayıcı öneki atılır', () {
      final g = parseGs1(']d201086912345678901727120010LOT42\x1d21SN001');
      expect(g.gtin, '08691234567890');
      expect(g.expiry, '2027-12-31', reason: 'gün 00 ise ayın son günü (paket 2027-11-30 diyor)');
      expect(g.lot, 'LOT42');
      expect(g.serial, 'SN001');
    });

    test('parantezli karekodda da gün 00 ayın son günü', () {
      expect(parseGs1('(01)08691234567890(17)280200(10)A').expiry, '2028-02-29');
    });

    test('lot ve SKT\'den önce gelen ek alanlar (240, 30) okumayı kesmez', () {
      final g = parseGs1('010869123456789024012345-X\x1d305\x1d17271231' '10LOT42\x1d21SN001');
      expect(g.gtin, '08691234567890');
      expect(g.expiry, '2027-12-31');
      expect(g.lot, 'LOT42');
      expect(g.serial, 'SN001');
    });

    test('üretim tarihi (11)', () {
      expect(parseGs1('(01)08691234567890(11)250115(10)A').produced, '2025-01-15');
    });

    test('yüzyıl GS1 kuralıyla bulunur (YY − bu yıl ≥ 51 ise önceki yüzyıl)', () {
      final now = DateTime(2026, 11, 17);
      expect(parseGs1('(01)08691234567890(17)601231', now: now).expiry, '2060-12-31');
      expect(parseGs1('(01)08691234567890(17)991231', now: now).expiry, '1999-12-31');
    });

    test('GTIN kontrol hanesi hatalıysa karekod reddedilir', () {
      final g = parseGs1('(01)08691234567893(10)LOT42');
      expect(g.isEmpty, isTrue);
      expect(g.error, contains('kontrol hanesi'));
    });

    test('geçersiz tarih SKT olarak alınmaz', () {
      expect(parseGs1('(01)08691234567890(17)271340(10)A').expiry, isNull);
    });

    test('GS1 Digital Link (QR içindeki adres)', () {
      final g = parseGs1('https://id.gs1.org/01/08691234567890/10/LOT42/21/SN001?17=271231');
      expect(g.gtin, '08691234567890');
      expect(g.lot, 'LOT42');
      expect(g.serial, 'SN001');
      expect(g.expiry, '2027-12-31');
    });

    test('düz EAN-13 GTIN-14 olarak da döner', () {
      final g = parseGs1('8691234567890');
      expect(g.plain, '8691234567890');
      expect(g.gtin, '08691234567890');
    });

    test('kontrol hanesi tutmayan düz sayı GTIN sayılmaz ama kod olarak aranır', () {
      final g = parseGs1('12345678');
      expect(g.plain, '12345678');
      expect(g.gtin, isNull);
    });

    test('tanınmayan metin düz kod sayılır; GTIN\'siz GS1 benzeri kod da', () {
      expect(parseGs1('URN-ABC').plain, 'URN-ABC');
      expect(parseGs1('10-4455').plain, '10-4455');
      expect(parseGs1('   ').isEmpty, isTrue);
    });
  });

  // Kontrol karakterleri ANSI/HIBC Ek B (mod 43) ile hesaplandı
  group('parseGs1 · HIBC', () {
    test('resmi örnek: +A123BJC5D6E71G', () {
      final g = parseGs1('+A123BJC5D6E71G');
      expect(g.plain, '+A123BJC5D6E71');
      expect(g.gtin, isNull);
      expect(g.error, isNull);
    });

    test('birincil + ikincil: SKT (YYMMDD) ve lot', () {
      final g = parseGs1('+H123ABC01/\$\$3271231LOT42E');
      expect(g.plain, '+H123ABC01');
      expect(g.expiry, '2027-12-31');
      expect(g.lot, 'LOT42');
    });

    test('SKT MMYY ise ayın son günü', () {
      final g = parseGs1('+H123ABC01/\$\$1227LOT427');
      expect(g.expiry, '2027-12-31');
      expect(g.lot, 'LOT42');
    });

    test('seri numarası (\$\$+)', () {
      final g = parseGs1('+H123ABC01/\$\$+3271231SN001R');
      expect(g.serial, 'SN001');
      expect(g.lot, isNull);
      expect(g.expiry, '2027-12-31');
    });

    test('tarihsiz lot (\$)', () {
      expect(parseGs1('+H123ABC01/\$LOT42%').lot, 'LOT42');
    });

    test('miktar alanı (\$\$8 + 2 hane) atlanır', () {
      final g = parseGs1('+H123ABC01/\$\$8053271231LOT42R');
      expect(g.expiry, '2027-12-31');
      expect(g.lot, 'LOT42');
    });

    test('ek veri: /16D üretim tarihi', () {
      final g = parseGs1('+H123ABC01/\$\$3271231LOT42/16D202501154');
      expect(g.produced, '2025-01-15');
      expect(g.lot, 'LOT42');
    });

    test('kontrol karakteri tutmazsa reddedilir', () {
      final g = parseGs1('+H123ABC01/\$\$3271231LOT42X');
      expect(g.isEmpty, isTrue);
      expect(g.error, contains('kontrol'));
    });

    test('ayrı basılmış ürün ve lot barkodu tanınır', () {
      final p = parseGs1('+H123ABC01C');
      expect(p.isHibcPrimaryOnly, isTrue);
      expect(p.hibcCheck, 'C');
      // sondan ikinci karakter bağ (link) karakteri = ürün barkodunun kontrol karakteri
      final s = parseGs1('+\$\$3271231LOT42CF');
      expect(s.isEmpty, isTrue, reason: 'ürün kimliği yok');
      expect(s.hibcLink, 'C');
      expect(s.lot, 'LOT42');
      expect(s.expiry, '2027-12-31');
      expect(parseGs1('+H123ABC01/\$\$3271231LOT42E').isHibcPrimaryOnly, isFalse, reason: 'birleşik etiket');
    });
  });

  group('HibcPairing', () {
    const primary = '+H123ABC01C';
    const secondary = '+\$\$3271231LOT42CF';

    test('önce ürün sonra lot: lot takipli ürün bekletilir, ikisi birleşir', () {
      final pair = HibcPairing();
      final p = pair.feed(parseGs1(primary))!;
      expect(pair.holdIfNeeded(p, 'lot'), isTrue);
      final g = pair.feed(parseGs1(secondary))!;
      expect(g.plain, '+H123ABC01');
      expect(g.lot, 'LOT42');
      expect(g.expiry, '2027-12-31');
      expect(pair.waiting, isFalse);
    });

    test('önce lot sonra ürün', () {
      final pair = HibcPairing();
      expect(pair.feed(parseGs1(secondary)), isNull);
      expect(pair.message, contains('ürün barkodunu okutun'));
      expect(pair.messageIsError, isFalse);
      final g = pair.feed(parseGs1(primary))!;
      expect(g.plain, '+H123ABC01');
      expect(g.lot, 'LOT42');
      expect(pair.holdIfNeeded(g, 'lot'), isFalse, reason: 'zaten tamam');
    });

    test('bağ karakteri tutmazsa birleşmez, ürün beklemeye devam eder', () {
      final pair = HibcPairing();
      pair.holdIfNeeded(pair.feed(parseGs1(primary))!, 'lot');
      expect(pair.feed(parseGs1('+\$\$3271231LOT42X-')), isNull);
      expect(pair.message, contains('ait değil'));
      expect(pair.messageIsError, isTrue);
      expect(pair.feed(parseGs1(secondary))!.lot, 'LOT42');
    });

    test('lot barkodu yoksa ürün barkodu ikinci kez okutulunca lotsuz devam edilir', () {
      final pair = HibcPairing();
      expect(pair.holdIfNeeded(pair.feed(parseGs1(primary))!, 'lot'), isTrue);
      final again = pair.feed(parseGs1(primary))!;
      expect(again.lot, isNull);
      expect(pair.holdIfNeeded(again, 'lot'), isFalse);
    });

    test('lot takibi olmayan ürün beklemez; başka barkod bekleyeni iptal eder', () {
      final pair = HibcPairing();
      expect(pair.holdIfNeeded(pair.feed(parseGs1(primary))!, 'none'), isFalse);
      pair.holdIfNeeded(pair.feed(parseGs1(primary))!, 'lot');
      expect(pair.feed(parseGs1('(01)08691234567890(10)A'))!.gtin, '08691234567890');
      expect(pair.waiting, isFalse);
    });
  });

  group('StockCart.scan', () {
    StockRow row(int id, {String? lot, String? serial, String? expiry, num quantity = 2}) => StockRow({
          'id': id,
          'product_id': 1,
          'quantity': '$quantity',
          'lot_number': lot,
          'serial_number': serial,
          'expiry_date': expiry,
          'product': {'id': 1, 'name': 'Vida', 'gtin': '08691234567890', 'tracking': serial != null ? 'serial' : 'lot'},
        });

    test('lot okunmazsa SKT\'si en yakın uygun lot seçilir, geçmiş lot atlanır', () {
      final cart = StockCart()
        ..reset([
          row(1, lot: 'A', expiry: '2030-01-01'),
          row(2, lot: 'B', expiry: '2028-01-01'),
          row(3, lot: 'C', expiry: '2020-01-01'),
        ]);
      final (_, error) = cart.scan('8691234567890', notHere: 'yok');
      expect(error, isFalse);
      expect(cart.picked, {2: 1});
    });

    test('seri numarası aynı ürün ikinci kez eklenmez', () {
      final cart = StockCart()..reset([row(5, serial: 'SN1', quantity: 1)]);
      expect(cart.scan('(01)08691234567890(21)SN1', notHere: 'yok').$2, isFalse);
      final (text, error) = cart.scan('(01)08691234567890(21)SN1', notHere: 'yok');
      expect(error, isTrue);
      expect(text, contains('zaten eklendi'));
    });

    test('depoda olmayan ürün', () {
      final cart = StockCart()..reset([row(1, lot: 'A')]);
      expect(cart.scan('4006381333931', notHere: 'Aracınızda yok').$1, 'Aracınızda yok');
    });

    test('HIBC ayrı etiket: ürün okutulunca bekler, lot okutulunca o lot seçilir', () {
      StockRow hibc(int id, String lot, String expiry) => StockRow({
            'id': id,
            'product_id': 2,
            'quantity': '3',
            'lot_number': lot,
            'expiry_date': expiry,
            'product': {'id': 2, 'name': 'Plak', 'barcode': '+H123ABC01', 'tracking': 'lot'},
          });
      final cart = StockCart()..reset([hibc(7, 'ERKEN', '2027-01-31'), hibc(8, 'LOT42', '2027-12-31')]);

      final (wait, waitError) = cart.scan('+H123ABC01C', notHere: 'yok');
      expect(waitError, isFalse);
      expect(wait, contains('lot/SKT barkodunu okutun'));
      expect(cart.picked, isEmpty);

      final (done, doneError) = cart.scan('+\$\$3271231LOT42CF', notHere: 'yok');
      expect(doneError, isFalse);
      expect(done, contains('LOT42'));
      expect(cart.picked, {8: 1}, reason: 'SKT\'si en yakın ERKEN değil, okutulan LOT42');
    });
  });
}
