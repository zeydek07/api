import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/models.dart';
import '../ui/case_widgets.dart';
import '../ui/common.dart';
import 'case_plan_page.dart';
import 'case_record_page.dart';

/// Vaka ayrıntısı. Planlıysa: hazırlık (hastanede / aracımda ne var) ve "Vakayı başlat".
/// Kaydedildiyse: ürünler, tutanak imzası, e-posta durumu.
class CaseDetailPage extends StatefulWidget {
  const CaseDetailPage({super.key, required this.caseId});
  final int caseId;

  @override
  State<CaseDetailPage> createState() => _CaseDetailPageState();
}

class _CaseDetailPageState extends State<CaseDetailPage> {
  final _api = Api.instance;
  SurgeryCase? _case;
  String? _error;

  // Hazırlık listeleri
  List<StockRow>? _hospitalRows;
  List<StockRow>? _vehicleRows;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await _api.get('cases/${widget.caseId}');
      if (!mounted) return;
      final c = SurgeryCase(Map<String, dynamic>.from(r));
      setState(() => (_case = c, _error = null));
      if (c.isPlanned) _loadPreparation(c);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    }
  }

  Future<void> _loadPreparation(SurgeryCase c) async {
    try {
      final h = await _api.get('hospitals/${c.hospital!['id']}/consignment');
      final vehicles = await _api.get('mobile/warehouses') as List;
      final rows = <StockRow>[];
      for (final v in vehicles) {
        final s = await _api.get('mobile/warehouses/${v['id']}/stock');
        rows.addAll((s['stocks'] as List).map((e) => StockRow(Map<String, dynamic>.from(e))));
      }
      if (!mounted) return;
      setState(() {
        _hospitalRows = (h['rows'] as List).map((e) => StockRow(Map<String, dynamic>.from(e))).toList();
        _vehicleRows = rows;
      });
    } catch (_) {
      // hazırlık listesi isteğe bağlı bilgi; hata ekranı bozmaz
    }
  }

  Future<void> _open(Widget page) async {
    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => page));
    _load();
  }

  Future<void> _cancel() async {
    final reason = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Vakayı iptal et'),
        content: TextField(
          controller: reason,
          autofocus: true,
          decoration: const InputDecoration(labelText: 'Neden? *', hintText: 'ör. Ameliyat ertelendi'),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Vazgeç')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('İptal et')),
        ],
      ),
    );
    if (ok != true || reason.text.trim().length < 3) return;
    try {
      final r = await _api.post('cases/${widget.caseId}/cancel', {'reason': reason.text.trim()});
      if (mounted) showMessage(context, r['message']);
      _load();
    } on ApiException catch (e) {
      if (mounted) showMessage(context, e.message, error: true);
    }
  }

  Future<void> _sendEmail() async {
    final c = _case!;
    final ctrl = TextEditingController(text: c.json['email_to'] ?? (c.json['contact'] as Map?)?['email'] ?? '');
    final to = await showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Tutanağı e-postayla gönder'),
        content: TextField(controller: ctrl, autofocus: true, keyboardType: TextInputType.emailAddress, decoration: const InputDecoration(labelText: 'E-posta')),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Vazgeç')),
          FilledButton(onPressed: () => Navigator.pop(ctx, ctrl.text.trim()), child: const Text('Gönder')),
        ],
      ),
    );
    if (to == null || to.isEmpty || !mounted) return;
    try {
      final r = await _api.post('cases/${c.id}/email', {'email': to});
      if (mounted) showMessage(context, r['message']);
    } on ApiException catch (e) {
      if (mounted) showMessage(context, e.message, error: true);
    }
    _load();
  }

  @override
  Widget build(BuildContext context) {
    final c = _case;
    return Scaffold(
      appBar: AppBar(
        title: Text(c?.number ?? 'Vaka'),
        actions: [
          if (c != null && c.isPlanned)
            PopupMenuButton<String>(
              onSelected: (v) => v == 'edit' ? _open(CasePlanPage(existing: c)) : _cancel(),
              itemBuilder: (_) => const [
                PopupMenuItem(value: 'edit', child: ListTile(leading: Icon(Icons.edit_outlined), title: Text('Planı düzenle'))),
                PopupMenuItem(value: 'cancel', child: ListTile(leading: Icon(Icons.block), title: Text('Vakayı iptal et'))),
              ],
            ),
        ],
      ),
      body: _error != null
          ? Center(child: Padding(padding: const EdgeInsets.all(24), child: Text(_error!)))
          : c == null
              ? const Center(child: CircularProgressIndicator())
              : RefreshIndicator(onRefresh: _load, child: _body(context, c)),
      bottomNavigationBar: c != null && c.isPlanned
          ? SafeArea(
              child: Padding(
                padding: const EdgeInsets.fromLTRB(16, 8, 16, 12),
                child: SizedBox(
                  height: 54,
                  child: FilledButton.icon(
                    onPressed: () => _open(CaseRecordPage(caseId: c.id)),
                    icon: const Icon(Icons.qr_code_scanner),
                    label: const Text('Vakayı başlat · ürünleri okut', style: TextStyle(fontSize: 16)),
                  ),
                ),
              ),
            )
          : null,
    );
  }

  Widget _body(BuildContext context, SurgeryCase c) {
    final theme = Theme.of(context);
    final muted = TextStyle(color: theme.hintColor);
    final j = c.json;
    final items = c.items;
    final billable = items.where((i) => i['status'] != 'unused').toList();
    final unused = items.where((i) => i['status'] == 'unused').toList();

    Widget info(IconData icon, String label, String? value) => value == null || value.isEmpty
        ? const SizedBox.shrink()
        : ListTile(dense: true, leading: Icon(icon), title: Text(value), subtitle: Text(label));

    return ListView(
      padding: const EdgeInsets.only(bottom: 24),
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 16, 16, 4),
          child: Row(
            children: [
              CaseStatusChip(c),
              const Spacer(),
              Text('${dayLabel(c.scheduledAt)} · ${hm(c.scheduledAt)}', style: theme.textTheme.titleMedium),
            ],
          ),
        ),
        if (c.isOverdue)
          Card(
            color: Colors.red.shade50,
            child: ListTile(
              leading: Icon(Icons.error_outline, color: Colors.red.shade700),
              title: const Text('Vakanın saati geçti, ürünleri henüz girilmedi.'),
              subtitle: const Text('Ürünler girilmeden vaka faturalanamaz.'),
            ),
          ),
        info(Icons.local_hospital_outlined, 'Hastane', c.hospitalName),
        info(Icons.place_outlined, 'Bölüm', c.locationPath),
        info(Icons.person_outline, 'Doktor', c.doctorName),
        info(Icons.healing_outlined, 'Ameliyat', c.procedure),
        info(Icons.badge_outlined, 'Hasta protokol no', c.protocolNo),
        info(Icons.notes, 'Not', j['note']),
        if (c.status == 'cancelled')
          Card(
            color: Colors.grey.shade100,
            child: ListTile(
              leading: const Icon(Icons.block),
              title: Text('İptal edildi: ${j['cancel_reason'] ?? ''}'),
              subtitle: Text((j['canceller'] as Map?)?['name'] ?? ''),
            ),
          ),

        // Planlı: hazırlık
        if (c.isPlanned) ...[
          const StepTitle('Hazırlık'),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16),
            child: Text('Ameliyattan önce gerekli ürünlerin hastanede ya da aracınızda olduğundan emin olun.', style: muted),
          ),
          _PrepTile(title: 'Bu hastanede', icon: Icons.local_hospital_outlined, rows: _hospitalRows, showPlace: true),
          _PrepTile(title: 'Aracımda', icon: Icons.local_shipping_outlined, rows: _vehicleRows),
        ],

        // Kaydedilmiş: ürünler ve tutanak
        if (!c.isPlanned && items.isNotEmpty) ...[
          StepTitle('Faturalanacak ürünler (${billable.length})'),
          for (final i in billable) _ItemTile(i),
          if (unused.isNotEmpty) ...[
            StepTitle('Kullanılmayanlar (${unused.length})'),
            for (final i in unused) _ItemTile(i),
          ],
          const StepTitle('Tutanak'),
          info(Icons.draw_outlined, 'Tutanağı imzalayan', [j['signer_name'], j['signer_title']].whereType<String>().join(' · ')),
          info(Icons.event_available, 'Kayıt saati', c.performedAt == null ? null : '${dayLabel(c.performedAt!)} ${hm(c.performedAt!)}'),
          if (j['approver'] != null) info(Icons.verified_outlined, 'Onaylayan', (j['approver'] as Map)['name']),
          Card(
            child: ListTile(
              leading: Icon(
                j['email_status'] == 'sent' ? Icons.mark_email_read_outlined : Icons.mail_outline,
                color: j['email_status'] == 'sent' ? theme.colorScheme.primary : j['email_status'] == 'failed' ? theme.colorScheme.error : null,
              ),
              title: Text(switch (j['email_status']) {
                'sent' => 'Tutanak ${j['email_to']} adresine gönderildi',
                'failed' => 'E-posta gönderilemedi',
                _ => 'Tutanak e-postayla gönderilmedi',
              }),
              subtitle: j['email_status'] == 'failed' ? Text(j['email_error'] ?? '') : null,
              trailing: c.status == 'cancelled' ? null : TextButton(onPressed: _sendEmail, child: const Text('Gönder')),
            ),
          ),
          if (c.status == 'recorded')
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
              child: Text('Vaka yönetici onayı bekliyor. Onaylanınca faturalanacaklara düşer.', style: muted),
            ),
        ],
      ],
    );
  }
}

/// Hazırlık listesi: açılır, ürün adı · lot · adet · SKT
class _PrepTile extends StatelessWidget {
  const _PrepTile({required this.title, required this.icon, required this.rows, this.showPlace = false});
  final String title;
  final IconData icon;
  final List<StockRow>? rows;
  final bool showPlace;

  @override
  Widget build(BuildContext context) {
    final list = rows;
    final total = list?.fold<double>(0, (s, r) => s + r.quantity) ?? 0;
    return Card(
      child: ExpansionTile(
        leading: Icon(icon),
        title: Text(title, style: const TextStyle(fontWeight: FontWeight.w600)),
        subtitle: Text(list == null ? 'Yükleniyor…' : list.isEmpty ? 'Ürün yok' : '${list.length} kalem · toplam ${qty(total)}'),
        children: [
          for (final r in list ?? <StockRow>[])
            ListTile(
              dense: true,
              title: Text(r.product.name),
              subtitle: Text([if (r.detail.isNotEmpty) r.detail, if (showPlace && r.warehouseName != null) r.warehouseName!].join(' · ')),
              trailing: Column(
                mainAxisAlignment: MainAxisAlignment.center,
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  Text(qty(r.quantity), style: const TextStyle(fontWeight: FontWeight.w700)),
                  if (r.expired || r.expiringSoon) ExpiryChip(r),
                ],
              ),
            ),
        ],
      ),
    );
  }
}

class _ItemTile extends StatelessWidget {
  const _ItemTile(this.i);
  final Map<String, dynamic> i;

  @override
  Widget build(BuildContext context) {
    final p = Map<String, dynamic>.from(i['product'] ?? {});
    final w = Map<String, dynamic>.from(i['warehouse'] ?? {});
    final fromHospital = w['type'] == 'consignment';
    final status = i['status'] as String;
    final (Color bg, Color fg) = switch (status) {
      'used' => (Colors.green.shade50, Colors.green.shade800),
      'opened' => (Colors.amber.shade50, Colors.amber.shade900),
      _ => (Colors.grey.shade200, Colors.grey.shade700),
    };
    return Card(
      child: ListTile(
        title: Text(p['name'] ?? '', style: const TextStyle(fontWeight: FontWeight.w600)),
        subtitle: Text([
          if (i['serial_number'] != null) 'SN ${i['serial_number']}',
          if (i['lot_number'] != null) 'LOT ${i['lot_number']}',
          fromHospital ? 'Dolap: ${w['path'] ?? w['name']}' : 'Araç',
          if (i['invoice_number'] != null) 'Fatura ${i['invoice_number']}',
          if (i['expired_at_use'] == true) 'SKT geçmiş',
        ].join(' · ')),
        trailing: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          crossAxisAlignment: CrossAxisAlignment.end,
          children: [
            Text(qty(i['quantity']), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
            Container(
              margin: const EdgeInsets.only(top: 2),
              padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 1),
              decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(10)),
              child: Text(itemStatusLabels[status] ?? status, style: TextStyle(color: fg, fontSize: 11, fontWeight: FontWeight.w600)),
            ),
          ],
        ),
      ),
    );
  }
}
