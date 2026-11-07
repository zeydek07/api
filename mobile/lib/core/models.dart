// API'den gelen JSON'u okuyan hafif modeller (yalnızca uygulamanın kullandığı alanlar).

double toNum(Object? v) => v is num ? v.toDouble() : double.tryParse('$v') ?? 0;

String qty(Object? v) {
  final n = toNum(v);
  return n == n.roundToDouble() ? n.toInt().toString() : n.toStringAsFixed(2);
}

/// "2027-05-31T00:00:00.000000Z" → "31.05.2027"
String? dateTr(String? iso) {
  if (iso == null || iso.length < 10) return null;
  final p = iso.substring(0, 10).split('-');
  return '${p[2]}.${p[1]}.${p[0]}';
}

class User {
  User(this.json);
  final Map<String, dynamic> json;

  int get id => json['id'];
  String get name => json['name'] ?? '';
  String get roleName => (json['role'] as Map?)?['name'] ?? '';
  bool get isSuperAdmin => json['is_super_admin'] == true;
  List<String> get permissions => List<String>.from(json['permissions'] ?? const []);

  bool can(String permission) => isSuperAdmin || permissions.contains(permission);
}

class Product {
  Product(this.json);
  final Map<String, dynamic> json;

  int get id => json['id'];
  String get name => json['name'] ?? '';
  String? get code => json['code'];
  String? get barcode => json['barcode'];
  String? get gtin => json['gtin'];
  String get unit => json['unit'] ?? 'adet';

  /// none | lot | serial
  String get tracking => json['tracking'] ?? 'none';

  bool matches(String? gtin, String? plain) =>
      (gtin != null && this.gtin == gtin) || (plain != null && (barcode == plain || code == plain));
}

class Warehouse {
  Warehouse(this.json);
  final Map<String, dynamic> json;

  int get id => json['id'];
  String get name => json['name'] ?? '';
  String? get code => json['code'];
  String? get type => json['type'];
  bool get isActive => json['is_active'] != false;

  String get label => code == null ? name : '$name ($code)';
}

/// Bir depodaki lot/seri satırı.
class StockRow {
  StockRow(this.json) : product = Product(Map<String, dynamic>.from(json['product'] ?? const {}));
  final Map<String, dynamic> json;
  final Product product;

  int get id => json['id'];
  int get productId => json['product_id'];
  double get quantity => toNum(json['quantity']);
  String? get lot => json['lot_number'];
  String? get serial => json['serial_number'];
  String? get expiry => (json['expiry_date'] as String?)?.substring(0, 10);
  String? get warehouseName => (json['warehouse'] as Map?)?['name'];

  bool get expired => expiry != null && expiry!.compareTo(_today()) < 0;

  /// SKT 90 gün içinde mi
  bool get expiringSoon {
    if (expiry == null || expired) return false;
    final limit = DateTime.now().add(const Duration(days: 90)).toIso8601String().substring(0, 10);
    return expiry!.compareTo(limit) <= 0;
  }

  /// Seri ve lot (SKT ayrıca rozet olarak gösterilir)
  String get detail => [
        if (serial != null) 'SN $serial',
        if (lot != null) 'LOT $lot',
      ].join(' · ');
}

String _today() => DateTime.now().toIso8601String().substring(0, 10);

/// Türkçe küçük harf (I → ı, İ → i); aramalarda kullanılır.
String trLower(String s) => s.replaceAll('I', 'ı').replaceAll('İ', 'i').toLowerCase();
