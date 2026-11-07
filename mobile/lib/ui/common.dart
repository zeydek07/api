import 'package:flutter/material.dart';

import '../core/models.dart';

void showMessage(BuildContext context, String text, {bool error = false}) {
  ScaffoldMessenger.of(context)
    ..hideCurrentSnackBar()
    ..showSnackBar(SnackBar(
      content: Text(text),
      backgroundColor: error ? Theme.of(context).colorScheme.error : null,
    ));
}

/// Adım başlığı (1. Depo, 2. Ürünler ...)
class StepTitle extends StatelessWidget {
  const StepTitle(this.text, {super.key, this.trailing});
  final String text;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 20, 16, 8),
      child: Row(
        children: [
          Expanded(child: Text(text, style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w600))),
          ?trailing,
        ],
      ),
    );
  }
}

/// Seçim listesi açan alan (depo, hastane, lokasyon ...)
class PickerField<T> extends StatelessWidget {
  const PickerField({
    super.key,
    required this.label,
    required this.value,
    required this.items,
    required this.itemLabel,
    required this.onChanged,
    this.icon,
    this.enabled = true,
    this.itemSubtitle,
  });

  final String label;
  final T? value;
  final List<T> items;
  final String Function(T) itemLabel;
  final String? Function(T)? itemSubtitle;
  final ValueChanged<T> onChanged;
  final IconData? icon;
  final bool enabled;

  Future<void> _open(BuildContext context) async {
    final picked = await showModalBottomSheet<T>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (ctx) => _PickerSheet<T>(title: label, items: items, itemLabel: itemLabel, itemSubtitle: itemSubtitle),
    );
    if (picked != null) onChanged(picked);
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
      child: InkWell(
        onTap: enabled && items.isNotEmpty ? () => _open(context) : null,
        borderRadius: BorderRadius.circular(12),
        child: InputDecorator(
          decoration: InputDecoration(
            labelText: label,
            prefixIcon: icon == null ? null : Icon(icon),
            suffixIcon: const Icon(Icons.expand_more),
            enabled: enabled && items.isNotEmpty,
          ),
          child: Text(
            value == null ? (items.isEmpty ? 'Seçenek yok' : 'Seçin') : itemLabel(value as T),
            style: value == null ? TextStyle(color: Theme.of(context).hintColor) : null,
          ),
        ),
      ),
    );
  }
}

class _PickerSheet<T> extends StatefulWidget {
  const _PickerSheet({required this.title, required this.items, required this.itemLabel, this.itemSubtitle});
  final String title;
  final List<T> items;
  final String Function(T) itemLabel;
  final String? Function(T)? itemSubtitle;

  @override
  State<_PickerSheet<T>> createState() => _PickerSheetState<T>();
}

class _PickerSheetState<T> extends State<_PickerSheet<T>> {
  String _q = '';

  @override
  Widget build(BuildContext context) {
    final q = trLower(_q);
    final list = widget.items
        .where((i) => q.isEmpty || trLower('${widget.itemLabel(i)} ${widget.itemSubtitle?.call(i) ?? ''}').contains(q))
        .toList();
    return SizedBox(
      height: MediaQuery.of(context).size.height * 0.75,
      child: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
            child: Text(widget.title, style: Theme.of(context).textTheme.titleMedium),
          ),
          if (widget.items.length > 6)
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
              child: TextField(
                decoration: const InputDecoration(prefixIcon: Icon(Icons.search), hintText: 'Ara'),
                onChanged: (v) => setState(() => _q = v),
              ),
            ),
          Expanded(
            child: ListView.builder(
              itemCount: list.length,
              itemBuilder: (_, i) {
                final sub = widget.itemSubtitle?.call(list[i]);
                return ListTile(
                  title: Text(widget.itemLabel(list[i])),
                  subtitle: sub == null ? null : Text(sub),
                  onTap: () => Navigator.pop(context, list[i]),
                );
              },
            ),
          ),
        ],
      ),
    );
  }
}

/// SKT durumu rozeti
class ExpiryChip extends StatelessWidget {
  const ExpiryChip(this.row, {super.key});
  final StockRow row;

  @override
  Widget build(BuildContext context) {
    if (row.expiry == null) return const SizedBox.shrink();
    final (bg, fg, text) = row.expired
        ? (Colors.red.shade50, Colors.red.shade800, 'SKT geçti')
        : row.expiringSoon
            ? (Colors.orange.shade50, Colors.orange.shade900, 'SKT yakın')
            : (Colors.green.shade50, Colors.green.shade800, 'SKT ${dateTr(row.expiry)}');
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
      decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(20)),
      child: Text(text, style: TextStyle(color: fg, fontSize: 12, fontWeight: FontWeight.w600)),
    );
  }
}

/// Miktar seçici: − 3 +
class QtyStepper extends StatelessWidget {
  const QtyStepper({super.key, required this.value, required this.max, required this.onChanged});
  final double value;
  final double max;
  final ValueChanged<double> onChanged;

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        IconButton.outlined(
          visualDensity: VisualDensity.compact,
          onPressed: value > 0 ? () => onChanged(value - 1 < 0 ? 0 : value - 1) : null,
          icon: const Icon(Icons.remove),
        ),
        SizedBox(
          width: 40,
          child: Text(qty(value), textAlign: TextAlign.center, style: const TextStyle(fontWeight: FontWeight.w600)),
        ),
        IconButton.outlined(
          visualDensity: VisualDensity.compact,
          onPressed: value < max ? () => onChanged(value + 1 > max ? max : value + 1) : null,
          icon: const Icon(Icons.add),
        ),
      ],
    );
  }
}

/// Altta sabit kaydet düğmesi + eksik adım açıklaması
class SubmitBar extends StatelessWidget {
  const SubmitBar({super.key, required this.label, required this.onPressed, required this.saving, this.missing});
  final String label;
  final VoidCallback? onPressed;
  final bool saving;
  final String? missing;

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 8, 16, 12),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            if (missing != null && missing!.isNotEmpty)
              Padding(
                padding: const EdgeInsets.only(bottom: 6),
                child: Text(missing!, style: TextStyle(color: Theme.of(context).hintColor)),
              ),
            SizedBox(
              width: double.infinity,
              height: 52,
              child: FilledButton(
                onPressed: saving || (missing?.isNotEmpty ?? false) ? null : onPressed,
                child: saving
                    ? const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2.5))
                    : Text(label, style: const TextStyle(fontSize: 16)),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
