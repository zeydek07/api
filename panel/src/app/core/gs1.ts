/**
 * Tıbbi cihaz (UDI) barkod / karekod çözücü. Mobildeki mobile/lib/core/gs1.dart ile
 * aynı kurallar; ikisi aynı test örnekleriyle sınanır (e2e/gs1.spec.ts, mobile/test/gs1_test.dart).
 *
 * GS1 (ÜTS'deki barkodların çoğu):
 *   (01)08691234567890(17)271231(10)LOT42(21)SN001   ← parantezli, insan okunur
 *   0108691234567890172712311 0LOT42<GS>21SN001      ← ham, değişken alanlar GS (\x1d) ile biter
 *   https://id.gs1.org/01/08691234567890/10/LOT42     ← GS1 Digital Link (QR)
 *   8691234567890                                    ← düz EAN-13 / GTIN
 *
 * HIBC (bazı implant üreticileri), `+` ile başlar, sonda mod-43 kontrol karakteri:
 *   +H123ABC01/$$3271231LOT42E                       ← ürün / SKT+lot
 */
export interface Gs1Data {
  gtin?: string;
  lot?: string;
  serial?: string;
  /** YYYY-MM-DD */
  expiry?: string;
  /** YYYY-MM-DD */
  produced?: string;
  /** GTIN içermeyen kod: düz barkod, ürün kodu ya da HIBC ürün kimliği (+LIC…) */
  plain?: string;
  /** Barkod okundu ama güvenilmez (kontrol hanesi tutmuyor vb.); kullanıcıya gösterilir */
  error?: string;
}

const GS = '\x1d';

export function parseGs1(raw: string, now: Date = new Date()): Gs1Data {
  // Sondaki boşluk HIBC kontrol karakteri olabilir; sadece satır sonlarını at
  let input = raw.replace(/^\s+|[\r\n]+$/g, '').replace(/^\][A-Za-z]\d/, ''); // tarayıcı öneki (]d2, ]C1)
  if (!input.trim()) return {};
  if (input.startsWith('+')) return parseHibc(input, now);
  input = input.trim();

  if (/^\d{8,14}$/.test(input)) {
    const gtin = input.padStart(14, '0');
    return gtinValid(gtin) ? { plain: input, gtin } : { plain: input };
  }

  const fields = digitalLink(input) ?? (input.startsWith('(') ? parenthesized(input) : rawFields(input));
  if (!fields?.['01']) return { plain: input };
  if (!gtinValid(fields['01'])) {
    return { error: 'Karekod hatalı okundu (GTIN kontrol hanesi tutmuyor). Tekrar okutun.' };
  }
  const out: Gs1Data = { gtin: fields['01'] };
  if (fields['10']) out.lot = fields['10'];
  if (fields['21']) out.serial = fields['21'];
  if (fields['17']) out.expiry = yymmdd(fields['17'], now);
  if (fields['11']) out.produced = yymmdd(fields['11'], now);
  return out;
}

function parenthesized(s: string): Record<string, string> {
  const out: Record<string, string> = {};
  for (const m of s.matchAll(/\((\d{2,4})\)([^(]*)/g)) out[m[1]] ??= m[2].trim();
  return out;
}

/** AI kodunun uzunluğu, ilk iki haneye göre (GS1 Genel Spesifikasyon AI tablosu) */
function aiLength(p: string): number | undefined {
  const n = Number(p);
  if (!/^\d\d$/.test(p)) return undefined;
  if (n <= 22 || n === 30 || n === 37 || n >= 90) return 2;
  if ((n >= 23 && n <= 25) || (n >= 40 && n <= 42) || n === 71) return 3;
  if ((n >= 31 && n <= 36) || n === 39 || n === 43 || n === 70 || n === 72 || (n >= 80 && n <= 82)) return 4;
  return undefined;
}

/** Önceden tanımlı sabit veri uzunlukları (GS1 tablosu); diğer tüm alanlar GS ile biter */
const FIXED: Record<string, number> = {
  '00': 18, '01': 14, '02': 14, '03': 14, '04': 16, '11': 6, '12': 6, '13': 6, '14': 6, '15': 6, '16': 6,
  '17': 6, '18': 6, '19': 6, '20': 2, '31': 6, '32': 6, '33': 6, '34': 6, '35': 6, '36': 6, '41': 13,
};

function rawFields(s: string): Record<string, string> | undefined {
  const out: Record<string, string> = {};
  let i = 0;
  while (i < s.length) {
    if (s[i] === GS) {
      i++;
      continue;
    }
    const len = aiLength(s.slice(i, i + 2));
    if (!len || i + len > s.length) return undefined; // GS1 değil
    const ai = s.slice(i, i + len);
    i += len;
    const fixed = FIXED[ai.slice(0, 2)];
    let value: string;
    if (fixed) {
      value = s.slice(i, i + fixed);
      if (value.length < fixed) return undefined;
      i += fixed;
    } else {
      const end = s.indexOf(GS, i);
      value = s.slice(i, end === -1 ? undefined : end);
      i = end === -1 ? s.length : end + 1;
    }
    out[ai] ??= value;
  }
  return out;
}

/** https://alan.adi/01/{gtin}/10/{lot}/21/{seri}?17=YYMMDD */
function digitalLink(s: string): Record<string, string> | undefined {
  if (!/^https?:\/\//i.test(s)) return undefined;
  let url: URL;
  try {
    url = new URL(s);
  } catch {
    return undefined;
  }
  const seg = url.pathname.split('/').filter(Boolean);
  const i = seg.indexOf('01');
  if (i === -1 || i + 1 >= seg.length) return undefined;
  const out: Record<string, string> = {};
  for (let k = i; k + 1 < seg.length; k += 2) out[seg[k]] = decodeURIComponent(seg[k + 1]);
  url.searchParams.forEach((v, k) => (out[k] = v));
  return out;
}

/** GS1 mod-10: sağdan itibaren 3,1,3,1… ağırlıkları */
function gtinValid(gtin: string): boolean {
  if (!/^\d{14}$/.test(gtin)) return false;
  let sum = 0;
  for (let i = 0; i < 13; i++) sum += Number(gtin[12 - i]) * (i % 2 === 0 ? 3 : 1);
  return (10 - (sum % 10)) % 10 === Number(gtin[13]);
}

/** İki haneli yıl, GS1 kuralı (7.12): bu yıla göre fark ≥ 51 → önceki yüzyıl, ≤ −50 → sonraki */
function year(yy: number, now: Date): number {
  const century = now.getFullYear() - (now.getFullYear() % 100);
  const diff = yy - (now.getFullYear() % 100);
  if (diff >= 51) return century - 100 + yy;
  if (diff <= -50) return century + 100 + yy;
  return century + yy;
}

function date(y: number, month: number, day: number): string | undefined {
  if (month < 1 || month > 12) return undefined;
  const last = new Date(y, month, 0).getDate();
  if (day === 0) day = last; // GS1: gün 00 → ayın son günü
  if (day < 1 || day > last) return undefined;
  return `${y}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
}

function yymmdd(v: string, now: Date): string | undefined {
  if (!/^\d{6}$/.test(v)) return undefined;
  return date(year(Number(v.slice(0, 2)), now), Number(v.slice(2, 4)), Number(v.slice(4, 6)));
}

// ─── HIBC (ANSI/HIBC 2.6) ────────────────────────────────────────────────

const HIBC_CHARS = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ-. $/+%';

/** Ek B: baştaki + dahil tüm karakter değerlerinin toplamı mod 43 */
function mod43(s: string): string | undefined {
  let sum = 0;
  for (const ch of s) {
    const v = HIBC_CHARS.indexOf(ch);
    if (v === -1) return undefined;
    sum += v;
  }
  return HIBC_CHARS[sum % 43];
}

function parseHibc(s: string, now: Date): Gs1Data {
  const body = s.slice(0, -1);
  if (s.length < 3 || mod43(body) !== s.at(-1)) {
    return { error: 'HIBC barkodu hatalı okundu (kontrol karakteri tutmuyor). Tekrar okutun.' };
  }
  const [primary, secondary, ...extras] = body.slice(1).split('/');
  const out: Gs1Data = {};

  // Ayrı basılmış ikincil barkod: +$$…  (sondaki karakter birincile bağ karakteri)
  if (!/^[A-Z][A-Z0-9]{3}[A-Z0-9]{1,18}\d$/.test(primary)) {
    const sec = primary.slice(0, -1);
    if (sec.startsWith('$') || /^\d{5}/.test(sec)) {
      hibcSecondary(sec, out, now);
      return { ...out, error: 'Bu barkodda sadece lot/SKT var. Önce ürün barkodunu okutun.' };
    }
    return { plain: s.trim() };
  }

  out.plain = `+${primary}`;
  if (secondary !== undefined) hibcSecondary(secondary, out, now);
  for (const extra of extras) {
    const m = /^(14|16)D(\d{4})(\d{2})(\d{2})$/.exec(extra);
    if (!m) continue;
    const d = date(Number(m[2]), Number(m[3]), Number(m[4]));
    if (m[1] === '14') out.expiry = d;
    else out.produced = d;
  }
  return out;
}

/** $lot · $$[tarih]lot · $+seri · $$+[tarih]seri · YYJJJlot (eski biçim) */
function hibcSecondary(s: string, out: Gs1Data, now: Date): void {
  if (s.startsWith('$$+')) out.serial = hibcDated(s.slice(3), out, now);
  else if (s.startsWith('$+')) out.serial = s.slice(2);
  else if (s.startsWith('$$')) out.lot = hibcDated(s.slice(2), out, now);
  else if (s.startsWith('$')) out.lot = s.slice(1);
  else if (/^\d{5}/.test(s)) {
    out.expiry = julian(s.slice(0, 5), now);
    out.lot = s.slice(5);
  }
  if (!out.lot) delete out.lot;
  if (!out.serial) delete out.serial;
}

/** Tarih biçim bayrağına göre SKT'yi yazar, kalan lot/seri metnini döndürür */
function hibcDated(s: string, out: Gs1Data, now: Date): string {
  const yy = (at: number) => year(Number(s.slice(at, at + 2)), now);
  const n = (at: number) => Number(s.slice(at, at + 2));
  const has = (len: number) => s.length >= len && /^\d+$/.test(s.slice(1, len));

  switch (s[0]) {
    case '0':
    case '1': // MMYY → ayın son günü
      if (!/^\d{4}/.test(s)) return s;
      out.expiry = date(yy(2), n(0), 0);
      return s.slice(4);
    case '2': // MMDDYY
      if (!has(7)) break;
      out.expiry = date(yy(5), n(1), n(3));
      return s.slice(7);
    case '3': // YYMMDD
      if (!has(7)) break;
      out.expiry = date(yy(1), n(3), n(5));
      return s.slice(7);
    case '4': // YYMMDDHH
      if (!has(9)) break;
      out.expiry = date(yy(1), n(3), n(5));
      return s.slice(9);
    case '5': // YYJJJ
      if (!has(6)) break;
      out.expiry = julian(s.slice(1, 6), now);
      return s.slice(6);
    case '6': // YYJJJHH
      if (!has(8)) break;
      out.expiry = julian(s.slice(1, 6), now);
      return s.slice(8);
    case '7': // tarih yok
      return s.slice(1);
    case '8': // 2 haneli miktar, ardından yine tarih bayrağı
      if (!has(3)) break;
      return hibcDated(s.slice(3), out, now);
    case '9': // 5 haneli miktar
      if (!has(6)) break;
      return hibcDated(s.slice(6), out, now);
  }
  return s;
}

/** YYJJJ (yılın kaçıncı günü) → YYYY-MM-DD */
function julian(v: string, now: Date): string | undefined {
  const y = year(Number(v.slice(0, 2)), now);
  const day = Number(v.slice(2));
  const d = new Date(y, 0, day);
  if (day < 1 || d.getFullYear() !== y) return undefined;
  return date(y, d.getMonth() + 1, d.getDate());
}
