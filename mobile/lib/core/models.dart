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

  /// Hastane lokasyonlarında tam yol: "Ortopedi › Ameliyathane 2 › Dolap A"
  String get path => (json['path'] as String?) ?? name;
  String? get code => json['code'];
  String? get type => json['type'];
  double get totalUnits => toNum(json['total_units'] ?? json['units']);
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
  String? get warehouseName => (json['warehouse'] as Map?)?['path'] ?? (json['warehouse'] as Map?)?['name'];

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

// ---------------------------------------------------------------- saat

/// Türkiye saati (UTC+3, yaz saati uygulaması yok). Sunucu saatleri UTC döner;
/// cihazın saat dilimi ne olursa olsun Türkiye saati gösterilir.
DateTime? trTime(String? iso) => iso == null ? null : DateTime.parse(iso).toUtc().add(const Duration(hours: 3));

DateTime trNow() => DateTime.now().toUtc().add(const Duration(hours: 3));

String two(int n) => n.toString().padLeft(2, '0');

String hm(DateTime d) => '${two(d.hour)}:${two(d.minute)}';

const _months = ['Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
const _days = ['Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi', 'Pazar'];

/// "9 Kasım Pazartesi"
String longDate(DateTime d) => '${d.day} ${_months[d.month - 1]} ${_days[d.weekday - 1]}';

/// "Bugün" / "Yarın" / "Dün" / "12 Kasım Perşembe"
String dayLabel(DateTime d) {
  final today = trNow();
  final diff = DateTime(d.year, d.month, d.day).difference(DateTime(today.year, today.month, today.day)).inDays;
  return switch (diff) { 0 => 'Bugün', 1 => 'Yarın', -1 => 'Dün', _ => longDate(d) };
}

/// Sunucuya gönderilen Türkiye saati: "2026-11-10 09:30"
String trWire(DateTime d) => '${d.year}-${two(d.month)}-${two(d.day)} ${two(d.hour)}:${two(d.minute)}';

// ---------------------------------------------------------------- vaka

class SurgeryCase {
  SurgeryCase(this.json);
  final Map<String, dynamic> json;

  int get id => json['id'];
  String get number => json['number'] ?? '';
  String get status => json['status'] ?? 'planned';
  DateTime get scheduledAt => trTime(json['scheduled_at'])!;
  DateTime? get performedAt => trTime(json['performed_at']);
  Map<String, dynamic>? get hospital => (json['hospital'] as Map?)?.cast<String, dynamic>();
  Map<String, dynamic>? get location => (json['location'] as Map?)?.cast<String, dynamic>();
  Map<String, dynamic>? get doctor => (json['doctor'] as Map?)?.cast<String, dynamic>();
  String get hospitalName => hospital?['name'] ?? '';
  String? get locationPath => location?['path'] ?? location?['name'];
  String? get doctorName => doctor == null ? null : (doctor!['display_name'] ?? doctor!['name']);
  String? get procedure => json['procedure'];
  String? get protocolNo => json['protocol_no'];
  bool get isOverdue => json['is_overdue'] == true;
  bool get isPlanned => status == 'planned';

  /// Plan yapılmadan kaydedilen (acil) vaka
  bool get isUnplanned => json['is_unplanned'] == true;
  int? get userId => json['user_id'];
  String? get ownerName => (json['user'] as Map?)?['name'];
  List<Map<String, dynamic>> get items => [for (final i in (json['items'] as List? ?? [])) Map<String, dynamic>.from(i)];
  List<Map<String, dynamic>> get photos => [for (final i in (json['photos'] as List? ?? [])) Map<String, dynamic>.from(i)];
  List<Map<String, dynamic>> get events => [for (final i in (json['events'] as List? ?? [])) Map<String, dynamic>.from(i)];

  static const labels = {'planned': 'Planlandı', 'recorded': 'Onay bekliyor', 'approved': 'Onaylandı', 'cancelled': 'İptal'};

  String get statusLabel => isOverdue ? 'Kaydı bekliyor' : labels[status] ?? status;
}

const itemStatusLabels = {'used': 'Kullanıldı', 'opened': 'Açıldı', 'unused': 'Kullanılmadı'};
