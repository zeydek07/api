import 'package:flutter/material.dart';

import '../core/gs1.dart';
import '../core/models.dart';
import 'common.dart';

/// Bir depodaki stok satırlarından miktar seçimi (çıkış ve teslimat ekranları).
/// Karekod okutulunca ürün + seri/lot eşleşen satıra 1 eklenir; lot okunmadıysa
/// SKT'si en yakın uygun satıra.
class StockCart {
  List<StockRow> rows = [];
  final Map<int, double> picked = {};

  /// true ise SKT'si geçmiş satır seçilemez (teslim ve çıkışta), geri almada seçilebilir
  bool blockExpired = true;

  double inCart(StockRow r) => picked[r.id] ?? 0;
  bool blocked(StockRow r) => blockExpired && r.expired;

  void set(StockRow r, double q) {
    if (blocked(r)) return;
    final v = q.clamp(0, r.quantity).toDouble();
    if (v == 0) {
      picked.remove(r.id);
    } else {
      picked[r.id] = v;
    }
  }

  void reset(List<StockRow> newRows) {
    rows = newRows;
    picked.clear();
  }

  List<(StockRow, double)> get lines =>
      [for (final r in rows) if (inCart(r) > 0) (r, inCart(r))];

  double get totalUnits => picked.values.fold(0, (s, v) => s + v);

  /// Okutulan karekodu işler; ekrana yazılacak mesajı ve hata olup olmadığını döndürür.
  (String, bool) scan(String raw, {required String notHere}) {
    final g = parseGs1(raw);
    if (g.isEmpty) return ('Karekod okunamadı.', true);

    final same = rows.where((r) => r.product.matches(g.gtin, g.plain)).toList();
    if (same.isEmpty) return (notHere, true);

    var candidates = same;
    if (g.serial != null) {
      candidates = candidates.where((r) => r.serial == g.serial).toList();
    } else if (g.lot != null) {
      candidates = candidates.where((r) => r.lot == g.lot && (g.expiry == null || r.expiry == g.expiry)).toList();
    }
    final usable = candidates.where((r) => !blocked(r) && inCart(r) < r.quantity).toList()
      ..sort((a, b) => (a.expiry ?? '9999').compareTo(b.expiry ?? '9999'));

    final name = same.first.product.name;
    if (usable.isEmpty) {
      if (g.serial != null) return ('$name SN ${g.serial} ${candidates.isEmpty ? 'burada yok.' : 'zaten eklendi.'}', true);
      if (candidates.any(blocked)) return ('$name${g.lot != null ? ' LOT ${g.lot}' : ''}: SKT\'si geçmiş, çıkış yapılamaz.', true);
      return ('$name${g.lot != null ? ' LOT ${g.lot}' : ''}: eklenebilecek başka ürün yok.', true);
    }
    final row = usable.first;
    set(row, inCart(row) + 1);
    return ('✓ $name${row.serial != null ? ' · SN ${row.serial}' : ''}${row.lot != null ? ' · LOT ${row.lot}' : ''} (${qty(inCart(row))}/${qty(row.quantity)})', false);
  }
}

/// Stok satırları listesi; aranabilir, her satırda miktar seçici.
class StockList extends StatefulWidget {
  const StockList({super.key, required this.cart, required this.onChanged, this.emptyText = 'Bu depoda stok yok.'});
  final StockCart cart;
  final VoidCallback onChanged;
  final String emptyText;

  @override
  State<StockList> createState() => _StockListState();
}

class _StockListState extends State<StockList> {
  String _q = '';

  @override
  Widget build(BuildContext context) {
    final cart = widget.cart;
    final q = trLower(_q);
    final rows = [...cart.rows]
      ..sort((a, b) => a.product.name.compareTo(b.product.name) != 0
          ? a.product.name.compareTo(b.product.name)
          : (a.expiry ?? '9999').compareTo(b.expiry ?? '9999'));
    final visible = q.isEmpty
        ? rows
        : rows.where((r) => trLower('${r.product.name} ${r.product.code ?? ''} ${r.lot ?? ''} ${r.serial ?? ''}').contains(q)).toList();

    if (cart.rows.isEmpty) {
      return Padding(padding: const EdgeInsets.all(16), child: Text(widget.emptyText, style: TextStyle(color: Theme.of(context).hintColor)));
    }
    return Column(
      children: [
        if (cart.rows.length > 5)
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
            child: TextField(
              decoration: const InputDecoration(prefixIcon: Icon(Icons.search), hintText: 'Ürün, lot veya seri ara', isDense: true),
              onChanged: (v) => setState(() => _q = v),
            ),
          ),
        for (final r in visible)
          Card(
            color: cart.inCart(r) > 0 ? Theme.of(context).colorScheme.primaryContainer.withValues(alpha: 0.5) : null,
            child: Padding(
              padding: const EdgeInsets.fromLTRB(16, 10, 8, 10),
              child: Row(
                children: [
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(r.product.name, style: const TextStyle(fontWeight: FontWeight.w600)),
                        const SizedBox(height: 2),
                        Text(
                          [if (r.detail.isNotEmpty) r.detail, 'Mevcut ${qty(r.quantity)} ${r.product.unit}'].join(' · '),
                          style: TextStyle(color: Theme.of(context).hintColor, fontSize: 13),
                        ),
                        if (r.expiry != null) Padding(padding: const EdgeInsets.only(top: 4), child: ExpiryChip(r)),
                      ],
                    ),
                  ),
                  if (cart.blocked(r))
                    const Padding(padding: EdgeInsets.all(8), child: Icon(Icons.block, color: Colors.red))
                  else
                    QtyStepper(
                      value: cart.inCart(r),
                      max: r.quantity,
                      onChanged: (v) {
                        cart.set(r, v);
                        widget.onChanged();
                      },
                    ),
                ],
              ),
            ),
          ),
      ],
    );
  }
}
