import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/models.dart';
import '../ui/case_widgets.dart';
import 'case_detail_page.dart';
import 'case_plan_page.dart';
import 'case_record_page.dart';

/// Vakalarım: Bugün (ve kaydı gecikenler) · Yaklaşan · Geçmiş
class CasesPage extends StatefulWidget {
  const CasesPage({super.key, required this.refresh, required this.active});
  final ValueNotifier<int> refresh;
  final bool Function() active;

  @override
  State<CasesPage> createState() => _CasesPageState();
}

class _CasesPageState extends State<CasesPage> with SingleTickerProviderStateMixin {
  final _api = Api.instance;
  late final _tabs = TabController(length: 3, vsync: this);
  final Map<String, List<SurgeryCase>?> _lists = {'today': null, 'overdue': null, 'upcoming': null, 'past': null};
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
    _tabs.dispose();
    super.dispose();
  }

  void _onTab() {
    if (widget.active()) _load();
  }

  Future<void> _load() async {
    try {
      final results = await Future.wait([
        for (final scope in _lists.keys) _api.get('cases', {'scope': scope, 'mine': 1, 'per_page': 100}),
      ]);
      if (!mounted) return;
      setState(() {
        _error = null;
        var i = 0;
        for (final scope in _lists.keys) {
          _lists[scope] = [for (final c in (results[i++]['data'] as List)) SurgeryCase(Map<String, dynamic>.from(c))];
        }
      });
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    }
  }

  Future<void> _open(Widget page) async {
    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => page));
    _load();
  }

  Widget _card(SurgeryCase c, {bool showDate = false}) => CaseCard(
        c,
        showDate: showDate,
        onTap: () => _open(CaseDetailPage(caseId: c.id)),
        action: c.isPlanned && (c.isOverdue || dayLabel(c.scheduledAt) == 'Bugün')
            ? SizedBox(
                width: double.infinity,
                child: FilledButton.icon(
                  onPressed: () => _open(CaseRecordPage(caseId: c.id)),
                  icon: const Icon(Icons.qr_code_scanner),
                  label: const Text('Vakayı başlat'),
                ),
              )
            : null,
      );

  @override
  Widget build(BuildContext context) {
    final today = _lists['today'];
    // Bugünden önceki, kaydı yapılmamış vakalar (bugünküler zaten listede)
    final overdue = (_lists['overdue'] ?? []).where((c) => dayLabel(c.scheduledAt) != 'Bugün').toList();
    final hint = TextStyle(color: Theme.of(context).hintColor);

    Widget list(List<Widget> children, String empty) => RefreshIndicator(
          onRefresh: _load,
          child: ListView(
            padding: const EdgeInsets.only(top: 8, bottom: 96),
            children: [
              if (_error != null) Padding(padding: const EdgeInsets.all(16), child: Text(_error!)),
              if (children.isEmpty && _error == null) Padding(padding: const EdgeInsets.all(24), child: Text(empty, style: hint)),
              ...children,
            ],
          ),
        );

    Widget grouped(List<SurgeryCase>? items, String empty) {
      if (items == null) return const Center(child: CircularProgressIndicator());
      final children = <Widget>[];
      String? last;
      for (final c in items) {
        final label = dayLabel(c.scheduledAt);
        if (label != last) {
          children.add(Padding(padding: const EdgeInsets.fromLTRB(20, 12, 20, 4), child: Text(label, style: const TextStyle(fontWeight: FontWeight.w600))));
          last = label;
        }
        children.add(_card(c));
      }
      return list(children, empty);
    }

    return Scaffold(
      appBar: AppBar(
        title: const Text('Vakalarım'),
        actions: [
          TextButton.icon(
            onPressed: () => _open(const CasePlanPage(emergency: true)),
            icon: Icon(Icons.bolt, color: Colors.orange.shade800),
            label: const Text('Acil vaka'),
          ),
        ],
        bottom: TabBar(
          controller: _tabs,
          tabs: [
            Tab(text: today == null ? 'Bugün' : 'Bugün (${today.length + overdue.length})'),
            Tab(text: _lists['upcoming'] == null ? 'Yaklaşan' : 'Yaklaşan (${_lists['upcoming']!.length})'),
            const Tab(text: 'Geçmiş'),
          ],
        ),
      ),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () => _open(const CasePlanPage()),
        icon: const Icon(Icons.add),
        label: const Text('Vaka planla'),
      ),
      body: TabBarView(
        controller: _tabs,
        children: [
          today == null
              ? const Center(child: CircularProgressIndicator())
              : list([
                  if (overdue.isNotEmpty) ...[
                    Padding(
                      padding: const EdgeInsets.fromLTRB(20, 8, 20, 4),
                      child: Text('Kaydı bekleyen eski vakalar', style: TextStyle(fontWeight: FontWeight.w600, color: Colors.red.shade700)),
                    ),
                    for (final c in overdue) _card(c, showDate: true),
                    Padding(
                      padding: const EdgeInsets.fromLTRB(20, 12, 20, 4),
                      child: today.isEmpty
                          ? Text('Bugün planlı vaka yok.', style: hint)
                          : const Text('Bugün', style: TextStyle(fontWeight: FontWeight.w600)),
                    ),
                  ],
                  for (final c in today) _card(c),
                ], 'Bugün planlı vaka yok. Sağ alttan yeni vaka planlayabilirsiniz.'),
          grouped(_lists['upcoming'], 'Yaklaşan vaka yok.'),
          grouped(_lists['past'], 'Geçmiş vaka yok.'),
        ],
      ),
    );
  }
}
