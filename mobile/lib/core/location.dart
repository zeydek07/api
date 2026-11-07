import 'package:geolocator/geolocator.dart';

/// İşlem anındaki konum. API kuralı: ya latitude + longitude gönderilir ya da
/// location_error (denied | unavailable | timeout | unsupported). Konum alınamazsa
/// belge "konum alınamadı" olarak işaretlenir; işlem yine yapılır.
class CapturedLocation {
  CapturedLocation.ok(Position p)
      : latitude = p.latitude,
        longitude = p.longitude,
        accuracy = p.accuracy,
        error = null;

  CapturedLocation.failed(this.error)
      : latitude = null,
        longitude = null,
        accuracy = null;

  final double? latitude;
  final double? longitude;
  final double? accuracy;
  final String? error;

  bool get ok => latitude != null;

  Map<String, Object?> toJson() => ok
      ? {'latitude': latitude, 'longitude': longitude, 'location_accuracy': accuracy}
      : {'location_error': error};

  static const labels = {
    'denied': 'Konum izni verilmedi',
    'unavailable': 'Konum servisi kapalı veya konum bulunamadı',
    'timeout': 'Konum zamanında alınamadı',
    'unsupported': 'Cihaz konum desteklemiyor',
  };

  String get label => ok ? 'Konum alındı (±${accuracy!.round()} m)' : labels[error] ?? 'Konum alınamadı';
}

Future<CapturedLocation> capturePosition() async {
  try {
    if (!await Geolocator.isLocationServiceEnabled()) return CapturedLocation.failed('unavailable');
    var perm = await Geolocator.checkPermission();
    if (perm == LocationPermission.denied) perm = await Geolocator.requestPermission();
    if (perm == LocationPermission.denied || perm == LocationPermission.deniedForever) {
      return CapturedLocation.failed('denied');
    }
    final p = await Geolocator.getCurrentPosition(
      locationSettings: const LocationSettings(accuracy: LocationAccuracy.high, timeLimit: Duration(seconds: 10)),
    );
    return CapturedLocation.ok(p);
  } on LocationServiceDisabledException {
    return CapturedLocation.failed('unavailable');
  } on PermissionDeniedException {
    return CapturedLocation.failed('denied');
  } catch (e) {
    return CapturedLocation.failed(e.toString().contains('TimeoutException') ? 'timeout' : 'unavailable');
  }
}
