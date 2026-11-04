/**
 * GS1 karekod / barkod çözücü (tıbbi cihaz UDI etiketleri).
 *
 * Desteklenen biçimler:
 *   (01)08691234567893(17)271231(10)LOT42(21)SN001   ← parantezli, insan okunur
 *   0108691234567893172712311 0LOT42<GS>21SN001      ← ham, değişken alanlar GS (\x1d) ile biter
 *   8691234567893                                    ← düz EAN-13 / GTIN
 *
 * Kullanılan uygulama tanımlayıcıları (AI):
 *   01 GTIN (14) · 17 SKT (YYMMDD) · 11 üretim tarihi · 10 lot · 21 seri
 */
export interface Gs1Data {
  gtin?: string;
  lot?: string;
  serial?: string;
  /** YYYY-MM-DD */
  expiry?: string;
  /** YYYY-MM-DD */
  produced?: string;
  /** Karekod değil, düz barkod okundu */
  plain?: string;
}

const FIXED: Record<string, number> = { '01': 14, '17': 6, '11': 6 };
const VARIABLE = new Set(['10', '21']);
const GS = '\x1d';

export function parseGs1(raw: string): Gs1Data {
  const input = raw.trim().replace(/^\][A-Za-z]\d/, ''); // tarayıcının eklediği sembol öneki (]d2, ]C1)
  if (!input) return {};

  // Düz barkod (8-14 hane). GS1 karekodu en az 16 karakterdir: "01" + 14 hane
  if (/^\d{8,14}$/.test(input)) {
    return { plain: input, gtin: input.padStart(14, '0') };
  }

  const fields = input.startsWith('(') ? parseParenthesized(input) : parseRaw(input);
  const out: Gs1Data = {};
  if (fields['01']) out.gtin = fields['01'];
  if (fields['10']) out.lot = fields['10'];
  if (fields['21']) out.serial = fields['21'];
  if (fields['17']) out.expiry = yymmdd(fields['17']);
  if (fields['11']) out.produced = yymmdd(fields['11']);
  if (!out.gtin && !out.lot && !out.serial) out.plain = input;
  return out;
}

function parseParenthesized(s: string): Record<string, string> {
  const out: Record<string, string> = {};
  for (const m of s.matchAll(/\((\d{2,4})\)([^(]*)/g)) out[m[1]] = m[2].trim();
  return out;
}

function parseRaw(s: string): Record<string, string> {
  const out: Record<string, string> = {};
  let i = 0;
  while (i < s.length) {
    if (s[i] === GS) {
      i++;
      continue;
    }
    const ai = s.slice(i, i + 2);
    i += 2;
    if (FIXED[ai]) {
      out[ai] = s.slice(i, i + FIXED[ai]);
      i += FIXED[ai];
    } else if (VARIABLE.has(ai)) {
      const end = s.indexOf(GS, i);
      out[ai] = s.slice(i, end === -1 ? undefined : end);
      i = end === -1 ? s.length : end + 1;
    } else {
      break; // bilinmeyen AI: kalanı yorumlanamaz
    }
  }
  return out;
}

/** YYMMDD → YYYY-MM-DD. GS1 kuralı: gün 00 ise ayın son günü. */
function yymmdd(v: string): string | undefined {
  if (!/^\d{6}$/.test(v)) return undefined;
  const year = 2000 + Number(v.slice(0, 2));
  const month = Number(v.slice(2, 4));
  let day = Number(v.slice(4, 6));
  if (month < 1 || month > 12) return undefined;
  if (day === 0) day = new Date(year, month, 0).getDate();
  return `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
}
