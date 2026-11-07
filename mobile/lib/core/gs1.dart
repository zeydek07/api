/// GS1 karekod / barkod çözücü (tıbbi cihaz UDI etiketleri). Paneldeki
/// panel/src/app/core/gs1.ts ile aynı kurallar.
///
///   (01)08691234567893(17)271231(10)LOT42(21)SN001   ← parantezli
///   010869123456789317271231 10LOT42 [GS] 21SN001    ← ham, değişken alanlar GS ile biter
///   8691234567893                                    ← düz EAN-13 / GTIN
class Gs1Data {
  String? gtin;
  String? lot;
  String? serial;

  /// YYYY-MM-DD
  String? expiry;

  /// YYYY-MM-DD
  String? produced;

  /// Karekod değil, düz barkod okundu
  String? plain;

  bool get isEmpty => gtin == null && plain == null;
}

const _fixed = {'01': 14, '17': 6, '11': 6};
const _variable = {'10', '21'};
const _gs = '\x1d';

Gs1Data parseGs1(String raw) {
  final input = raw.trim().replaceFirst(RegExp(r'^\][A-Za-z]\d'), '');
  final out = Gs1Data();
  if (input.isEmpty) return out;

  if (RegExp(r'^\d{8,14}$').hasMatch(input)) {
    return out
      ..plain = input
      ..gtin = input.padLeft(14, '0');
  }

  final fields = input.startsWith('(') ? _parenthesized(input) : _raw(input);
  out.gtin = fields['01'];
  out.lot = fields['10'];
  out.serial = fields['21'];
  if (fields['17'] != null) out.expiry = _yymmdd(fields['17']!);
  if (fields['11'] != null) out.produced = _yymmdd(fields['11']!);
  if (out.gtin == null && out.lot == null && out.serial == null) out.plain = input;
  return out;
}

Map<String, String> _parenthesized(String s) {
  final out = <String, String>{};
  for (final m in RegExp(r'\((\d{2,4})\)([^(]*)').allMatches(s)) {
    out[m.group(1)!] = m.group(2)!.trim();
  }
  return out;
}

Map<String, String> _raw(String s) {
  final out = <String, String>{};
  var i = 0;
  while (i < s.length) {
    if (s[i] == _gs) {
      i++;
      continue;
    }
    if (i + 2 > s.length) break;
    final ai = s.substring(i, i + 2);
    i += 2;
    final len = _fixed[ai];
    if (len != null) {
      if (i + len > s.length) break;
      out[ai] = s.substring(i, i + len);
      i += len;
    } else if (_variable.contains(ai)) {
      final end = s.indexOf(_gs, i);
      out[ai] = s.substring(i, end == -1 ? s.length : end);
      i = end == -1 ? s.length : end + 1;
    } else {
      break; // bilinmeyen AI
    }
  }
  return out;
}

/// YYMMDD → YYYY-MM-DD. GS1 kuralı: gün 00 ise ayın son günü.
String? _yymmdd(String v) {
  if (!RegExp(r'^\d{6}$').hasMatch(v)) return null;
  final year = 2000 + int.parse(v.substring(0, 2));
  final month = int.parse(v.substring(2, 4));
  var day = int.parse(v.substring(4, 6));
  if (month < 1 || month > 12) return null;
  if (day == 0) day = DateTime(year, month + 1, 0).day;
  return '$year-${month.toString().padLeft(2, '0')}-${day.toString().padLeft(2, '0')}';
}
