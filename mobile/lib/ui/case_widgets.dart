import 'package:flutter/material.dart';

import '../core/models.dart';

/// Vaka durumu rozeti; kaydı geciken planlı vaka kırmızı
class CaseStatusChip extends StatelessWidget {
  const CaseStatusChip(this.c, {super.key});
  final SurgeryCase c;

  @override
  Widget build(BuildContext context) {
    final (Color bg, Color fg, IconData icon) = c.isOverdue
        ? (Colors.red.shade50, Colors.red.shade800, Icons.error_outline)
        : switch (c.status) {
            'recorded' => (Colors.amber.shade50, Colors.amber.shade900, Icons.hourglass_top),
            'approved' => (Colors.green.shade50, Colors.green.shade800, Icons.check_circle_outline),
            'cancelled' => (Colors.grey.shade200, Colors.grey.shade700, Icons.block),
            _ => (Colors.blue.shade50, Colors.blue.shade800, Icons.schedule),
          };
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(20)),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, size: 14, color: fg),
          const SizedBox(width: 4),
          Text(c.statusLabel, style: TextStyle(color: fg, fontSize: 12, fontWeight: FontWeight.w600)),
        ],
      ),
    );
  }
}

/// Plansız (acil) kaydedilen vaka rozeti
class UnplannedChip extends StatelessWidget {
  const UnplannedChip({super.key});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(color: Colors.orange.shade50, borderRadius: BorderRadius.circular(20)),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(Icons.bolt, size: 14, color: Colors.orange.shade900),
          const SizedBox(width: 2),
          Text('Plansız', style: TextStyle(color: Colors.orange.shade900, fontSize: 12, fontWeight: FontWeight.w600)),
        ],
      ),
    );
  }
}

/// Vaka kartı: saat, hastane, bölüm, doktor, ameliyat, durum ve isteğe bağlı eylem düğmesi
class CaseCard extends StatelessWidget {
  const CaseCard(this.c, {super.key, required this.onTap, this.action, this.showDate = false});
  final SurgeryCase c;
  final VoidCallback onTap;
  final Widget? action;
  final bool showDate;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final t = c.scheduledAt;
    final muted = TextStyle(color: theme.hintColor, fontSize: 13);
    return Card(
      clipBehavior: Clip.antiAlias,
      shape: c.isOverdue
          ? RoundedRectangleBorder(borderRadius: BorderRadius.circular(12), side: BorderSide(color: Colors.red.shade300))
          : null,
      child: InkWell(
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(14, 12, 14, 12),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              SizedBox(
                width: 56,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(hm(t), style: theme.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700)),
                    if (showDate) Text('${t.day}.${two(t.month)}', style: muted),
                  ],
                ),
              ),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(c.hospitalName, style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 15)),
                    if (c.locationPath != null || c.doctorName != null)
                      Padding(
                        padding: const EdgeInsets.only(top: 2),
                        child: Text([c.locationPath, c.doctorName].whereType<String>().join(' · '), style: muted),
                      ),
                    if (c.procedure != null) Padding(padding: const EdgeInsets.only(top: 2), child: Text(c.procedure!)),
                    const SizedBox(height: 6),
                    Row(
                      children: [
                        CaseStatusChip(c),
                        if (c.isUnplanned) const Padding(padding: EdgeInsets.only(left: 6), child: UnplannedChip()),
                        const SizedBox(width: 8),
                        Flexible(child: Text(c.number, style: muted, overflow: TextOverflow.ellipsis)),
                      ],
                    ),
                    if (action != null) Padding(padding: const EdgeInsets.only(top: 10), child: action),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
