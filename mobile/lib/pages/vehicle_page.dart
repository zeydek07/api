import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/models.dart';
import '../ui/common.dart';

/// Aracım / zimmetim: araçtaki (çantadaki) ürünler; SKT'ye göre süzülebilir
class VehiclePage extends StatefulWidget {
  const VehiclePage({super.key, required this.refresh, required this.active});
  final ValueNotifier<int> refresh;
  final bool Function() active;

  @override
  State<VehiclePage> createState() => _VehiclePageState();
}

class _VehiclePageState extends State<VehiclePage> {
  final _api = Api.instance;
  List<Warehouse> _vehicles = [];
  Warehouse? _vehicle;
  List<StockRow>? _rows;
  String _filter = 'all';
  String _q = '';
  String? _error;

  @override
  void initState() {
    super.initState();
    widget.refresh.addListener(_onTab);
    _load();
  }

  @override
  void dispose() {
    widget.refresh.removeListener(_onTab);
    super.dispose();
  }

  void _onTab() {
    if (widget.active()) _load();
  }

  Future<void> _load() async {
    try {
      final list = (await _api.get('mobile/warehouses') as List).map((e) => Warehouse(Map<String, dynamic>.from(e))).toList();
      final v = list.where((x) => x.id == _vehicle?.id).firstOrNull ?? list.firstOrNull;
      if (!mounted) return;
      setState(() => (_vehicles = list, _vehicle = v, _error = null));
      if (v == null) return setState(() => _rows = []);
      final s = await _api.get('mobile/warehouses/${v.id}/stock');
      if (mounted) setState(() => _rows = (s['stocks'] as List).map((e) => StockRow(Map<String, dynamic>.from(e))).toList());
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    }
  }

  @override
  Widget build(BuildContext context) {
    final rows = _rows ?? [];
    final expired = rows.where((r) => r.expired).toList();
    final soon = rows.where((r) => r.expiringSoon).toList();
    final q = trLower(_q);
    final visible = (switch (_filter) { 'soon' => soon, 'expired' => expired, _ => rows })
        .where((r) => q.isEmpty || trLower('${r.product.name} ${r.product.code ?? ''} ${r.lot ?? ''} ${r.serial ?? ''}').contains(q))
        .toList();
    final total = rows.fold<double>(0, (s, r) => s + r.quantity);

    return Scaffold(
      appBar: AppBar(title: Text(_vehicle?.name ?? 'Aracım')),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          padding: const EdgeInsets.only(bottom: 24),
          children: [
            if (_error != null) Padding(padding: const EdgeInsets.all(16), child: Text(_error!)),
            if (_vehicles.length > 1)
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
                child: Wrap(
                  spacing: 8,
                  children: [
                    for (final v in _vehicles)
                      ChoiceChip(
                        label: Text(v.name),
                        selected: v.id == _vehicle?.id,
                        onSelected: (_) {
                          setState(() => (_vehicle = v, _rows = null));
                          _load();
                        },
                      ),
                  ],
                ),
              ),
            if (_rows == null && _error == null) const Padding(padding: EdgeInsets.all(40), child: Center(child: CircularProgressIndicator())),
            if (_rows != null) ...[
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 12, 16, 4),
                child: Text('${rows.length} kalem · toplam ${qty(total)}', style: Theme.of(context).textTheme.titleMedium),
              ),
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 16),
                child: Wrap(
                  spacing: 8,
                  children: [
                    ChoiceChip(label: Text('Tümü (${rows.length})'), selected: _filter == 'all', onSelected: (_) => setState(() => _filter = 'all')),
                    ChoiceChip(label: Text('SKT yakın (${soon.length})'), selected: _filter == 'soon', onSelected: (_) => setState(() => _filter = 'soon')),
                    ChoiceChip(label: Text('SKT geçti (${expired.length})'), selected: _filter == 'expired', onSelected: (_) => setState(() => _filter = 'expired')),
                  ],
                ),
              ),
              if (rows.length > 6)
                Padding(
                  padding: const EdgeInsets.fromLTRB(16, 8, 16, 4),
                  child: TextField(
                    decoration: const InputDecoration(prefixIcon: Icon(Icons.search), hintText: 'Ürün, lot veya seri ara', isDense: true),
                    onChanged: (v) => setState(() => _q = v),
                  ),
                ),
              if (visible.isEmpty)
                Padding(padding: const EdgeInsets.all(24), child: Text(rows.isEmpty ? 'Aracınızda ürün yok.' : 'Bu süzgece uyan ürün yok.')),
              for (final r in visible) StockRowTile(r),
            ],
          ],
        ),
      ),
    );
  }
}

/// Salt okunur stok satırı: ürün, lot / seri, yer, adet, SKT rozeti
class StockRowTile extends StatelessWidget {
  const StockRowTile(this.r, {super.key, this.showPlace = false});
  final StockRow r;
  final bool showPlace;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: ListTile(
        title: Text(r.product.name, style: const TextStyle(fontWeight: FontWeight.w600)),
        subtitle: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text([if (r.detail.isNotEmpty) r.detail, if (showPlace && r.warehouseName != null) r.warehouseName!].join(' · ')),
            if (r.expiry != null) Padding(padding: const EdgeInsets.only(top: 4), child: ExpiryChip(r)),
          ],
        ),
        trailing: Text('${qty(r.quantity)} ${r.product.unit}', style: const TextStyle(fontWeight: FontWeight.w700)),
      ),
    );
  }
}
