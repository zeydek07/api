import 'package:flutter_test/flutter_test.dart';
import 'package:medikal_mobil/core/gs1.dart';
import 'package:medikal_mobil/core/models.dart';
import 'package:medikal_mobil/ui/stock_picker.dart';

void main() {
  group('parseGs1', () {
    test('parantezli karekod', () {
      final g = parseGs1('(01)08691234567893(17)271231(10)LOT42(21)SN001');
      expect(g.gtin, '08691234567893');
      expect(g.expiry, '2027-12-31');
      expect(g.lot, 'LOT42');
      expect(g.serial, 'SN001');
    });

    test('ham karekod, değişken alanlar GS ile biter; tarayıcı öneki atılır', () {
      final g = parseGs1(']d201086912345678931727120010LOT42\x1d21SN001');
      expect(g.gtin, '08691234567893');
      expect(g.expiry, '2027-12-31', reason: 'gün 00 ise ayın son günü');
      expect(g.lot, 'LOT42');
      expect(g.serial, 'SN001');
    });

    test('düz EAN-13 GTIN-14 olarak da döner', () {
      final g = parseGs1('8691234567893');
      expect(g.plain, '8691234567893');
      expect(g.gtin, '08691234567893');
    });

    test('tanınmayan metin düz kod sayılır', () {
      expect(parseGs1('URN-ABC').plain, 'URN-ABC');
      expect(parseGs1('   ').isEmpty, isTrue);
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
          'product': {'id': 1, 'name': 'Vida', 'gtin': '08691234567893', 'tracking': serial != null ? 'serial' : 'lot'},
        });

    test('lot okunmazsa SKT\'si en yakın uygun lot seçilir, geçmiş lot atlanır', () {
      final cart = StockCart()
        ..reset([
          row(1, lot: 'A', expiry: '2030-01-01'),
          row(2, lot: 'B', expiry: '2028-01-01'),
          row(3, lot: 'C', expiry: '2020-01-01'),
        ]);
      final (_, error) = cart.scan('8691234567893', notHere: 'yok');
      expect(error, isFalse);
      expect(cart.picked, {2: 1});
    });

    test('seri numarası aynı ürün ikinci kez eklenmez', () {
      final cart = StockCart()..reset([row(5, serial: 'SN1', quantity: 1)]);
      expect(cart.scan('(01)08691234567893(21)SN1', notHere: 'yok').$2, isFalse);
      final (text, error) = cart.scan('(01)08691234567893(21)SN1', notHere: 'yok');
      expect(error, isTrue);
      expect(text, contains('zaten eklendi'));
    });

    test('depoda olmayan ürün', () {
      final cart = StockCart()..reset([row(1, lot: 'A')]);
      expect(cart.scan('4006381333931', notHere: 'Aracınızda yok').$1, 'Aracınızda yok');
    });
  });
}
