import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/gs1.dart';
import '../core/models.dart';
import '../ui/common.dart';
import '../ui/scanner_page.dart';

/// Depoya mal kabul (POST /stocks/receive). Karekod okutulunca ürün, lot, seri
/// ve SKT otomatik dolar; seri numaralı üründe her okutma ayrı satırdır.
class ReceivePage extends StatefulWidget {
  const ReceivePage({super.key});

  @override
  State<ReceivePage> createState() => _ReceivePageState();
}

class _Line {
  _Line(this.product, {this.lot, this.serial, this.expiry});
  final Product product;
  String? lot;
  String? serial;
  String? expiry;
  double quantity = 1;

  String? get problem {
    if (product.tracking == 'serial' && (serial ?? '').isEmpty) return 'Seri numarası gerekli';
    if (product.tracking == 'lot' && (lot ?? '').isEmpty) return 'Lot numarası gerekli';
    return null;
  }

  Map<String, Object?> toJson() => {
        'product_id': product.id,
        'quantity': quantity,
        'lot_number': lot,
        'serial_number': serial,
        'expiry_date': expiry,
      };
}

class _ReceivePageState extends State<ReceivePage> {
  final _api = Api.instance;
  List<Warehouse> _warehouses = [];
  Warehouse? _warehouse;
  final _lines = <_Line>[];
  final _reference = TextEditingController();
  bool _saving = false;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _api.get('warehouses', {'is_active': 1}).then((r) {
      final list = (r as List).map((e) => Warehouse(Map<String, dynamic>.from(e))).toList();
      setState(() {
        _warehouses = list;
        if (list.length == 1) _warehouse = list.first;
      });
    }).catchError((Object e) {
      if (mounted) showMessage(context, '$e', error: true);
    });
  }

  Future<Product?> _findProduct(Gs1Data g) async {
    for (final code in {g.gtin, g.plain}.whereType<String>()) {
      try {
        return Product(Map<String, dynamic>.from(await _api.get('products/barcode/${Uri.encodeComponent(code)}')));
      } on ApiException catch (e) {
        if (e.status != 404) rethrow;
      }
    }
    return null;
  }

  Future<void> _scan() async {
    final raw = await scanBarcode(context);
    if (raw == null || !mounted) return;
    final g = parseGs1(raw);
    if (g.isEmpty) return showMessage(context, 'Karekod okunamadı.', error: true);

    setState(() => _busy = true);
    try {
      final product = await _findProduct(g);
      if (!mounted) return;
      if (product == null) return showMessage(context, 'Bu barkoda ait ürün kartı yok. Önce panelden ürünü tanımlayın.', error: true);
      _addScanned(product, g);
    } on ApiException catch (e) {
      if (mounted) showMessage(context, e.message, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _addScanned(Product product, Gs1Data g) {
    if (product.tracking == 'serial') {
      if (g.serial != null && _lines.any((l) => l.product.id == product.id && l.serial == g.serial)) {
        return showMessage(context, '${product.name} SN ${g.serial} zaten listede.', error: true);
      }
      setState(() => _lines.insert(0, _Line(product, lot: g.lot, serial: g.serial, expiry: g.expiry)));
      if (g.serial == null) _edit(_lines.first);
    } else {
      final lot = product.tracking == 'lot' ? g.lot : null;
      final expiry = product.tracking == 'lot' ? g.expiry : null;
      final same = _lines.where((l) => l.product.id == product.id && l.lot == lot && l.expiry == expiry).firstOrNull;
      setState(() {
        if (same != null) {
          same.quantity += 1;
        } else {
          _lines.insert(0, _Line(product, lot: lot, expiry: expiry));
        }
      });
      if (product.tracking == 'lot' && lot == null) _edit(same ?? _lines.first);
    }
    showMessage(context, '✓ ${product.name}${g.serial != null ? ' · SN ${g.serial}' : ''}${g.lot != null ? ' · LOT ${g.lot}' : ''}');
  }

  /// Barkodu olmayan ürün için adıyla arama
  Future<void> _search() async {
    final product = await showModalBottomSheet<Product>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (_) => const _ProductSearch(),
    );
    if (product == null || !mounted) return;
    final line = _Line(product);
    setState(() => _lines.insert(0, line));
    if (product.tracking != 'none') _edit(line);
  }

  Future<void> _edit(_Line line) async {
    final lot = TextEditingController(text: line.lot);
    final serial = TextEditingController(text: line.serial);
    final quantity = TextEditingController(text: qty(line.quantity));
    String? expiry = line.expiry;
    final saved = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setLocal) => AlertDialog(
          title: Text(line.product.name),
          content: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                if (line.product.tracking == 'serial')
                  TextField(controller: serial, decoration: const InputDecoration(labelText: 'Seri numarası *'))
                else
                  TextField(
                    controller: quantity,
                    keyboardType: const TextInputType.numberWithOptions(decimal: true),
                    decoration: InputDecoration(labelText: 'Miktar (${line.product.unit})'),
                  ),
                if (line.product.tracking != 'none') ...[
                  const SizedBox(height: 12),
                  TextField(
                    controller: lot,
                    decoration: InputDecoration(labelText: line.product.tracking == 'lot' ? 'Lot numarası *' : 'Lot numarası'),
                  ),
                  const SizedBox(height: 12),
                  InkWell(
                    onTap: () async {
                      final d = await showDatePicker(
                        context: ctx,
                        firstDate: DateTime.now(),
                        lastDate: DateTime(2100),
                        initialDate: expiry == null ? DateTime.now() : DateTime.parse(expiry!),
                      );
                      if (d != null) setLocal(() => expiry = d.toIso8601String().substring(0, 10));
                    },
                    child: InputDecorator(
                      decoration: const InputDecoration(labelText: 'SKT', suffixIcon: Icon(Icons.event)),
                      child: Text(dateTr(expiry) ?? 'Seçin'),
                    ),
                  ),
                ],
              ],
            ),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Vazgeç')),
            FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Tamam')),
          ],
        ),
      ),
    );
    if (saved != true) return;
    setState(() {
      line.lot = lot.text.trim().isEmpty ? null : lot.text.trim();
      line.serial = line.product.tracking == 'serial' && serial.text.trim().isNotEmpty ? serial.text.trim() : null;
      line.expiry = expiry;
      if (line.product.tracking != 'serial') {
        final q = toNum(quantity.text.replaceAll(',', '.'));
        if (q > 0) line.quantity = q;
      }
    });
  }

  String? get _missing {
    if (_warehouse == null) return 'Depo seçin';
    if (_lines.isEmpty) return 'Ürün okutun veya ekleyin';
    final bad = _lines.where((l) => l.problem != null).firstOrNull;
    if (bad != null) return '${bad.product.name}: ${bad.problem}';
    return null;
  }

  Future<void> _submit() async {
    setState(() => _saving = true);
    try {
      await _api.post('stocks/receive', {
        'warehouse_id': _warehouse!.id,
        'reference': _reference.text.trim().isEmpty ? null : _reference.text.trim(),
        'items': _lines.map((l) => l.toJson()).toList(),
      });
      if (!mounted) return;
      showMessage(context, 'Stok girişi yapıldı: ${_lines.length} kalem → ${_warehouse!.name}');
      setState(() {
        _lines.clear();
        _reference.clear();
      });
    } on ApiException catch (e) {
      if (mounted) showMessage(context, e.message, error: true);
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final units = _lines.fold<double>(0, (s, l) => s + l.quantity);
    return Scaffold(
      appBar: AppBar(title: const Text('Stok Girişi')),
      body: ListView(
        padding: const EdgeInsets.only(bottom: 16),
        children: [
          const StepTitle('1. Depo ve belge'),
          PickerField<Warehouse>(
            label: 'Giriş yapılacak depo',
            icon: Icons.warehouse_outlined,
            value: _warehouse,
            items: _warehouses,
            itemLabel: (w) => w.label,
            onChanged: (w) => setState(() => _warehouse = w),
          ),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
            child: TextField(
              controller: _reference,
              decoration: const InputDecoration(labelText: 'İrsaliye / fatura no', prefixIcon: Icon(Icons.receipt_long_outlined)),
            ),
          ),
          StepTitle('2. Ürünler', trailing: _lines.isEmpty ? null : Text('${_lines.length} kalem · toplam ${qty(units)}')),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16),
            child: Row(
              children: [
                Expanded(
                  child: FilledButton.tonalIcon(
                    onPressed: _busy ? null : _scan,
                    icon: const Icon(Icons.qr_code_scanner),
                    label: const Text('Barkod okut'),
                  ),
                ),
                const SizedBox(width: 8),
                OutlinedButton.icon(onPressed: _search, icon: const Icon(Icons.search), label: const Text('Ara')),
              ],
            ),
          ),
          if (_busy) const Padding(padding: EdgeInsets.all(12), child: LinearProgressIndicator()),
          const SizedBox(height: 8),
          for (final l in _lines)
            Card(
              child: ListTile(
                title: Text(l.product.name),
                subtitle: Text(
                  [
                    if (l.serial != null) 'SN ${l.serial}',
                    if (l.lot != null) 'LOT ${l.lot}',
                    if (l.expiry != null) 'SKT ${dateTr(l.expiry)}',
                    ?l.problem,
                  ].join(' · '),
                  style: l.problem == null ? null : TextStyle(color: Theme.of(context).colorScheme.error),
                ),
                trailing: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text('${qty(l.quantity)} ${l.product.unit}', style: const TextStyle(fontWeight: FontWeight.w600)),
                    IconButton(
                      tooltip: 'Kaldır',
                      icon: const Icon(Icons.close),
                      onPressed: () => setState(() => _lines.remove(l)),
                    ),
                  ],
                ),
                onTap: () => _edit(l),
              ),
            ),
        ],
      ),
      bottomNavigationBar: SubmitBar(label: 'Girişi kaydet', saving: _saving, missing: _missing, onPressed: _submit),
    );
  }
}

class _ProductSearch extends StatefulWidget {
  const _ProductSearch();

  @override
  State<_ProductSearch> createState() => _ProductSearchState();
}

class _ProductSearchState extends State<_ProductSearch> {
  List<Product> _results = [];
  bool _loading = false;

  Future<void> _search(String q) async {
    if (q.trim().length < 2) return setState(() => _results = []);
    setState(() => _loading = true);
    try {
      final r = await Api.instance.get('products', {'q': q.trim(), 'is_active': 1, 'per_page': 30});
      if (mounted) setState(() => _results = (r['data'] as List).map((e) => Product(Map<String, dynamic>.from(e))).toList());
    } catch (_) {
      // sessiz geç; kullanıcı yeniden yazabilir
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: MediaQuery.of(context).size.height * 0.8,
      child: Column(
        children: [
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16),
            child: TextField(
              autofocus: true,
              decoration: const InputDecoration(prefixIcon: Icon(Icons.search), hintText: 'Ürün adı veya kodu'),
              onChanged: _search,
            ),
          ),
          if (_loading) const LinearProgressIndicator(),
          Expanded(
            child: ListView(
              children: [
                for (final p in _results)
                  ListTile(
                    title: Text(p.name),
                    subtitle: Text([p.code, p.tracking == 'serial' ? 'Seri takipli' : p.tracking == 'lot' ? 'Lot takipli' : null].whereType<String>().join(' · ')),
                    onTap: () => Navigator.pop(context, p),
                  ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
