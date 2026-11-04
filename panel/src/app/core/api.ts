import { HttpParams } from '@angular/common/http';

/**
 * Geliştirmede /api istekleri proxy.conf.json ile Laravel'e (127.0.0.1:8010) gider.
 * Panel Laravel ile aynı alan adından yayınlanırsa bu değer değişmeden çalışır.
 */
export const API_URL = '/api';

/** Boş değerleri atarak sorgu parametresi oluşturur. */
export function toParams(values: Record<string, unknown>): HttpParams {
  let params = new HttpParams();
  for (const [key, value] of Object.entries(values)) {
    if (value === null || value === undefined || value === '') continue;
    params = params.set(key, typeof value === 'boolean' ? (value ? '1' : '0') : String(value));
  }
  return params;
}

/** "48.00" -> "48", "2.50" -> "2,5" */
export function qty(value: string | number | null | undefined, abs = false): string {
  const n = abs ? Math.abs(Number(value ?? 0)) : Number(value ?? 0);
  return n.toLocaleString('tr-TR', { maximumFractionDigits: 2 });
}

/** Laravel doğrulama hatalarını tek metne çevirir. */
export function errorMessage(err: unknown): string {
  const e = err as { status?: number; error?: { message?: string; errors?: Record<string, string[]> } };
  if (e?.error?.errors) {
    return Object.values(e.error.errors).flat().join('\n');
  }
  if (e?.status === 0) return 'Sunucuya ulaşılamıyor. API çalışıyor mu?';
  return e?.error?.message ?? 'Beklenmeyen bir hata oluştu.';
}
