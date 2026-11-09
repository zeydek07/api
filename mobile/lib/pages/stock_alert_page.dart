import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/models.dart';
import 'delivery_page.dart';
import 'vehicle_page.dart';

/// Ana sayfadaki bir stok uyarısının ürünleri (araç ya da hastane lokasyonu)
class StockAlertPage extends StatefulWidget {
  const StockAlertPage({super.key, required this.alert});
  final Map<String, dynamic> alert;

  @override
  State<StockAlertPage> createState() => _StockAlertPageState();
}

class _StockAlertPageState extends State<StockAlertPage> {
  final _api = Api.instance;
  List<StockRow>? _rows;
  String? _error;

  bool get _vehicle => widget.alert['scope'] == 'vehicle';

  @override
  void initState() {
    super.initState();
    _load();
  }

  /// Uyarı seviyesine uyan satır mı (sunucudaki gruplamayla aynı)
  bool _matches(StockRow r) {
    if (r.expiry == null) return false;
    final today = trNow();
    final days = DateTime.parse(r.expiry!).difference(DateTime(today.year, today.month, today.day)).inDays;
    return switch (widget.alert['level']) {
      'expired' => days < 0,
      'critical' => days >= 0 && days <= 30,
      _ => days > 30 && days <= 90,
    };
  }

  Future<void> _load() async {
    final a = widget.alert;
    try {
      final r = _vehicle
          ? await _api.get('mobile/warehouses/${a['warehouse_id']}/stock')
          : await _api.get('hospitals/${a['hospital_id']}/consignment', {'location_id': a['warehouse_id']});
      final list = ((r['stocks'] ?? r['rows']) as List).map((e) => StockRow(Map<String, dynamic>.from(e)));
      if (mounted) {
        setState(() => _rows = list
            .where((s) => _vehicle || s.json['warehouse_id'] == a['warehouse_id'])
            .where(_matches)
            .toList());
      }
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    }
  }

  @override
  Widget build(BuildContext context) {
    final a = widget.alert;
    final rows = _rows;
    return Scaffold(
      appBar: AppBar(title: Text(a['title'] ?? 'Stok uyarısı')),
      body: _error != null
          ? Center(child: Text(_error!))
          : rows == null
              ? const Center(child: CircularProgressIndicator())
              : ListView(
                  padding: const EdgeInsets.only(top: 8, bottom: 24),
                  children: [
                    Padding(
                      padding: const EdgeInsets.fromLTRB(16, 4, 16, 8),
                      child: Text(
                        switch (a['level']) {
                          'expired' => 'SKT\'si geçmiş ürünler. Kullanılamaz; depoya iade edilmeli.',
                          'critical' => 'SKT\'sine 30 günden az kalan ürünler. Önce bunlar kullanılmalı ya da geri alınmalı.',
                          _ => 'SKT\'sine 90 günden az kalan ürünler.',
                        },
                        style: TextStyle(color: Theme.of(context).hintColor),
                      ),
                    ),
                    if (rows.isEmpty) const Padding(padding: EdgeInsets.all(24), child: Text('Bu uyarıya ait ürün kalmadı.')),
                    for (final r in rows) StockRowTile(r),
                  ],
                ),
      bottomNavigationBar: _vehicle
          ? null
          : SafeArea(
              child: Padding(
                padding: const EdgeInsets.fromLTRB(16, 8, 16, 12),
                child: FilledButton.icon(
                  style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52)),
                  onPressed: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => const DeliveryPage(initialReturn: true))),
                  icon: const Icon(Icons.undo),
                  label: const Text('Hastaneden geri al'),
                ),
              ),
            ),
    );
  }
}
