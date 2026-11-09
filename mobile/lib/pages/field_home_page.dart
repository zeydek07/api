import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/models.dart';
import '../core/session.dart';
import '../ui/case_widgets.dart';
import '../ui/common.dart';
import 'case_detail_page.dart';
import 'case_plan_page.dart';
import 'case_record_page.dart';
import 'delivery_page.dart';
import 'lookup_page.dart';
import 'shell_page.dart';
import 'stock_alert_page.dart';

/// Saha ana sayfası: "Bugün ne yapmalıyım?"
/// Bugünkü vakalar · Bekleyen işler · Stok uyarıları · Hızlı işlemler.
class FieldHomePage extends StatefulWidget {
  const FieldHomePage({super.key, required this.refresh, required this.active});
  final ValueNotifier<int> refresh;
  final bool Function() active;

  @override
  State<FieldHomePage> createState() => _FieldHomePageState();
}

class _FieldHomePageState extends State<FieldHomePage> {
  final _api = Api.instance;
  Map<String, dynamic>? _data;
  String? _error;
  final _tasksKey = GlobalKey();
  final _alertsKey = GlobalKey();

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
      final r = await _api.get('mobile/home');
      if (mounted) setState(() => (_data = Map<String, dynamic>.from(r), _error = null));
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    }
  }

  /// Alt sayfaya git, dönünce yenile
  Future<void> _open(Widget page) async {
    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => page));
    _load();
  }

  void _scrollTo(GlobalKey key) {
    final ctx = key.currentContext;
    if (ctx != null) Scrollable.ensureVisible(ctx, duration: const Duration(milliseconds: 300), alignment: 0.05);
  }

  String get _greeting {
    final h = trNow().hour;
    final name = Session.instance.user!.name.split(' ').first;
    return '${h < 12 ? 'Günaydın' : h < 18 ? 'İyi günler' : 'İyi akşamlar'}, $name';
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final d = _data;
    final counts = Map<String, dynamic>.from(d?['counts'] ?? {});
    final today = [for (final c in (d?['today'] as List? ?? [])) SurgeryCase(Map<String, dynamic>.from(c))];
    final overdue = [for (final c in (d?['overdue'] as List? ?? [])) SurgeryCase(Map<String, dynamic>.from(c))];
    final failed = [for (final f in (d?['failed_emails'] as List? ?? [])) Map<String, dynamic>.from(f)];
    final alerts = [for (final a in (d?['alerts'] as List? ?? [])) Map<String, dynamic>.from(a)];

    return Scaffold(
      body: SafeArea(
        child: RefreshIndicator(
          onRefresh: _load,
          child: ListView(
            padding: const EdgeInsets.only(bottom: 24),
            children: [
              // Karşılama
              Padding(
                padding: const EdgeInsets.fromLTRB(20, 16, 20, 4),
                child: Text(_greeting, style: theme.textTheme.headlineSmall?.copyWith(fontWeight: FontWeight.w600)),
              ),
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 20),
                child: Text(longDate(trNow()), style: TextStyle(color: theme.hintColor)),
              ),
              if (_error != null)
                Padding(padding: const EdgeInsets.all(20), child: Text(_error!, style: TextStyle(color: theme.colorScheme.error))),
              if (d == null && _error == null) const Padding(padding: EdgeInsets.all(40), child: Center(child: CircularProgressIndicator())),
              if (d != null) ...[
                // Özet sayaçlar
                Padding(
                  padding: const EdgeInsets.fromLTRB(16, 16, 16, 0),
                  child: Row(
                    children: [
                      _Counter(value: counts['today'] ?? 0, label: 'Bugün vaka', icon: Icons.medical_services_outlined, color: theme.colorScheme.primary,
                          onTap: () => ShellPage.goTo(context, 1)),
                      const SizedBox(width: 8),
                      _Counter(value: counts['tasks'] ?? 0, label: 'Bekleyen iş', icon: Icons.pending_actions_outlined,
                          color: (counts['tasks'] ?? 0) > 0 ? Colors.red.shade700 : theme.hintColor, onTap: () => _scrollTo(_tasksKey)),
                      const SizedBox(width: 8),
                      _Counter(value: counts['alerts'] ?? 0, label: 'Stok uyarısı', icon: Icons.inventory_2_outlined,
                          color: (counts['alerts'] ?? 0) > 0 ? Colors.orange.shade800 : theme.hintColor, onTap: () => _scrollTo(_alertsKey)),
                    ],
                  ),
                ),

                // Bugünkü vakalar
                _Section(
                  title: 'Bugünkü vakalar',
                  icon: Icons.medical_services_outlined,
                  trailing: IconButton.filledTonal(
                    tooltip: 'Vaka planla',
                    onPressed: () => _open(const CasePlanPage()),
                    icon: const Icon(Icons.add),
                  ),
                ),
                if (today.isEmpty)
                  _Empty(
                    'Bugün planlı vaka yok.',
                    action: (counts['upcoming'] ?? 0) > 0 ? '${counts['upcoming']} yaklaşan vaka' : null,
                    onAction: () => ShellPage.goTo(context, 1),
                  ),
                for (final c in today)
                  CaseCard(
                    c,
                    onTap: () => _open(CaseDetailPage(caseId: c.id)),
                    action: c.isPlanned
                        ? SizedBox(
                            width: double.infinity,
                            child: FilledButton.icon(
                              onPressed: () => _open(CaseRecordPage(caseId: c.id)),
                              icon: const Icon(Icons.qr_code_scanner),
                              label: const Text('Vakayı başlat'),
                            ),
                          )
                        : null,
                  ),

                // Bekleyen işler
                _Section(key: _tasksKey, title: 'Bekleyen işler', icon: Icons.pending_actions_outlined, count: overdue.length + failed.length),
                if (overdue.isEmpty && failed.isEmpty) const _Empty('Bekleyen iş yok 👍'),
                for (final c in overdue)
                  _TaskTile(
                    icon: Icons.error_outline,
                    color: Colors.red.shade700,
                    title: '${c.hospitalName} vakasının ürünleri girilmedi',
                    subtitle: '${c.number} · ${dayLabel(c.scheduledAt)} ${hm(c.scheduledAt)}${c.procedure == null ? '' : ' · ${c.procedure}'}',
                    onTap: () => _open(CaseRecordPage(caseId: c.id)),
                  ),
                for (final f in failed)
                  _TaskTile(
                    icon: Icons.mail_outline,
                    color: Colors.orange.shade800,
                    title: '${f['number']} e-postası gönderilemedi',
                    subtitle: f['email_to'] ?? '',
                    onTap: () => f['type'] == 'case' ? _open(CaseDetailPage(caseId: f['id'])) : _resendDelivery(f),
                  ),

                // Stok uyarıları (yalnızca varsa)
                if (alerts.isNotEmpty) ...[
                  _Section(key: _alertsKey, title: 'Stok uyarıları', icon: Icons.inventory_2_outlined, count: alerts.length),
                  for (final a in alerts) _AlertTile(a, onTap: () => _open(StockAlertPage(alert: a))),
                ] else
                  SizedBox(key: _alertsKey),

                // Hızlı işlemler
                const _Section(title: 'Hızlı işlemler', icon: Icons.bolt_outlined),
                Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 12),
                  child: GridView.count(
                    crossAxisCount: 2,
                    shrinkWrap: true,
                    physics: const NeverScrollableScrollPhysics(),
                    childAspectRatio: 2.6,
                    children: [
                      _Quick(Icons.add_circle_outline, 'Vaka planla', () => _open(const CasePlanPage())),
                      if (Session.instance.can('deliveries.create')) ...[
                        _Quick(Icons.move_to_inbox_outlined, 'Teslim et', () => _open(const DeliveryPage())),
                        _Quick(Icons.undo, 'Geri al', () => _open(const DeliveryPage(initialReturn: true))),
                      ],
                      _Quick(Icons.qr_code_scanner, 'Ürün sorgula', () => _open(const LookupPage())),
                    ],
                  ),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }

  /// Teslimat belgesinin e-postasını yeniden gönder
  Future<void> _resendDelivery(Map<String, dynamic> f) async {
    final ctrl = TextEditingController(text: f['email_to'] ?? '');
    final to = await showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text('${f['number']} yeniden gönder'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            if (f['email_error'] != null)
              Padding(padding: const EdgeInsets.only(bottom: 12), child: Text('Hata: ${f['email_error']}', style: const TextStyle(fontSize: 13))),
            TextField(controller: ctrl, keyboardType: TextInputType.emailAddress, decoration: const InputDecoration(labelText: 'E-posta')),
          ],
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Vazgeç')),
          FilledButton(onPressed: () => Navigator.pop(ctx, ctrl.text.trim()), child: const Text('Gönder')),
        ],
      ),
    );
    if (to == null || to.isEmpty || !mounted) return;
    try {
      final r = await _api.post('deliveries/${f['id']}/email', {'email': to});
      if (mounted) showMessage(context, r['message']);
    } on ApiException catch (e) {
      if (mounted) showMessage(context, e.message, error: true);
    }
    _load();
  }
}

class _Counter extends StatelessWidget {
  const _Counter({required this.value, required this.label, required this.icon, required this.color, required this.onTap});
  final int value;
  final String label;
  final IconData icon;
  final Color color;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Expanded(
      child: Card(
        margin: EdgeInsets.zero,
        child: InkWell(
          borderRadius: BorderRadius.circular(12),
          onTap: onTap,
          child: Padding(
            padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 10),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Icon(icon, size: 18, color: color),
                    const SizedBox(width: 6),
                    Text('$value', style: TextStyle(fontSize: 22, fontWeight: FontWeight.w700, color: color)),
                  ],
                ),
                const SizedBox(height: 2),
                Text(label, style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor)),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _Section extends StatelessWidget {
  const _Section({super.key, required this.title, required this.icon, this.trailing, this.count});
  final String title;
  final IconData icon;
  final Widget? trailing;
  final int? count;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(20, 22, 12, 6),
      child: Row(
        children: [
          Icon(icon, size: 20, color: Theme.of(context).colorScheme.primary),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              count == null || count == 0 ? title : '$title ($count)',
              style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w600),
            ),
          ),
          ?trailing,
        ],
      ),
    );
  }
}

class _Empty extends StatelessWidget {
  const _Empty(this.text, {this.action, this.onAction});
  final String text;
  final String? action;
  final VoidCallback? onAction;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 4),
      child: Row(
        children: [
          Expanded(child: Text(text, style: TextStyle(color: Theme.of(context).hintColor))),
          if (action != null) TextButton(onPressed: onAction, child: Text(action!)),
        ],
      ),
    );
  }
}

class _TaskTile extends StatelessWidget {
  const _TaskTile({required this.icon, required this.color, required this.title, required this.subtitle, required this.onTap});
  final IconData icon;
  final Color color;
  final String title;
  final String subtitle;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: ListTile(
        leading: Icon(icon, color: color),
        title: Text(title, style: const TextStyle(fontWeight: FontWeight.w600)),
        subtitle: Text(subtitle),
        trailing: const Icon(Icons.chevron_right),
        onTap: onTap,
      ),
    );
  }
}

class _AlertTile extends StatelessWidget {
  const _AlertTile(this.a, {required this.onTap});
  final Map<String, dynamic> a;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final (Color color, String what) = switch (a['level']) {
      'expired' => (Colors.red.shade700, 'SKT geçti'),
      'critical' => (Colors.orange.shade800, 'SKT 30 gün içinde'),
      _ => (Colors.amber.shade800, 'SKT 90 gün içinde'),
    };
    final vehicle = a['scope'] == 'vehicle';
    return Card(
      child: ListTile(
        leading: Icon(vehicle ? Icons.local_shipping_outlined : Icons.local_hospital_outlined, color: color),
        title: Text(a['title'] ?? '', style: const TextStyle(fontWeight: FontWeight.w600)),
        subtitle: Text('$what · ${a['lines']} kalem, toplam ${qty(a['units'])}', style: TextStyle(color: color)),
        trailing: const Icon(Icons.chevron_right),
        onTap: onTap,
      ),
    );
  }
}

class _Quick extends StatelessWidget {
  const _Quick(this.icon, this.label, this.onTap);
  final IconData icon;
  final String label;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Card(
      margin: const EdgeInsets.all(4),
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 12),
          child: Row(
            children: [
              Icon(icon, color: Theme.of(context).colorScheme.primary),
              const SizedBox(width: 10),
              Expanded(child: Text(label, style: const TextStyle(fontWeight: FontWeight.w600))),
            ],
          ),
        ),
      ),
    );
  }
}
