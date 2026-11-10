import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/models.dart';
import '../ui/common.dart';
import 'case_detail_page.dart';
import 'case_record_page.dart';

/// Vaka planla / planı düzenle: hastane, bölüm, doktor, tarih-saat, ameliyat türü.
/// [emergency]: plansız (acil) vaka — tarih sorulmaz, seçimden sonra doğrudan ürün kaydına geçilir.
class CasePlanPage extends StatefulWidget {
  const CasePlanPage({super.key, this.existing, this.emergency = false});

  /// Düzenlenecek planlı vaka (yoksa yeni)
  final SurgeryCase? existing;
  final bool emergency;

  @override
  State<CasePlanPage> createState() => _CasePlanPageState();
}

class _CasePlanPageState extends State<CasePlanPage> {
  final _api = Api.instance;
  static const _procedures = ['Diz protezi', 'Kalça protezi', 'Artroskopi', 'Kırık fiksasyonu', 'Omurga cerrahisi', 'Omuz cerrahisi'];

  List<Map<String, dynamic>> _hospitals = [];
  Map<String, dynamic>? _hospital;
  List<Warehouse> _locations = [];
  Warehouse? _location;
  List<Map<String, dynamic>> _doctors = [];
  Map<String, dynamic>? _doctor;
  late DateTime _date;
  late TimeOfDay _time;
  final _procedure = TextEditingController();
  final _protocol = TextEditingController();
  final _patient = TextEditingController();
  final _note = TextEditingController();
  bool _saving = false;

  bool get _editing => widget.existing != null;

  @override
  void initState() {
    super.initState();
    final e = widget.existing;
    final start = e?.scheduledAt ?? trNow().add(const Duration(hours: 1));
    _date = DateTime(start.year, start.month, start.day);
    _time = TimeOfDay(hour: start.hour, minute: e == null ? 0 : start.minute);
    _procedure.text = e?.procedure ?? '';
    _protocol.text = e?.protocolNo ?? '';
    _patient.text = e?.patientName ?? '';
    _note.text = e?.json['note'] ?? '';

    _api.get('hospitals', {'is_active': 1, 'per_page': 100}).then((r) async {
      final list = List<Map<String, dynamic>>.from(r['data']);
      if (!mounted) return;
      setState(() => _hospitals = list);
      if (e != null) {
        final h = list.where((x) => x['id'] == e.hospital?['id']).firstOrNull ?? e.hospital;
        if (h != null) await _selectHospital(h, keepLocation: e.location?['id'], keepDoctor: e.doctor?['id']);
      }
    }).catchError((Object err) {
      if (mounted) showMessage(context, '$err', error: true);
    });
  }

  Future<void> _selectHospital(Map<String, dynamic> h, {int? keepLocation, int? keepDoctor}) async {
    setState(() {
      _hospital = h;
      _location = null;
      _locations = [];
      _doctor = null;
      _doctors = [];
    });
    try {
      final locs = await _api.get('hospitals/${h['id']}/locations');
      await _loadDoctors(select: keepDoctor);
      if (!mounted) return;
      setState(() {
        _locations = (locs as List).map((e) => Warehouse(Map<String, dynamic>.from(e))).where((w) => w.isActive).toList();
        _location = _locations.where((w) => w.id == keepLocation).firstOrNull;
      });
    } catch (e) {
      if (mounted) showMessage(context, '$e', error: true);
    }
  }

  Future<void> _loadDoctors({int? select}) async {
    final d = await _api.get('hospitals/${_hospital!['id']}/doctors');
    if (!mounted) return;
    setState(() {
      _doctors = List<Map<String, dynamic>>.from(d).where((x) => x['is_active'] != false).toList();
      _doctor = _doctors.where((x) => x['id'] == select).firstOrNull;
    });
  }

  Future<void> _addDoctor() async {
    final name = TextEditingController();
    final branch = TextEditingController();
    var title = 'Op. Dr.';
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setLocal) => AlertDialog(
          title: const Text('Yeni doktor'),
          content: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Wrap(
                  spacing: 6,
                  children: [
                    for (final t in ['Op. Dr.', 'Uzm. Dr.', 'Doç. Dr.', 'Prof. Dr.'])
                      ChoiceChip(label: Text(t), selected: title == t, onSelected: (_) => setLocal(() => title = t)),
                  ],
                ),
                const SizedBox(height: 12),
                TextField(controller: name, autofocus: true, decoration: const InputDecoration(labelText: 'Ad soyad *')),
                const SizedBox(height: 12),
                TextField(controller: branch, decoration: const InputDecoration(labelText: 'Branş (ör. Ortopedi)')),
              ],
            ),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Vazgeç')),
            FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Kaydet')),
          ],
        ),
      ),
    );
    if (ok != true || name.text.trim().isEmpty) return;
    try {
      final d = await _api.post('hospitals/${_hospital!['id']}/doctors', {
        'name': name.text.trim(),
        'title': title,
        'branch': branch.text.trim().isEmpty ? null : branch.text.trim(),
      });
      await _loadDoctors(select: d['id']);
      if (mounted) showMessage(context, '${d['display_name']} eklendi.');
    } on ApiException catch (e) {
      if (mounted) showMessage(context, e.message, error: true);
    }
  }

  Future<void> _pickDate() async {
    FocusScope.of(context).unfocus();
    final now = trNow();
    final d = await showDatePicker(
      context: context,
      initialDate: _date,
      firstDate: DateTime(now.year - 1),
      lastDate: DateTime(now.year + 2),
    );
    if (d != null) setState(() => _date = d);
  }

  Future<void> _pickTime() async {
    FocusScope.of(context).unfocus();
    final t = await showTimePicker(
      context: context,
      initialTime: _time,
      builder: (ctx, child) => MediaQuery(data: MediaQuery.of(ctx).copyWith(alwaysUse24HourFormat: true), child: child!),
    );
    if (t != null) setState(() => _time = t);
  }

  DateTime get _when => DateTime(_date.year, _date.month, _date.day, _time.hour, _time.minute);

  /// Acil vaka: seçilenlerle ürün kaydına geç (vaka kayıtla birlikte açılır).
  /// Kayıt bitince bu ekran da kapanır; çağıran liste ancak o zaman yenilenir.
  Future<void> _continueEmergency() async {
    final done = await Navigator.of(context).push<bool>(MaterialPageRoute(
      builder: (_) => CaseRecordPage(
        unplanned: UnplannedCase(
          hospital: _hospital!,
          location: _location,
          doctor: _doctor,
          procedure: _procedure.text.trim().isEmpty ? null : _procedure.text.trim(),
        ),
      ),
    ));
    if (done == true && mounted) Navigator.pop(context, true);
  }

  Future<void> _save() async {
    setState(() => _saving = true);
    final body = {
      'hospital_id': _hospital!['id'],
      'location_id': _location?.id,
      'doctor_id': _doctor?['id'],
      'scheduled_at': trWire(_when),
      'procedure': _procedure.text.trim().isEmpty ? null : _procedure.text.trim(),
      'protocol_no': _protocol.text.trim().isEmpty ? null : _protocol.text.trim(),
      'patient_name': _patient.text.trim().isEmpty ? null : _patient.text.trim(),
      'note': _note.text.trim().isEmpty ? null : _note.text.trim(),
    };
    try {
      final r = _editing ? await _api.put('cases/${widget.existing!.id}', body) : await _api.post('cases', body);
      if (!mounted) return;
      showMessage(context, r['message']);
      if (_editing) {
        Navigator.pop(context, true);
      } else {
        Navigator.of(context).pushReplacement(MaterialPageRoute(builder: (_) => CaseDetailPage(caseId: r['case']['id'])));
      }
    } on ApiException catch (e) {
      if (mounted) showMessage(context, e.message, error: true);
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(widget.emergency ? 'Acil vaka kaydet' : _editing ? 'Planı düzenle' : 'Vaka planla')),
      body: ListView(
        padding: const EdgeInsets.only(bottom: 16),
        children: [
          if (widget.emergency)
            Card(
              color: Colors.orange.shade50,
              child: ListTile(
                leading: Icon(Icons.bolt, color: Colors.orange.shade800),
                title: const Text('Plan gerekmez'),
                subtitle: const Text('Hastaneyi seçin, ardından kullanılan ürünleri okutup imza alın. Vaka "Plansız" olarak kaydedilir.'),
              ),
            ),
          const StepTitle('Nerede?'),
          PickerField<Map<String, dynamic>>(
            label: 'Hastane *',
            icon: Icons.local_hospital_outlined,
            value: _hospital,
            items: _hospitals,
            itemLabel: (h) => h['name'] ?? '',
            itemSubtitle: (h) => [h['district'], h['city']].whereType<String>().join(', '),
            onChanged: (h) => _selectHospital(h),
          ),
          PickerField<Warehouse>(
            label: 'Bölüm / ameliyathane (isteğe bağlı)',
            icon: Icons.place_outlined,
            value: _location,
            items: _locations,
            itemLabel: (w) => w.path,
            enabled: _hospital != null,
            onChanged: (w) => setState(() => _location = w),
          ),
          StepTitle(
            'Doktor',
            trailing: _hospital == null
                ? null
                : TextButton.icon(onPressed: _addDoctor, icon: const Icon(Icons.person_add_alt, size: 18), label: const Text('Yeni doktor')),
          ),
          if (_hospital == null)
            Padding(padding: const EdgeInsets.symmetric(horizontal: 16), child: Text('Önce hastane seçin.', style: TextStyle(color: theme.hintColor)))
          else if (_doctors.isEmpty)
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 16),
              child: Text('Bu hastanede kayıtlı doktor yok. "Yeni doktor" ile ekleyin.', style: TextStyle(color: theme.hintColor)),
            )
          else
            PickerField<Map<String, dynamic>>(
              label: 'Doktor',
              icon: Icons.person_outline,
              value: _doctor,
              items: _doctors,
              itemLabel: (d) => d['display_name'] ?? d['name'],
              itemSubtitle: (d) => d['branch'],
              onChanged: (d) => setState(() => _doctor = d),
            ),
          if (!widget.emergency) ...[
            const StepTitle('Ne zaman?'),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 16),
              child: Row(
                children: [
                  Expanded(
                    child: OutlinedButton.icon(
                      style: OutlinedButton.styleFrom(minimumSize: const Size.fromHeight(52)),
                      onPressed: _pickDate,
                      icon: const Icon(Icons.event),
                      label: Text(dayLabel(_date)),
                    ),
                  ),
                  const SizedBox(width: 8),
                  SizedBox(
                    width: 120,
                    child: OutlinedButton.icon(
                      style: OutlinedButton.styleFrom(minimumSize: const Size.fromHeight(52)),
                      onPressed: _pickTime,
                      icon: const Icon(Icons.schedule),
                      label: Text('${two(_time.hour)}:${two(_time.minute)}'),
                    ),
                  ),
                ],
              ),
            ),
          ],
          const StepTitle('Ameliyat'),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16),
            child: TextField(
              controller: _procedure,
              onChanged: (_) => setState(() {}),
              decoration: const InputDecoration(labelText: 'Ameliyat türü', prefixIcon: Icon(Icons.healing_outlined)),
            ),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
            child: Wrap(
              spacing: 6,
              runSpacing: 0,
              children: [
                for (final p in _procedures)
                  ChoiceChip(
                    label: Text(p),
                    selected: _procedure.text == p,
                    onSelected: (_) => setState(() => _procedure.text = p),
                  ),
              ],
            ),
          ),
          if (!widget.emergency) ...[
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
              child: TextField(
                controller: _patient,
                maxLength: 120,
                textCapitalization: TextCapitalization.words,
                decoration: const InputDecoration(
                  labelText: 'Hasta adı soyadı (isteğe bağlı)',
                  prefixIcon: Icon(Icons.personal_injury_outlined),
                  counterText: '',
                ),
              ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
              child: TextField(
                controller: _protocol,
                decoration: const InputDecoration(
                  labelText: 'Hasta protokol no (isteğe bağlı)',
                  helperText: 'Hasta bilgileri ameliyatta da girilebilir.',
                  prefixIcon: Icon(Icons.badge_outlined),
                ),
              ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
              child: TextField(controller: _note, maxLines: 2, decoration: const InputDecoration(labelText: 'Not (isteğe bağlı)')),
            ),
          ],
        ],
      ),
      bottomNavigationBar: SubmitBar(
        label: widget.emergency ? 'Devam: ürünleri okut' : _editing ? 'Değişiklikleri kaydet' : 'Vakayı planla',
        saving: _saving,
        missing: _hospital == null ? 'Hastane seçin' : null,
        onPressed: widget.emergency ? _continueEmergency : _save,
      ),
    );
  }
}
