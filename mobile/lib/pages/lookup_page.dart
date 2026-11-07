import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/gs1.dart';
import '../core/models.dart';
import '../ui/common.dart';
import '../ui/scanner_page.dart';

/// Barkod okut → ürün kartı ve hangi depoda / hastanede, hangi lotta ne kadar var.
class LookupPage extends StatefulWidget {
  const LookupPage({super.key});

  @override
  State<LookupPage> createState() => _LookupPageState();
}

class _LookupPageState extends State<LookupPage> {
  final _api = Api.instance;
  Gs1Data? _scanned;
  Map<String, dynamic>? _product;
  bool _loading = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _scan());
  }

  Future<void> _scan() async {
    final raw = await scanBarcode(context);
    if (raw == null || !mounted) return;
    final g = parseGs1(raw);
    setState(() {
      _scanned = g;
      _product = null;
      _error = null;
      _loading = true;
    });
    try {
      Map<String, dynamic>? found;
      for (final code in {g.gtin, g.plain}.whereType<String>()) {
        try {
          found = Map<String, dynamic>.from(await _api.get('products/barcode/${Uri.encodeComponent(code)}'));
          break;
        } on ApiException catch (e) {
          if (e.status != 404) rethrow;
        }
      }
      if (found == null) {
        _error = 'Bu barkoda ait ürün kartı bulunamadı.';
      } else {
        _product = Map<String, dynamic>.from(await _api.get('products/${found['id']}'));
      }
    } on ApiException catch (e) {
      _error = e.message;
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final p = _product;
    final stocks = p == null ? <StockRow>[] : (p['stocks'] as List? ?? []).map((e) => StockRow(Map<String, dynamic>.from(e))).toList();
    final g = _scanned;

    return Scaffold(
      appBar: AppBar(title: const Text('Ürün Sorgula')),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: _scan,
        icon: const Icon(Icons.qr_code_scanner),
        label: const Text('Okut'),
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : ListView(
              padding: const EdgeInsets.only(bottom: 96),
              children: [
                if (g == null)
                  const Padding(padding: EdgeInsets.all(24), child: Text('Ürünün barkodunu veya karekodunu okutun.')),
                if (_error != null)
                  Padding(padding: const EdgeInsets.all(24), child: Text(_error!, style: TextStyle(color: theme.colorScheme.error))),
                if (g != null && (g.lot != null || g.serial != null || g.expiry != null))
                  Card(
                    child: ListTile(
                      leading: const Icon(Icons.qr_code_2),
                      title: const Text('Okunan etiket'),
                      subtitle: Text([
                        if (g.serial != null) 'SN ${g.serial}',
                        if (g.lot != null) 'LOT ${g.lot}',
                        if (g.expiry != null) 'SKT ${dateTr(g.expiry)}',
                      ].join(' · ')),
                    ),
                  ),
                if (p != null) ...[
                  Padding(
                    padding: const EdgeInsets.fromLTRB(16, 16, 16, 4),
                    child: Text(p['name'] ?? '', style: theme.textTheme.titleLarge),
                  ),
                  Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 16),
                    child: Text(
                      [p['code'], p['brand'], p['category'], p['gtin'] == null ? null : 'GTIN ${p['gtin']}'].whereType<String>().join(' · '),
                      style: TextStyle(color: theme.hintColor),
                    ),
                  ),
                  StepTitle(
                    'Stok durumu',
                    trailing: Text('${qty(stocks.fold<double>(0, (s, r) => s + r.quantity))} ${p['unit'] ?? 'adet'}'),
                  ),
                  if (stocks.isEmpty)
                    const Padding(padding: EdgeInsets.symmetric(horizontal: 16), child: Text('Hiçbir depoda stok yok.')),
                  for (final r in stocks)
                    Card(
                      color: _matches(r, g) ? theme.colorScheme.primaryContainer : null,
                      child: ListTile(
                        title: Text(_place(r)),
                        subtitle: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            if (r.detail.isNotEmpty) Text(r.detail),
                            if (r.expiry != null) Padding(padding: const EdgeInsets.only(top: 4), child: ExpiryChip(r)),
                          ],
                        ),
                        trailing: Text(qty(r.quantity), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
                      ),
                    ),
                ],
              ],
            ),
    );
  }

  /// Okutulan lot/seri satırını vurgula
  bool _matches(StockRow r, Gs1Data? g) =>
      g != null && ((g.serial != null && r.serial == g.serial) || (g.serial == null && g.lot != null && r.lot == g.lot));

  String _place(StockRow r) {
    final w = r.json['warehouse'] as Map?;
    final hospital = (w?['hospital'] as Map?)?['name'];
    return hospital == null ? (w?['name'] ?? '') : '$hospital · ${w?['name']}';
  }
}
