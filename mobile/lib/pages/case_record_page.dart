import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:signature/signature.dart';
import 'package:uuid/uuid.dart';

import '../core/api.dart';
import '../core/gs1.dart';
import '../core/location.dart';
import '../core/models.dart';
import '../ui/common.dart';
import '../ui/photos.dart';
import '../ui/scanner_page.dart';

/// Plansız (acil) vakanın kayıttan önce seçilen bilgileri
class UnplannedCase {
  UnplannedCase({required this.hospital, this.location, this.doctor, this.procedure});
  final Map<String, dynamic> hospital;
  final Warehouse? location;
  final Map<String, dynamic>? doctor;
  final String? procedure;
}

/// Ameliyathanede vaka kaydı: ürünleri okut, her birini Kullanıldı / Açıldı / Kullanılmadı işaretle,
/// tutanağı hemşireye / doktora imzalat. Ürün hastane dolabından ya da personelin aracından düşer.
/// [caseId] planlı vakayı kaydeder; [unplanned] verilirse plan olmadan yeni (acil) vaka açılır.
class CaseRecordPage extends StatefulWidget {
  const CaseRecordPage({super.key, this.caseId, this.unplanned}) : assert(caseId != null || unplanned != null);
  final int? caseId;
  final UnplannedCase? unplanned;

  @override
  State<CaseRecordPage> createState() => _CaseRecordPageState();
}

/// Tutanaktaki bir satır
class _Line {
  _Line(this.row);
  final StockRow row;
  double quantity = 1;
  String status = 'used';
}

class _CaseRecordPageState extends State<CaseRecordPage> {
  final _api = Api.instance;
  SurgeryCase? _case;
  String? _loadError;

  /// Hastanedeki ve araçtaki kullanılabilir stok satırları
  List<StockRow> _rows = [];
  bool _rowsLoading = true;
  final List<_Line> _lines = [];

  final _protocol = TextEditingController();
  final _patient = TextEditingController();
  final _note = TextEditingController();
  final _email = TextEditingController();
  final _otherName = TextEditingController();
  final _otherTitle = TextEditingController();
  List<Map<String, dynamic>> _contacts = [];
  Map<String, dynamic>? _contact;
  bool _otherPerson = false;

  final _signature = SignatureController(penStrokeWidth: 2.5, penColor: Colors.black);
  bool _signed = false;
  final List<PhotoDraft> _photos = [];

  bool _saving = false;
  final String _idempotencyKey = const Uuid().v4();
  Map<String, dynamic>? _done;

  @override
  void initState() {
    super.initState();
    _signature.onDrawEnd = () => setState(() => _signed = _signature.isNotEmpty);
    _load();
  }

  @override
  void dispose() {
    _signature.dispose();
    super.dispose();
  }

  bool get _isUnplanned => widget.unplanned != null;

  /// Ekranın üstündeki vaka özeti
  bool get _ready => _isUnplanned || _case != null;

  Future<void> _load() async {
    try {
      final int hid;
      if (_isUnplanned) {
        hid = widget.unplanned!.hospital['id'];
      } else {
        final r = await _api.get('cases/${widget.caseId}');
        final c = SurgeryCase(Map<String, dynamic>.from(r));
        if (!mounted) return;
        setState(() {
          _case = c;
          _protocol.text = c.protocolNo ?? '';
          _patient.text = c.patientName ?? '';
        });
        if (!c.isPlanned) {
          setState(() => _loadError = 'Bu vakanın ürünleri zaten kaydedilmiş.');
          return;
        }
        hid = c.hospital!['id'];
      }
      final results = await Future.wait([
        _api.get('hospitals/$hid/consignment'),
        _api.get('hospitals/$hid/contacts'),
        _api.get('mobile/warehouses'),
      ]);
      final rows = <StockRow>[
        for (final e in (results[0]['rows'] as List)) StockRow(Map<String, dynamic>.from(e)..['_source'] = 'hospital'),
      ];
      for (final v in results[2] as List) {
        final s = await _api.get('mobile/warehouses/${v['id']}/stock');
        for (final e in s['stocks'] as List) {
          rows.add(StockRow(Map<String, dynamic>.from(e)
            ..['_source'] = 'vehicle'
            ..['warehouse'] = {'name': v['name']}));
        }
      }
      if (!mounted) return;
      setState(() {
        _rows = rows;
        _rowsLoading = false;
        _contacts = List<Map<String, dynamic>>.from(results[1]).where((x) => x['is_active'] != false).toList();
      });
    } on ApiException catch (e) {
      if (mounted) setState(() => _loadError = e.message);
    }
  }

  // ---------------------------------------------------------------- satırlar

  double _usedOf(StockRow r) => _lines.where((l) => l.row.id == r.id).fold(0, (s, l) => s + l.quantity);
  double _available(StockRow r) => r.quantity - _usedOf(r);

  /// Satır ekle: seri numaralı ürün tek satır; lotlu üründe aynı satırın "kullanıldı" satırına +1
  String _add(StockRow r) {
    if (_available(r) <= 0) return '${r.product.name}: bu satırdaki ürünlerin hepsi eklendi.';
    final same = _lines.where((l) => l.row.id == r.id && l.status == 'used').firstOrNull;
    setState(() {
      if (same != null && r.serial == null) {
        same.quantity += 1;
      } else {
        _lines.insert(0, _Line(r));
      }
    });
    return '✓ ${r.product.name}${r.serial != null ? ' · SN ${r.serial}' : ''}${r.lot != null ? ' · LOT ${r.lot}' : ''}';
  }

  /// Okutulan karekodu hastane dolabında, sonra araçta arar (SKT'si en yakın önce)
  Future<void> _scan() async {
    final raw = await scanBarcode(context);
    if (raw == null || !mounted) return;
    final g = parseGs1(raw);
    if (g.isEmpty) return showMessage(context, 'Karekod okunamadı.', error: true);

    final same = _rows.where((r) => r.product.matches(g.gtin, g.plain)).toList();
    if (same.isEmpty) return showMessage(context, 'Bu ürün bu hastanede ya da aracınızda yok.', error: true);
    var candidates = same;
    if (g.serial != null) {
      candidates = candidates.where((r) => r.serial == g.serial).toList();
    } else if (g.lot != null) {
      candidates = candidates.where((r) => r.lot == g.lot && (g.expiry == null || r.expiry == g.expiry)).toList();
    }
    final usable = candidates.where((r) => _available(r) > 0).toList()
      ..sort((a, b) {
        final src = (a.json['_source'] == 'hospital' ? 0 : 1).compareTo(b.json['_source'] == 'hospital' ? 0 : 1);
        return src != 0 ? src : (a.expiry ?? '9999').compareTo(b.expiry ?? '9999');
      });
    if (usable.isEmpty) {
      final name = same.first.product.name;
      return showMessage(
        context,
        g.serial != null
            ? '$name SN ${g.serial} ${candidates.isEmpty ? 'burada yok.' : 'zaten eklendi.'}'
            : '$name${g.lot != null ? ' LOT ${g.lot}' : ''}: eklenebilecek başka ürün yok.',
        error: true,
      );
    }
    final msg = _add(usable.first);
    if (mounted) showMessage(context, msg, error: !msg.startsWith('✓'));
  }

  /// Listeden ürün seç (barkodu okunmayan ürünler için)
  Future<void> _pickFromList() async {
    FocusScope.of(context).unfocus();
    final picked = await showModalBottomSheet<StockRow>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (ctx) => _RowPicker(rows: _rows.where((r) => _available(r) > 0).toList()),
    );
    if (picked != null && mounted) showMessage(context, _add(picked));
  }

  // ---------------------------------------------------------------- kanıt

  bool get _emailInvalid {
    final e = _email.text.trim();
    return e.isNotEmpty && !RegExp(r'^[^\s@]+@[^\s@]+\.[^\s@]+$').hasMatch(e);
  }

  String? get _missing {
    if (_lines.isEmpty) return 'En az bir ürün okutun';
    if (_otherPerson ? _otherName.text.trim().isEmpty : _contact == null) return 'Tutanağı imzalayan kişiyi seçin';
    if (_emailInvalid) return 'E-posta adresi geçersiz';
    if (!_signed) return 'İmza gerekli';
    return null;
  }

  Future<void> _submit() async {
    setState(() => _saving = true);
    final location = await capturePosition();
    final png = await _signature.toPngBytes();
    final u = widget.unplanned;
    try {
      final r = await _api.post(
        u == null ? 'cases/${widget.caseId}/record' : 'cases/unplanned',
        {
          if (u != null) ...{
            'hospital_id': u.hospital['id'],
            'location_id': u.location?.id,
            'doctor_id': u.doctor?['id'],
            'procedure': u.procedure,
          },
          'protocol_no': _protocol.text.trim().isEmpty ? null : _protocol.text.trim(),
          'patient_name': _patient.text.trim().isEmpty ? null : _patient.text.trim(),
          'note': _note.text.trim().isEmpty ? null : _note.text.trim(),
          'contact_id': _otherPerson ? null : _contact?['id'],
          'signer_name': _otherPerson ? _otherName.text.trim() : null,
          'signer_title': _otherPerson && _otherTitle.text.trim().isNotEmpty ? _otherTitle.text.trim() : null,
          'receiver_email': _email.text.trim().isEmpty ? null : _email.text.trim(),
          'items': [for (final l in _lines) {'stock_id': l.row.id, 'quantity': l.quantity, 'status': l.status}],
          'signature': png == null ? null : 'data:image/png;base64,${base64Encode(png)}',
          'photos': [for (final p in _photos) p.toJson()],
          ...location.toJson(),
          'device_time': DateTime.now().toUtc().toIso8601String(),
        },
        headers: {'Idempotency-Key': _idempotencyKey},
      );
      if (mounted) setState(() => _done = Map<String, dynamic>.from(r['case']));
    } on ApiException catch (e) {
      if (mounted) showMessage(context, e.message, error: true);
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  // ---------------------------------------------------------------- ekran

  @override
  Widget build(BuildContext context) {
    if (_done != null) return _doneView(context);
    final c = _case;
    final theme = Theme.of(context);
    final billable = _lines.where((l) => l.status != 'unused').fold<double>(0, (s, l) => s + l.quantity);
    final unused = _lines.where((l) => l.status == 'unused').fold<double>(0, (s, l) => s + l.quantity);

    final u = widget.unplanned;
    return Scaffold(
      appBar: AppBar(title: Text(u != null ? 'Acil vaka kaydı' : c == null ? 'Vaka kaydı' : '${c.number} · kayıt')),
      body: _loadError != null
          ? Center(child: Padding(padding: const EdgeInsets.all(24), child: Text(_loadError!)))
          : !_ready
              ? const Center(child: CircularProgressIndicator())
              : ListView(
                  padding: const EdgeInsets.only(bottom: 16),
                  children: [
                    Card(
                      child: ListTile(
                        leading: Icon(u != null ? Icons.bolt : Icons.medical_services_outlined, color: u != null ? Colors.orange.shade800 : null),
                        title: Text(u != null ? u.hospital['name'] ?? '' : c!.hospitalName, style: const TextStyle(fontWeight: FontWeight.w600)),
                        subtitle: Text(u != null
                            ? ['Plansız vaka', u.location?.path, u.doctor?['display_name'] ?? u.doctor?['name'], u.procedure].whereType<String>().join(' · ')
                            : [hm(c!.scheduledAt), c.locationPath, c.doctorName, c.procedure].whereType<String>().join(' · ')),
                      ),
                    ),

                    // 1. Ürünler
                    StepTitle(
                      '1. Kullanılan ürünler',
                      trailing: _lines.isEmpty ? null : Text('${qty(billable)} faturalanacak${unused > 0 ? ' · ${qty(unused)} kullanılmadı' : ''}'),
                    ),
                    Padding(
                      padding: const EdgeInsets.symmetric(horizontal: 16),
                      child: Row(
                        children: [
                          Expanded(
                            flex: 3,
                            child: FilledButton.icon(
                              style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52)),
                              onPressed: _rowsLoading ? null : _scan,
                              icon: const Icon(Icons.qr_code_scanner),
                              label: const Text('Barkod okut'),
                            ),
                          ),
                          const SizedBox(width: 8),
                          Expanded(
                            flex: 2,
                            child: OutlinedButton.icon(
                              style: OutlinedButton.styleFrom(minimumSize: const Size.fromHeight(52)),
                              onPressed: _rowsLoading ? null : _pickFromList,
                              icon: const Icon(Icons.list),
                              label: const Text('Listeden'),
                            ),
                          ),
                        ],
                      ),
                    ),
                    if (_rowsLoading) const Padding(padding: EdgeInsets.all(16), child: LinearProgressIndicator()),
                    if (!_rowsLoading && _rows.isEmpty)
                      Padding(
                        padding: const EdgeInsets.all(16),
                        child: Text('Bu hastanede ve aracınızda ürün yok.', style: TextStyle(color: theme.hintColor)),
                      ),
                    if (!_rowsLoading && _rows.isNotEmpty && _lines.isEmpty)
                      Padding(
                        padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
                        child: Text(
                          'Ameliyatta açılan her ürünün karekodunu okutun. Varsayılan durum "Kullanıldı"; '
                          'açılıp kullanılmayanı "Açıldı", salondan geri döneni "Kullanılmadı" yapın.',
                          style: TextStyle(color: theme.hintColor),
                        ),
                      ),
                    for (final l in _lines)
                      _LineCard(
                        line: l,
                        max: l.quantity + _available(l.row),
                        onChanged: () => setState(() {}),
                        onRemove: () => setState(() => _lines.remove(l)),
                      ),

                    // 2. Protokol
                    const StepTitle('2. Hasta bilgisi'),
                    Padding(
                      padding: const EdgeInsets.symmetric(horizontal: 16),
                      child: TextField(
                        controller: _patient,
                        maxLength: 120,
                        textCapitalization: TextCapitalization.words,
                        decoration: const InputDecoration(
                          prefixIcon: Icon(Icons.person_outline),
                          labelText: 'Hasta adı soyadı',
                          hintText: 'İsteğe bağlı',
                          counterText: '',
                        ),
                      ),
                    ),
                    Padding(
                      padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
                      child: TextField(
                        controller: _protocol,
                        decoration: const InputDecoration(prefixIcon: Icon(Icons.badge_outlined), labelText: 'Protokol no', hintText: 'İsteğe bağlı'),
                      ),
                    ),

                    // 3. Tutanağı imzalayan
                    const StepTitle('3. Tutanağı imzalayan'),
                    Padding(
                      padding: const EdgeInsets.symmetric(horizontal: 16),
                      child: Wrap(
                        spacing: 8,
                        runSpacing: 4,
                        children: [
                          for (final p in _contacts)
                            ChoiceChip(
                              label: Text(p['title'] == null ? p['name'] : '${p['name']} · ${p['title']}'),
                              selected: !_otherPerson && _contact?['id'] == p['id'],
                              onSelected: (_) => setState(() {
                                _otherPerson = false;
                                _contact = _contact?['id'] == p['id'] ? null : p;
                                _email.text = _contact?['email'] ?? '';
                              }),
                            ),
                          ChoiceChip(
                            label: const Text('Başka biri'),
                            selected: _otherPerson,
                            onSelected: (_) => setState(() {
                              _contact = null;
                              _otherPerson = true;
                              _email.clear();
                            }),
                          ),
                        ],
                      ),
                    ),
                    if (_otherPerson) ...[
                      Padding(
                        padding: const EdgeInsets.fromLTRB(16, 8, 16, 4),
                        child: TextField(controller: _otherName, onChanged: (_) => setState(() {}), decoration: const InputDecoration(labelText: 'Ad soyad *')),
                      ),
                      Padding(
                        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
                        child: TextField(controller: _otherTitle, decoration: const InputDecoration(labelText: 'Unvan (ör. Ameliyathane hemşiresi)')),
                      ),
                    ],
                    if (_contact != null || _otherPerson)
                      Padding(
                        padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
                        child: TextField(
                          controller: _email,
                          keyboardType: TextInputType.emailAddress,
                          onChanged: (_) => setState(() {}),
                          decoration: InputDecoration(
                            labelText: 'Tutanak kopyasının gideceği e-posta',
                            prefixIcon: const Icon(Icons.mail_outline),
                            errorText: _emailInvalid ? 'Geçerli bir e-posta yazın' : null,
                            helperText: 'Boş bırakılırsa gönderilmez.',
                          ),
                        ),
                      ),

                    // 4. İmza
                    StepTitle(
                      '4. İmza',
                      trailing: _signed
                          ? TextButton(
                              onPressed: () => setState(() {
                                _signature.clear();
                                _signed = false;
                              }),
                              child: const Text('Temizle'),
                            )
                          : null,
                    ),
                    Padding(
                      padding: const EdgeInsets.symmetric(horizontal: 16),
                      child: Container(
                        clipBehavior: Clip.antiAlias,
                        decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(12)),
                        foregroundDecoration: BoxDecoration(border: Border.all(color: theme.colorScheme.outline), borderRadius: BorderRadius.circular(12)),
                        child: Signature(controller: _signature, height: 170, backgroundColor: Colors.white),
                      ),
                    ),

                    // 5. Fotoğraflar ve not
                    const StepTitle('5. Fotoğraflar (isteğe bağlı)'),
                    Padding(
                      padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
                      child: Text('Kullanım formu, ürün etiketleri, hasta etiket sayfası…', style: TextStyle(color: theme.hintColor)),
                    ),
                    PhotoDraftsField(photos: _photos, kinds: casePhotoKinds, onChanged: () => setState(() {})),
                    Padding(
                      padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
                      child: TextField(controller: _note, decoration: const InputDecoration(labelText: 'Not (isteğe bağlı)')),
                    ),
                  ],
                ),
      bottomNavigationBar: !_ready || _loadError != null
          ? null
          : SubmitBar(label: 'Vakayı kaydet', saving: _saving, missing: _missing, onPressed: _submit),
    );
  }

  Widget _doneView(BuildContext context) {
    final d = _done!;
    final theme = Theme.of(context);
    final emailed = d['email_status'] == 'sent';
    return Scaffold(
      appBar: AppBar(title: const Text('Vaka kaydı')),
      body: Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(Icons.check_circle, size: 72, color: theme.colorScheme.primary),
              const SizedBox(height: 12),
              Text('${d['number']} kaydedildi', style: theme.textTheme.titleLarge),
              const SizedBox(height: 8),
              const Text('Kullanılan ve açılan ürünler stoktan düşüldü. Vaka yönetici onayına gönderildi.', textAlign: TextAlign.center),
              const SizedBox(height: 12),
              Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Icon(emailed ? Icons.mark_email_read_outlined : Icons.mail_outline, size: 18, color: emailed ? theme.colorScheme.primary : theme.hintColor),
                  const SizedBox(width: 6),
                  Flexible(
                    child: Text(
                      emailed
                          ? 'Tutanak ${d['email_to']} adresine gönderildi'
                          : d['email_status'] == 'failed'
                              ? 'Tutanak e-postası gönderilemedi; vaka sayfasından tekrar gönderebilirsiniz.'
                              : 'Tutanak e-postayla gönderilmedi.',
                      textAlign: TextAlign.center,
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 24),
              FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('Tamam')),
            ],
          ),
        ),
      ),
    );
  }
}

/// Tutanak satırı: ürün, kaynak, durum seçimi, miktar
class _LineCard extends StatelessWidget {
  const _LineCard({required this.line, required this.max, required this.onChanged, required this.onRemove});
  final _Line line;
  final double max;
  final VoidCallback onChanged;
  final VoidCallback onRemove;

  @override
  Widget build(BuildContext context) {
    final r = line.row;
    final fromHospital = r.json['_source'] == 'hospital';
    final muted = TextStyle(color: Theme.of(context).hintColor, fontSize: 13);
    return Card(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(14, 10, 6, 10),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(r.product.name, style: const TextStyle(fontWeight: FontWeight.w600)),
                      Text([if (r.detail.isNotEmpty) r.detail, fromHospital ? 'Dolap: ${r.warehouseName}' : 'Araç: ${r.warehouseName}'].join(' · '), style: muted),
                      if (r.expired || r.expiringSoon) Padding(padding: const EdgeInsets.only(top: 4), child: ExpiryChip(r)),
                    ],
                  ),
                ),
                IconButton(tooltip: 'Kaldır', onPressed: onRemove, icon: const Icon(Icons.close)),
              ],
            ),
            const SizedBox(height: 8),
            // Durum: tam genişlik, sığmayan metin bölünmesin
            Padding(
              padding: const EdgeInsets.only(right: 8),
              child: SizedBox(
                width: double.infinity,
                child: SegmentedButton<String>(
                  showSelectedIcon: false,
                  style: ButtonStyle(
                    visualDensity: VisualDensity.compact,
                    padding: const WidgetStatePropertyAll(EdgeInsets.symmetric(horizontal: 4)),
                    textStyle: WidgetStatePropertyAll(Theme.of(context).textTheme.labelMedium),
                  ),
                  segments: const [
                    ButtonSegment(value: 'used', label: Text('Kullanıldı', maxLines: 1, softWrap: false)),
                    ButtonSegment(value: 'opened', label: Text('Açıldı', maxLines: 1, softWrap: false)),
                    ButtonSegment(value: 'unused', label: Text('Kullanılmadı', maxLines: 1, softWrap: false)),
                  ],
                  selected: {line.status},
                  onSelectionChanged: (s) {
                    line.status = s.first;
                    onChanged();
                  },
                ),
              ),
            ),
            if (r.serial == null)
              Row(
                children: [
                  Text('Adet', style: muted),
                  const Spacer(),
                  QtyStepper(
                    value: line.quantity,
                    max: max,
                    onChanged: (v) {
                      if (v <= 0) {
                        onRemove();
                        return;
                      }
                      line.quantity = v;
                      onChanged();
                    },
                  ),
                ],
              ),
          ],
        ),
      ),
    );
  }
}

/// Barkodu okunamayan ürün için arama listesi
class _RowPicker extends StatefulWidget {
  const _RowPicker({required this.rows});
  final List<StockRow> rows;

  @override
  State<_RowPicker> createState() => _RowPickerState();
}

class _RowPickerState extends State<_RowPicker> {
  String _q = '';

  @override
  Widget build(BuildContext context) {
    final q = trLower(_q);
    final list = widget.rows
        .where((r) => q.isEmpty || trLower('${r.product.name} ${r.product.code ?? ''} ${r.lot ?? ''} ${r.serial ?? ''}').contains(q))
        .toList()
      ..sort((a, b) => a.product.name.compareTo(b.product.name));
    return SizedBox(
      height: MediaQuery.of(context).size.height * 0.8,
      child: Column(
        children: [
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
            child: TextField(
              decoration: const InputDecoration(prefixIcon: Icon(Icons.search), hintText: 'Ürün, lot veya seri ara'),
              onChanged: (v) => setState(() => _q = v),
            ),
          ),
          Expanded(
            child: ListView.builder(
              itemCount: list.length,
              itemBuilder: (_, i) {
                final r = list[i];
                final fromHospital = r.json['_source'] == 'hospital';
                return ListTile(
                  leading: Icon(fromHospital ? Icons.local_hospital_outlined : Icons.local_shipping_outlined),
                  title: Text(r.product.name),
                  subtitle: Text([if (r.detail.isNotEmpty) r.detail, r.warehouseName ?? '', if (r.expiry != null) 'SKT ${dateTr(r.expiry)}'].join(' · ')),
                  trailing: Text(qty(r.quantity), style: const TextStyle(fontWeight: FontWeight.w700)),
                  onTap: () => Navigator.pop(context, r),
                );
              },
            ),
          ),
        ],
      ),
    );
  }
}
