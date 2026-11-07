import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/models.dart';
import '../ui/common.dart';
import '../ui/scanner_page.dart';
import '../ui/stock_picker.dart';

/// Depodan çıkış: başka bir depoya veya mümessil aracına transfer
/// (POST /stocks/transfer). Hastaneye bırakma bu ekrandan değil,
/// imzalı "Hastane Teslimatı" ile yapılır.
class TransferPage extends StatefulWidget {
  const TransferPage({super.key});

  @override
  State<TransferPage> createState() => _TransferPageState();
}

class _TransferPageState extends State<TransferPage> {
  final _api = Api.instance;
  List<Warehouse> _warehouses = [];
  Warehouse? _from;
  Warehouse? _to;
  final _cart = StockCart();
  final _note = TextEditingController();
  bool _loading = false;
  bool _saving = false;

  @override
  void initState() {
    super.initState();
    _api.get('warehouses', {'is_active': 1}).then((r) {
      setState(() => _warehouses = (r as List).map((e) => Warehouse(Map<String, dynamic>.from(e))).toList());
    }).catchError((Object e) {
      if (mounted) showMessage(context, '$e', error: true);
    });
  }

  Future<void> _loadStock() async {
    setState(() {
      _cart.reset([]);
      _loading = true;
    });
    try {
      final r = await _api.get('stocks', {'warehouse_id': _from!.id, 'per_page': 200});
      if (!mounted) return;
      setState(() => _cart.reset((r['data'] as List).map((e) => StockRow(Map<String, dynamic>.from(e))).toList()));
    } on ApiException catch (e) {
      if (mounted) showMessage(context, e.message, error: true);
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _scan() async {
    final raw = await scanBarcode(context);
    if (raw == null || !mounted) return;
    final (text, error) = _cart.scan(raw, notHere: 'Bu ürün ${_from!.name} deposunda yok.');
    setState(() {});
    showMessage(context, text, error: error);
  }

  String? get _missing {
    if (_from == null) return 'Çıkış yapılacak depoyu seçin';
    if (_to == null) return 'Gideceği depoyu / aracı seçin';
    if (_cart.lines.isEmpty) return 'En az bir ürün seçin';
    return null;
  }

  Future<void> _submit() async {
    setState(() => _saving = true);
    try {
      await _api.post('stocks/transfer', {
        'from_warehouse_id': _from!.id,
        'to_warehouse_id': _to!.id,
        'note': _note.text.trim().isEmpty ? null : _note.text.trim(),
        'items': [
          for (final (r, q) in _cart.lines)
            {
              'product_id': r.productId,
              'stock_id': r.id,
              'quantity': q,
              'lot_number': r.lot,
              'serial_number': r.serial,
              'expiry_date': r.expiry,
            },
        ],
      });
      if (!mounted) return;
      showMessage(context, 'Çıkış yapıldı: ${_cart.lines.length} kalem, ${_from!.name} → ${_to!.name}');
      _note.clear();
      await _loadStock();
    } on ApiException catch (e) {
      if (!mounted) return;
      showMessage(context, e.message, error: true);
      if (e.status == 422) _loadStock(); // stok başkası tarafından değişmiş olabilir
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Stok Çıkışı')),
      body: ListView(
        padding: const EdgeInsets.only(bottom: 16),
        children: [
          const StepTitle('1. Nereden nereye'),
          PickerField<Warehouse>(
            label: 'Çıkış yapılan depo',
            icon: Icons.warehouse_outlined,
            value: _from,
            items: _warehouses,
            itemLabel: (w) => w.label,
            onChanged: (w) {
              setState(() {
                _from = w;
                if (_to?.id == w.id) _to = null;
              });
              _loadStock();
            },
          ),
          PickerField<Warehouse>(
            label: 'Gideceği depo / araç',
            icon: Icons.local_shipping_outlined,
            value: _to,
            items: _warehouses.where((w) => w.id != _from?.id).toList(),
            itemLabel: (w) => w.label,
            itemSubtitle: (w) => w.type == 'field' ? 'Saha / araç' : null,
            enabled: _from != null,
            onChanged: (w) => setState(() => _to = w),
          ),
          StepTitle(
            '2. Ürünler',
            trailing: _cart.lines.isEmpty ? null : Text('${_cart.lines.length} kalem · toplam ${qty(_cart.totalUnits)}'),
          ),
          if (_from != null)
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 16),
              child: FilledButton.tonalIcon(
                onPressed: _loading || _cart.rows.isEmpty ? null : _scan,
                icon: const Icon(Icons.qr_code_scanner),
                label: const Text('Barkod okut'),
              ),
            ),
          if (_loading) const Padding(padding: EdgeInsets.all(16), child: LinearProgressIndicator()),
          if (_from != null && !_loading) StockList(cart: _cart, onChanged: () => setState(() {})),
          if (_from == null)
            Padding(
              padding: const EdgeInsets.all(16),
              child: Text('Önce çıkış yapılacak depoyu seçin.', style: TextStyle(color: Theme.of(context).hintColor)),
            ),
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
            child: TextField(controller: _note, decoration: const InputDecoration(labelText: 'Not (isteğe bağlı)')),
          ),
        ],
      ),
      bottomNavigationBar: SubmitBar(label: 'Çıkışı kaydet', saving: _saving, missing: _missing, onPressed: _submit),
    );
  }
}
