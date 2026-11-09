import 'dart:convert';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:http/http.dart' as http;

/// Emülatörden bilgisayardaki API'ye 10.0.2.2 ile ulaşılır.
/// Gerçek cihazda: flutter run --dart-define=API_URL=http://192.168.1.20:8010/api
const apiUrl = String.fromEnvironment('API_URL', defaultValue: 'http://10.0.2.2:8010/api');

class ApiException implements Exception {
  ApiException(this.message, [this.status = 0]);
  final String message;
  final int status;

  @override
  String toString() => message;
}

/// Token'ı güvenli depoda tutan, her isteğe Bearer ekleyen istemci.
class Api {
  Api._();
  static final instance = Api._();

  final _storage = const FlutterSecureStorage();
  final _client = http.Client();
  String? _token;

  /// Token süresi dolunca / iptal edilince çağrılır (giriş ekranına döner).
  void Function()? onUnauthorized;

  Future<String?> loadToken() async => _token = await _storage.read(key: 'token');

  Future<void> saveToken(String? token) async {
    _token = token;
    if (token == null) {
      await _storage.delete(key: 'token');
    } else {
      await _storage.write(key: 'token', value: token);
    }
  }

  Future<dynamic> get(String path, [Map<String, Object?>? query]) => _send('GET', path, query: query);

  Future<dynamic> post(String path, Object? body, {Map<String, String>? headers}) =>
      _send('POST', path, body: body, headers: headers);

  Future<dynamic> put(String path, Object? body) => _send('PUT', path, body: body);

  Future<dynamic> _send(
    String method,
    String path, {
    Map<String, Object?>? query,
    Object? body,
    Map<String, String>? headers,
  }) async {
    final params = query?.map((k, v) => MapEntry(k, '$v'))?..removeWhere((_, v) => v == 'null');
    final uri = Uri.parse('$apiUrl/$path').replace(queryParameters: params?.isEmpty ?? true ? null : params);
    final req = http.Request(method, uri)
      ..headers.addAll({
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        if (_token != null) 'Authorization': 'Bearer $_token',
        ...?headers,
      });
    if (body != null) req.body = jsonEncode(body);

    http.Response res;
    try {
      res = await http.Response.fromStream(await _client.send(req).timeout(const Duration(seconds: 30)));
    } catch (_) {
      throw ApiException('Sunucuya ulaşılamadı. İnternet bağlantınızı kontrol edin.');
    }

    final data = res.body.isEmpty ? null : _decode(res.body);
    if (res.statusCode >= 200 && res.statusCode < 300) return data;

    if (res.statusCode == 401 && _token != null) {
      await saveToken(null);
      onUnauthorized?.call();
      throw ApiException('Oturumunuz sona erdi, tekrar giriş yapın.', 401);
    }
    throw ApiException(_message(data, res.statusCode), res.statusCode);
  }

  dynamic _decode(String s) {
    try {
      return jsonDecode(s);
    } catch (_) {
      return null;
    }
  }

  /// Laravel doğrulama hatalarından ilkini gösterir.
  String _message(dynamic data, int status) {
    if (data is Map) {
      final errors = data['errors'];
      if (errors is Map && errors.isNotEmpty) {
        final first = errors.values.first;
        if (first is List && first.isNotEmpty) return '${first.first}';
      }
      if (data['message'] is String) return data['message'];
    }
    if (status == 403) return 'Bu işlem için yetkiniz yok.';
    if (status == 429) return 'Çok fazla deneme yapıldı, biraz bekleyin.';
    return 'Beklenmeyen bir hata oluştu ($status).';
  }
}
