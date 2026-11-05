/**
 * Saha işleminin yapıldığı andaki konum.
 * API her teslimat / geri alma / kullanımda ya koordinat ya da konumun neden alınamadığını ister.
 */
export type LocationError = 'denied' | 'unavailable' | 'timeout' | 'unsupported';

export type CapturedLocation =
  | { latitude: number; longitude: number; location_accuracy: number; location_error?: undefined }
  | { latitude?: undefined; longitude?: undefined; location_accuracy?: undefined; location_error: LocationError };

export const LOCATION_ERROR_LABELS: Record<string, string> = {
  denied: 'Konum izni verilmedi',
  unavailable: 'Konum bulunamadı',
  timeout: 'Konum zaman aşımı',
  unsupported: 'Cihaz konum desteklemiyor',
  not_recorded: 'Konum kaydedilmemiş (eski kayıt)',
};

/**
 * Konumu şimdi alır. En fazla 30 sn önceki konum kabul edilir; hiçbir durumda işlemi engellemez,
 * alınamazsa nedenini döner.
 */
export function capturePosition(timeoutMs = 10000): Promise<CapturedLocation> {
  return new Promise((resolve) => {
    if (!('geolocation' in navigator)) return resolve({ location_error: 'unsupported' });
    navigator.geolocation.getCurrentPosition(
      (p) =>
        resolve({
          latitude: +p.coords.latitude.toFixed(7),
          longitude: +p.coords.longitude.toFixed(7),
          location_accuracy: Math.round(p.coords.accuracy),
        }),
      (e) =>
        resolve({
          location_error: e.code === e.PERMISSION_DENIED ? 'denied' : e.code === e.TIMEOUT ? 'timeout' : 'unavailable',
        }),
      { enableHighAccuracy: true, timeout: timeoutMs, maximumAge: 30000 },
    );
  });
}
