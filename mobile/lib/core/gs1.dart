import 'package:gs1_barcode_parser/gs1_barcode_parser.dart';

/// Tıbbi cihaz (UDI) barkod / karekod çözücü. ÜTS'nin kabul ettiği iki yaygın standart:
///
/// GS1 (çoğunluk):
///   (01)08691234567890(17)271231(10)LOT42(21)SN001   ← parantezli, insan okunur
///   0108691234567890172712311 0LOT42 [GS] 21SN001    ← ham, değişken alanlar GS ile biter
///   https://id.gs1.org/01/08691234567890/10/LOT42     ← GS1 Digital Link (QR)
///   8691234567890                                    ← düz EAN-13 / GTIN
///
/// HIBC (bazı implant üreticileri), `+` ile başlar, sonda mod-43 kontrol karakteri:
///   +H123ABC01/$$3271231LOT42E                       ← ürün / SKT+lot
///
/// Alan tablosu ve ham karekod ayrıştırması `gs1_barcode_parser` paketinden gelir.
/// Tarihler paketin değil bizim kuralımızla çözülür: paket gün=00'ı önceki ayın
/// son günü yapıyor (271200 → 2027-11-30), doğrusu 2027-12-31.
class Gs1Data {
  String? gtin;
  String? lot;
  String? serial;

  /// YYYY-MM-DD
  String? expiry;

  /// YYYY-MM-DD
  String? produced;

  /// GTIN içermeyen kod: düz barkod, ürün kodu ya da HIBC ürün kimliği (+LIC…)
  String? plain;

  /// Barkod okundu ama güvenilmez (kontrol hanesi tutmuyor vb.); kullanıcıya gösterilir
  String? error;

  /// HIBC ürün barkodunun kontrol karakteri. Ayrı basılmış lot barkodu bunu bağ karakteri olarak taşır.
  String? hibcCheck;

  /// Ayrı basılmış HIBC lot/SKT barkodunun bağ karakteri (ait olduğu ürün barkodunun kontrol karakteri)
  String? hibcLink;

  /// Lot barkodu beklenmeden devam edilsin (ürün barkodu ikinci kez okutuldu)
  bool linkWaived = false;

  bool get isEmpty => gtin == null && plain == null;

  /// Yalnızca ürün kimliği olan HIBC barkodu; lot/SKT ayrı barkodda olabilir
  bool get isHibcPrimaryOnly => hibcCheck != null && lot == null && serial == null && expiry == null;
}

/// HIBC ürün barkodu okundu, aynı etiketteki lot/SKT barkodu bekleniyor
String hibcWaitText(String name) =>
    '$name okundu. Şimdi aynı etiketteki lot/SKT barkodunu okutun (lot barkodu yoksa ürün barkodunu tekrar okutun).';

/// HIBC ürün ve lot/SKT barkodları ayrı basıldığında iki okutmayı birleştirir (sıra fark etmez).
/// İki barkodun aynı etikete ait olduğu bağ karakteriyle doğrulanır (ANSI/HIBC 2.6).
///
///   final g = pairing.feed(parseGs1(raw));
///   if (g == null) → pairing.message göster (diğer yarı bekleniyor ya da eşleşmedi)
///   … ürünü bul …
///   if (pairing.holdIfNeeded(g, product.tracking)) → "şimdi lot barkodunu okutun"
class HibcPairing {
  Gs1Data? _primary;
  Gs1Data? _secondary;

  /// feed() null döndürdüğünde ekranda gösterilecek mesaj
  String? message;
  bool messageIsError = false;

  bool get waiting => _primary != null || _secondary != null;

  void clear() {
    _primary = null;
    _secondary = null;
  }

  Gs1Data? feed(Gs1Data g) {
    message = null;
    messageIsError = false;

    if (g.hibcLink != null) {
      final p = _primary;
      _secondary = null;
      if (p == null) {
        _secondary = g;
        message = 'Lot/SKT okundu. Şimdi ürün barkodunu okutun.';
        return null;
      }
      if (p.hibcCheck != g.hibcLink) {
        message = 'Bu lot/SKT barkodu okutulan ürüne ait değil. Aynı etiketteki lot barkodunu okutun.';
        messageIsError = true;
        return null;
      }
      _primary = null;
      return _merge(p, g);
    }

    if (g.isHibcPrimaryOnly) {
      final s = _secondary;
      final held = _primary;
      clear();
      if (s != null) {
        if (s.hibcLink != g.hibcCheck) {
          message = 'Bu ürün barkodu önce okutulan lot/SKT barkoduna ait değil. İkisini aynı etiketten okutun.';
          messageIsError = true;
          return null;
        }
        return _merge(g, s);
      }
      if (held != null && held.plain == g.plain) g.linkWaived = true; // ikinci kez: lotsuz devam
      return g;
    }

    clear();
    return g;
  }

  /// Ürün lot/seri takipliyse ve sadece ürün barkodu okunduysa lot barkodunu bekle
  bool holdIfNeeded(Gs1Data g, String tracking) {
    if (!g.isHibcPrimaryOnly || g.linkWaived || tracking == 'none') return false;
    _primary = g;
    return true;
  }

  Gs1Data _merge(Gs1Data primary, Gs1Data secondary) => Gs1Data()
    ..plain = primary.plain
    ..lot = secondary.lot
    ..serial = secondary.serial
    ..expiry = secondary.expiry
    ..produced = secondary.produced;
}

const _gs = '\x1d';
final _parser = GS1BarcodeParser.defaultParser();

Gs1Data parseGs1(String raw, {DateTime? now}) {
  // Sondaki boşluk HIBC kontrol karakteri olabilir; sadece satır sonlarını at
  var input = raw.replaceAll(RegExp(r'^\s+|[\r\n]+$'), '');
  input = input.replaceFirst(RegExp(r'^\][A-Za-z]\d'), ''); // tarayıcı öneki (]d2, ]C1, ]Q3)
  now ??= DateTime.now();
  if (input.trim().isEmpty) return Gs1Data();

  if (input.startsWith('+')) return _hibc(input, now);
  input = input.trim();

  if (RegExp(r'^\d{8,14}$').hasMatch(input)) {
    final gtin = input.padLeft(14, '0');
    return Gs1Data()
      ..plain = input
      ..gtin = _gtinValid(gtin) ? gtin : null;
  }

  final fields = _digitalLink(input) ?? _gs1Fields(input);
  final out = Gs1Data();
  if (fields == null || fields['01'] == null) return out..plain = input;

  if (!_gtinValid(fields['01']!)) {
    return out..error = 'Karekod hatalı okundu (GTIN kontrol hanesi tutmuyor). Tekrar okutun.';
  }
  out
    ..gtin = fields['01']
    ..lot = fields['10']
    ..serial = fields['21'];
  if (fields['17'] != null) out.expiry = _yymmdd(fields['17']!, now);
  if (fields['11'] != null) out.produced = _yymmdd(fields['11']!, now);
  return out;
}

/// Parantezli ya da ham GS1 metnini paketle çözer; çözülemezse null.
Map<String, String>? _gs1Fields(String s) {
  if (s.startsWith('(')) {
    // (01)…(17)… → paketin anladığı ham biçim: her alanın sonuna GS
    s = RegExp(r'\((\d{2,4})\)([^(]*)').allMatches(s).map((m) => '${m[1]}${m[2]!.trim()}$_gs').join();
  }
  try {
    return _parser.parse(s).getAIsRawData;
  } catch (_) {
    return null; // bilinmeyen alan, eksik veri: GS1 değil say
  }
}

/// https://alan.adi/01/{gtin}/10/{lot}/21/{seri}?17=YYMMDD
Map<String, String>? _digitalLink(String s) {
  final uri = Uri.tryParse(s);
  if (uri == null || !uri.hasScheme || !uri.scheme.startsWith('http')) return null;
  final seg = uri.pathSegments;
  final i = seg.indexOf('01');
  if (i == -1 || i + 1 >= seg.length) return null;
  final out = <String, String>{};
  for (var k = i; k + 1 < seg.length; k += 2) {
    out[seg[k]] = Uri.decodeComponent(seg[k + 1]);
  }
  out.addAll(uri.queryParameters);
  return out;
}

/// GS1 mod-10: sağdan itibaren 3,1,3,1… ağırlıkları
bool _gtinValid(String gtin) {
  if (!RegExp(r'^\d{14}$').hasMatch(gtin)) return false;
  var sum = 0;
  for (var i = 0; i < 13; i++) {
    sum += int.parse(gtin[12 - i]) * (i.isEven ? 3 : 1);
  }
  return (10 - sum % 10) % 10 == int.parse(gtin[13]);
}

/// İki haneli yılı GS1 kuralıyla dört haneye çevirir (Genel Spesifikasyon 7.12):
/// bu yıla göre fark ≥ 51 ise önceki yüzyıl, ≤ −50 ise sonraki yüzyıl.
int _year(int yy, DateTime now) {
  final century = now.year - now.year % 100;
  final diff = yy - now.year % 100;
  if (diff >= 51) return century - 100 + yy;
  if (diff <= -50) return century + 100 + yy;
  return century + yy;
}

String? _date(int year, int month, int day) {
  if (month < 1 || month > 12) return null;
  final last = DateTime(year, month + 1, 0).day;
  if (day == 0) day = last; // GS1: gün 00 → ayın son günü
  if (day < 1 || day > last) return null;
  return '$year-${month.toString().padLeft(2, '0')}-${day.toString().padLeft(2, '0')}';
}

/// YYMMDD → YYYY-MM-DD
String? _yymmdd(String v, DateTime now) {
  if (!RegExp(r'^\d{6}$').hasMatch(v)) return null;
  return _date(_year(int.parse(v.substring(0, 2)), now), int.parse(v.substring(2, 4)), int.parse(v.substring(4, 6)));
}

// ─── HIBC (ANSI/HIBC 2.6) ────────────────────────────────────────────────

const _hibcChars = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ-. \$/+%';

/// Ek B: baştaki + dahil tüm karakter değerlerinin toplamı mod 43
String? _mod43(String s) {
  var sum = 0;
  for (final ch in s.split('')) {
    final v = _hibcChars.indexOf(ch);
    if (v == -1) return null;
    sum += v;
  }
  return _hibcChars[sum % 43];
}

Gs1Data _hibc(String s, DateTime now) {
  final out = Gs1Data();
  final body = s.substring(0, s.length - 1);
  if (s.length < 3 || _mod43(body) != s[s.length - 1]) {
    return out..error = 'HIBC barkodu hatalı okundu (kontrol karakteri tutmuyor). Tekrar okutun.';
  }

  final parts = body.substring(1).split('/');
  final primary = parts.first;

  // Ayrı basılmış ikincil barkod: +$$…  (sondaki karakter birincile bağ karakteri)
  if (!RegExp(r'^[A-Z][A-Z0-9]{3}[A-Z0-9]{1,18}\d$').hasMatch(primary)) {
    final secondary = primary.substring(0, primary.length - 1);
    if (secondary.startsWith(r'$') || RegExp(r'^\d{5}').hasMatch(secondary)) {
      _hibcSecondary(secondary, out, now);
      return out..hibcLink = primary[primary.length - 1];
    }
    return out..plain = s.trim();
  }

  out.plain = '+$primary';
  if (parts.length == 1) out.hibcCheck = s[s.length - 1];
  if (parts.length > 1) _hibcSecondary(parts[1], out, now);
  for (final extra in parts.skip(2)) {
    final m = RegExp(r'^(14|16)D(\d{4})(\d{2})(\d{2})$').firstMatch(extra);
    if (m == null) continue;
    final d = _date(int.parse(m[2]!), int.parse(m[3]!), int.parse(m[4]!));
    if (m[1] == '14') {
      out.expiry = d;
    } else {
      out.produced = d;
    }
  }
  return out;
}

/// $lot · $$[tarih]lot · $+seri · $$+[tarih]seri · YYJJJlot (eski biçim)
void _hibcSecondary(String s, Gs1Data out, DateTime now) {
  if (s.startsWith(r'$$+')) {
    out.serial = _hibcDated(s.substring(3), out, now);
  } else if (s.startsWith(r'$+')) {
    out.serial = s.substring(2);
  } else if (s.startsWith(r'$$')) {
    out.lot = _hibcDated(s.substring(2), out, now);
  } else if (s.startsWith(r'$')) {
    out.lot = s.substring(1);
  } else if (RegExp(r'^\d{5}').hasMatch(s)) {
    out.expiry = _julian(s.substring(0, 5), now);
    out.lot = s.substring(5);
  }
  if (out.lot?.isEmpty ?? false) out.lot = null;
  if (out.serial?.isEmpty ?? false) out.serial = null;
}

/// Tarih biçim bayrağına göre SKT'yi yazar, kalan lot/seri metnini döndürür.
String _hibcDated(String s, Gs1Data out, DateTime now) {
  if (s.isEmpty) return s;
  int yy(int at) => _year(int.parse(s.substring(at, at + 2)), now);
  int n(int at) => int.parse(s.substring(at, at + 2));
  bool has(int len) => s.length >= len && RegExp(r'^\d+$').hasMatch(s.substring(1, len));

  switch (s[0]) {
    case '0' || '1': // MMYY → ayın son günü
      if (!RegExp(r'^\d{4}').hasMatch(s)) return s;
      out.expiry = _date(yy(2), n(0), 0);
      return s.substring(4);
    case '2' when has(7): // MMDDYY
      out.expiry = _date(yy(5), n(1), n(3));
      return s.substring(7);
    case '3' when has(7): // YYMMDD
      out.expiry = _date(yy(1), n(3), n(5));
      return s.substring(7);
    case '4' when has(9): // YYMMDDHH
      out.expiry = _date(yy(1), n(3), n(5));
      return s.substring(9);
    case '5' when has(6): // YYJJJ
      out.expiry = _julian(s.substring(1, 6), now);
      return s.substring(6);
    case '6' when has(8): // YYJJJHH
      out.expiry = _julian(s.substring(1, 6), now);
      return s.substring(8);
    case '7': // tarih yok
      return s.substring(1);
    case '8' when has(3): // 2 haneli miktar, ardından yine tarih bayrağı
      return _hibcDated(s.substring(3), out, now);
    case '9' when has(6): // 5 haneli miktar
      return _hibcDated(s.substring(6), out, now);
  }
  return s;
}

/// YYJJJ (yılın kaçıncı günü) → YYYY-MM-DD
String? _julian(String v, DateTime now) {
  final year = _year(int.parse(v.substring(0, 2)), now);
  final day = int.parse(v.substring(2));
  final d = DateTime(year, 1, day);
  if (day < 1 || d.year != year) return null;
  return _date(d.year, d.month, d.day);
}
