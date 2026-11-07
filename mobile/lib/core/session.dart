import 'package:flutter/foundation.dart';

import 'api.dart';
import 'models.dart';

/// Oturum açmış kullanıcı ve yetkileri. Ekranlar hangi işlemi göstereceğine
/// buradaki yetki listesine bakarak karar verir (panelle aynı yetki anahtarları).
class Session extends ChangeNotifier {
  Session._();
  static final instance = Session._();

  final _api = Api.instance;
  User? user;
  bool ready = false;

  Future<void> restore() async {
    _api.onUnauthorized = () {
      user = null;
      notifyListeners();
    };
    if (await _api.loadToken() != null) {
      try {
        final r = await _api.get('auth/me');
        user = User(Map<String, dynamic>.from(r['user']));
      } on ApiException catch (e) {
        if (e.status == 401 || e.status == 403) await _api.saveToken(null);
      }
    }
    ready = true;
    notifyListeners();
  }

  Future<void> login(String email, String password) async {
    final r = await _api.post('auth/login', {
      'email': email.trim(),
      'password': password,
      'device_name': 'mobil-uygulama',
    });
    await _api.saveToken(r['token']);
    user = User(Map<String, dynamic>.from(r['user']));
    notifyListeners();
  }

  Future<void> logout() async {
    try {
      await _api.post('auth/logout', null);
    } catch (_) {
      // Sunucuya ulaşılamasa da cihazdaki oturum kapanır
    }
    await _api.saveToken(null);
    user = null;
    notifyListeners();
  }

  bool can(String permission) => user?.can(permission) ?? false;
}
