import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:signature/signature.dart';
import 'package:uuid/uuid.dart';

import '../core/api.dart';
import '../core/location.dart';
import '../core/models.dart';
import '../ui/common.dart';
import '../ui/scanner_page.dart';
import '../ui/stock_picker.dart';

/// Konsinye teslimat (POST /deliveries), paneldeki "Hastaneye Teslim" ile aynı akış.
/// Teslim et: mümessilin aracından hastanedeki lokasyona (imza zorunlu).
/// Geri al: hastanedeki lokasyondan araca.
/// Konum kaydet anında alınır; alınamazsa nedeni gönderilir.
class DeliveryPage extends StatefulWidget {
  const DeliveryPage({super.key});

  @override
  State<DeliveryPage> createState() => _DeliveryPageState();
}

class _DeliveryPageState extends State<DeliveryPage> {
  final _api = Api.instance;

  bool _isReturn = false;
  List<Map<String, dynamic>> _hospitals = [];
  Map<String, dynamic>? _hospital;
  List<Warehouse> _locations = [];
  Warehouse? _location;
  List<Warehouse> _vehicles = [];
  Warehouse? _vehicle;
  List<Map<String, dynamic>> _contacts = [];
  Map<String, dynamic>? _contact;
  bool _otherPerson = false;
  final _otherName = TextEditingController();
  final _otherTitle = TextEditingController();
  final _note = TextEditingController();

  final _cart = StockCart();
  bool _rowsLoading = false;
  final _signature = SignatureController(penStrokeWidth: 2.5, penColor: Colors.black);
  bool _signed = false;

  bool _saving = false;
  String _idempotencyKey = const Uuid().v4();
  Map<String, dynamic>? _done;

  @override
  void initState() {
    super.initState();
    _signature.onDrawEnd = () => setState(() => _signed = _signature.isNotEmpty);
    _api.get('hospitals', {'is_active': 1, 'per_page': 100}).then((r) {
      setState(() => _hospitals = List<Map<String, dynamic>>.from(r['data']));
    }).catchError(_onError);
    _api.get('mobile/warehouses').then((r) {
      final list = (r as List).map((e) => Warehouse(Map<String, dynamic>.from(e))).toList();
      setState(() => _vehicles = list);
      if (list.length == 1) _selectVehicle(list.first);
    }).catchError(_onError);
  }

  @override
  void dispose() {
    _signature.dispose();
    super.dispose();
  }

  void _onError(Object e) {
    if (mounted) showMessage(context, '$e', error: true);
  }

  void _setMode(bool isReturn) {
    if (_isReturn == isReturn) return;
    setState(() {
      _isReturn = isReturn;
      _cart.blockExpired = !isReturn;
    });
    _loadRows();
  }

  Future<void> _selectHospital(Map<String, dynamic> h) async {
    setState(() {
      _hospital = h;
      _location = null;
      _locations = [];
      _contact = null;
      _contacts = [];
      _otherPerson = false;
      if (_isReturn) _cart.reset([]);
    });
    try {
      final locs = await _api.get('hospitals/${h['id']}/locations');
      final active = (locs as List).map((e) => Warehouse(Map<String, dynamic>.from(e))).where((w) => w.isActive).toList();
      if (!mounted) return;
      setState(() => _locations = active);
      if (active.length == 1) _selectLocation(active.first);
      await _loadContacts();
    } catch (e) {
      _onError(e);
    }
  }

  Future<void> _loadContacts() async {
    final c = await _api.get('hospitals/${_hospital!['id']}/contacts');
    if (mounted) setState(() => _contacts = List<Map<String, dynamic>>.from(c));
  }

  void _selectLocation(Warehouse l) {
    setState(() {
      _location = l;
      final cl = _contact?['location_id'];
      if (cl != null && cl != l.id) _contact = null;
    });
    if (_isReturn) _loadRows();
  }

  void _selectVehicle(Warehouse v) {
    setState(() => _vehicle = v);
    if (!_isReturn) _loadRows();
  }

  /// Teslimde araçtaki stok, geri almada hastane lokasyonundaki stok
  Future<void> _loadRows() async {
    setState(() => _cart.reset([]));
    final String? path;
    Map<String, Object?>? query;
    if (_isReturn) {
      path = _hospital != null && _location != null ? 'hospitals/${_hospital!['id']}/consignment' : null;
      query = {'location_id': _location?.id};
    } else {
      path = _vehicle != null ? 'mobile/warehouses/${_vehicle!.id}/stock' : null;
    }
    if (path == null) return;
    setState(() => _rowsLoading = true);
    try {
      final r = await _api.get(path, query);
      final list = (r['stocks'] ?? r['rows']) as List;
      if (mounted) setState(() => _cart.reset(list.map((e) => StockRow(Map<String, dynamic>.from(e))).toList()));
    } catch (e) {
      _onError(e);
    } finally {
      if (mounted) setState(() => _rowsLoading = false);
    }
  }

  List<Map<String, dynamic>> get _visibleContacts => _contacts
      .where((c) => c['is_active'] != false && (c['location_id'] == null || c['location_id'] == _location?.id))
      .toList();

  Future<void> _scan() async {
    final raw = await scanBarcode(context);
    if (raw == null || !mounted) return;
    final (text, error) = _cart.scan(raw, notHere: _isReturn ? 'Bu ürün seçili lokasyonda yok.' : 'Bu ürün aracınızda yok.');
    setState(() {});
    showMessage(context, text, error: error);
  }

  Future<void> _addContact() async {
    final name = TextEditingController();
    final title = TextEditingController();
    final phone = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Hastane personeli ekle'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            TextField(controller: name, decoration: const InputDecoration(labelText: 'Ad soyad *')),
            const SizedBox(height: 12),
            TextField(controller: title, decoration: const InputDecoration(labelText: 'Unvan (ör. Sorumlu hemşire)')),
            const SizedBox(height: 12),
            TextField(controller: phone, keyboardType: TextInputType.phone, decoration: const InputDecoration(labelText: 'Telefon')),
          ],
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Vazgeç')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Kaydet')),
        ],
      ),
    );
    if (ok != true || name.text.trim().isEmpty) return;
    try {
      final c = await _api.post('hospitals/${_hospital!['id']}/contacts', {
        'name': name.text.trim(),
        'title': title.text.trim().isEmpty ? null : title.text.trim(),
        'phone': phone.text.trim().isEmpty ? null : phone.text.trim(),
        'location_id': _location?.id,
      });
      await _loadContacts();
      if (!mounted) return;
      setState(() {
        _otherPerson = false;
        _contact = _contacts.where((x) => x['id'] == c['id']).firstOrNull;
      });
      showMessage(context, '${c['name']} hastane personeline eklendi.');
    } catch (e) {
      _onError(e);
    }
  }

  String? get _missing {
    if (_hospital == null) return 'Hastane seçin';
    if (_location == null) return 'Lokasyon seçin';
    if (_vehicle == null) return 'Araç seçin';
    if (_otherPerson ? _otherName.text.trim().isEmpty : _contact == null) {
      return _isReturn ? 'Teslim eden kişiyi seçin' : 'Teslim alan kişiyi seçin';
    }
    if (_cart.lines.isEmpty) return 'En az bir ürün seçin';
    if (!_isReturn && !_signed) return 'Teslim alanın imzası gerekli';
    return null;
  }

  Future<void> _submit() async {
    setState(() => _saving = true);
    // İşlem anındaki konum
    final location = await capturePosition();
    String? signature;
    if (_signed) {
      final png = await _signature.toPngBytes();
      if (png != null) signature = 'data:image/png;base64,${base64Encode(png)}';
    }
    try {
      final r = await _api.post(
        'deliveries',
        {
          'type': _isReturn ? 'return' : 'delivery',
          'hospital_id': _hospital!['id'],
          'location_id': _location!.id,
          'vehicle_id': _vehicle!.id,
          'contact_id': _otherPerson ? null : _contact?['id'],
          'receiver_name': _otherPerson ? _otherName.text.trim() : null,
          'receiver_title': _otherPerson && _otherTitle.text.trim().isNotEmpty ? _otherTitle.text.trim() : null,
          'items': [for (final (row, q) in _cart.lines) {'stock_id': row.id, 'quantity': q}],
          'signature': signature,
          'note': _note.text.trim().isEmpty ? null : _note.text.trim(),
          ...location.toJson(),
          'device_time': DateTime.now().toUtc().toIso8601String(),
        },
        headers: {'Idempotency-Key': _idempotencyKey},
      );
      if (!mounted) return;
      setState(() => _done = Map<String, dynamic>.from(r['delivery'])..['_location'] = location.label);
    } on ApiException catch (e) {
      if (!mounted) return;
      showMessage(context, e.message, error: true);
      if (e.status == 422) _loadRows();
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  /// Aynı hastane ve araçla yeni belge
  void _startNew() {
    setState(() {
      _done = null;
      _idempotencyKey = const Uuid().v4();
      _signature.clear();
      _signed = false;
      _note.clear();
    });
    _loadRows();
  }

  @override
  Widget build(BuildContext context) {
    if (_done != null) return _doneView(context);
    final theme = Theme.of(context);
    return Scaffold(
      appBar: AppBar(title: const Text('Hastane Teslimatı')),
      body: ListView(
        padding: const EdgeInsets.only(bottom: 16),
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
            child: SegmentedButton<bool>(
              segments: const [
                ButtonSegment(value: false, label: Text('Teslim et'), icon: Icon(Icons.call_made)),
                ButtonSegment(value: true, label: Text('Geri al'), icon: Icon(Icons.call_received)),
              ],
              selected: {_isReturn},
              onSelectionChanged: (s) => _setMode(s.first),
            ),
          ),
          const StepTitle('1. Hastane ve lokasyon'),
          PickerField<Map<String, dynamic>>(
            label: 'Hastane',
            icon: Icons.local_hospital_outlined,
            value: _hospital,
            items: _hospitals,
            itemLabel: (h) => h['name'] ?? '',
            itemSubtitle: (h) => [h['district'], h['city']].whereType<String>().join(', '),
            onChanged: _selectHospital,
          ),
          PickerField<Warehouse>(
            label: 'Hastanedeki lokasyon',
            icon: Icons.place_outlined,
            value: _location,
            items: _locations,
            itemLabel: (w) => w.name,
            enabled: _hospital != null,
            onChanged: _selectLocation,
          ),
          PickerField<Warehouse>(
            label: 'Araç / çanta',
            icon: Icons.local_shipping_outlined,
            value: _vehicle,
            items: _vehicles,
            itemLabel: (w) => w.label,
            onChanged: _selectVehicle,
          ),
          StepTitle(_isReturn ? '2. Teslim eden' : '2. Teslim alan'),
          if (_hospital == null)
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 16),
              child: Text('Önce hastane seçin.', style: TextStyle(color: theme.hintColor)),
            )
          else
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 16),
              child: Wrap(
                spacing: 8,
                runSpacing: 4,
                children: [
                  for (final c in _visibleContacts)
                    ChoiceChip(
                      label: Text(c['title'] == null ? c['name'] : '${c['name']} · ${c['title']}'),
                      selected: !_otherPerson && _contact?['id'] == c['id'],
                      onSelected: (_) => setState(() {
                        _otherPerson = false;
                        _contact = _contact?['id'] == c['id'] ? null : c;
                      }),
                    ),
                  ChoiceChip(
                    label: const Text('Başka biri'),
                    selected: _otherPerson,
                    onSelected: (_) => setState(() {
                      _contact = null;
                      _otherPerson = true;
                    }),
                  ),
                  ActionChip(avatar: const Icon(Icons.person_add_alt, size: 18), label: const Text('Personel ekle'), onPressed: _addContact),
                ],
              ),
            ),
          if (_otherPerson) ...[
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 4),
              child: TextField(
                controller: _otherName,
                onChanged: (_) => setState(() {}),
                decoration: const InputDecoration(labelText: 'Ad soyad *'),
              ),
            ),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
              child: TextField(controller: _otherTitle, decoration: const InputDecoration(labelText: 'Unvan')),
            ),
          ],
          StepTitle(
            _isReturn ? '3. Geri alınan ürünler' : '3. Teslim edilen ürünler',
            trailing: _cart.lines.isEmpty ? null : Text('${_cart.lines.length} kalem · toplam ${qty(_cart.totalUnits)}'),
          ),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16),
            child: FilledButton.tonalIcon(
              onPressed: _cart.rows.isEmpty ? null : _scan,
              icon: const Icon(Icons.qr_code_scanner),
              label: const Text('Barkod okut'),
            ),
          ),
          if (_rowsLoading) const Padding(padding: EdgeInsets.all(16), child: LinearProgressIndicator()),
          if (!_rowsLoading)
            StockList(
              cart: _cart,
              onChanged: () => setState(() {}),
              emptyText: _isReturn
                  ? (_location == null ? 'Önce hastane ve lokasyon seçin.' : 'Bu lokasyonda konsinye ürün yok.')
                  : (_vehicle == null ? 'Önce araç seçin.' : 'Aracınızda ürün yok.'),
            ),
          StepTitle(
            _isReturn ? '4. İmza (isteğe bağlı)' : '4. Teslim alanın imzası',
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
              foregroundDecoration: BoxDecoration(
                border: Border.all(color: theme.colorScheme.outline),
                borderRadius: BorderRadius.circular(12),
              ),
              child: Signature(controller: _signature, height: 180, backgroundColor: Colors.white),
            ),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
            child: TextField(controller: _note, decoration: const InputDecoration(labelText: 'Not (isteğe bağlı)')),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
            child: Row(
              children: [
                Icon(Icons.my_location, size: 18, color: theme.hintColor),
                const SizedBox(width: 6),
                Expanded(
                  child: Text(
                    'Kaydettiğiniz an konum alınıp belgeye eklenir. Alınamazsa belge "konum alınamadı" olarak işaretlenir.',
                    style: TextStyle(color: theme.hintColor, fontSize: 12),
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
      bottomNavigationBar: SubmitBar(
        label: _isReturn ? 'Geri almayı kaydet' : 'Teslimatı kaydet',
        saving: _saving,
        missing: _missing,
        onPressed: _submit,
      ),
    );
  }

  Widget _doneView(BuildContext context) {
    final d = _done!;
    return Scaffold(
      appBar: AppBar(title: const Text('Hastane Teslimatı')),
      body: Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(Icons.check_circle, size: 72, color: Theme.of(context).colorScheme.primary),
              const SizedBox(height: 12),
              Text('${d['number']} kaydedildi', style: Theme.of(context).textTheme.titleLarge),
              const SizedBox(height: 8),
              Text(
                '${(d['hospital'] as Map?)?['name'] ?? ''} · ${(d['location'] as Map?)?['name'] ?? ''}',
                textAlign: TextAlign.center,
              ),
              const SizedBox(height: 4),
              Text(d['_location'] ?? '', style: TextStyle(color: Theme.of(context).hintColor)),
              const SizedBox(height: 24),
              FilledButton.icon(onPressed: _startNew, icon: const Icon(Icons.add), label: const Text('Yeni belge')),
              TextButton(onPressed: () => Navigator.pop(context), child: const Text('Ana ekrana dön')),
            ],
          ),
        ),
      ),
    );
  }
}
