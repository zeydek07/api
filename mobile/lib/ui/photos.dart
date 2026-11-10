import 'dart:convert';
import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';

import '../core/api.dart';
import 'common.dart';

/// Belge fotoğraf türleri (sunucudaki ProofPhoto::KINDS ile aynı)
const photoKindLabels = {
  'usage_form': 'Kullanım formu',
  'product_label': 'Ürün etiketi',
  'patient_label': 'Hasta etiket sayfası',
  'delivery_form': 'Teslim formu / irsaliye',
  'shelf': 'Raf / dolap',
  'other': 'Diğer',
};

const casePhotoKinds = ['usage_form', 'product_label', 'patient_label', 'other'];
const deliveryPhotoKinds = ['delivery_form', 'shelf', 'other'];

/// Bir belgede en fazla fotoğraf sayısı ve bir istekte toplam boyut (sunucu sınırının altında)
const maxPhotos = 10;
const maxPhotoBytes = 4500 * 1024;

/// Henüz gönderilmemiş fotoğraf
class PhotoDraft {
  PhotoDraft(this.bytes, this.kind);
  final Uint8List bytes;
  String kind;

  Map<String, String> toJson() => {'data': 'data:image/jpeg;base64,${base64Encode(bytes)}', 'kind': kind};
}

int photosSize(List<PhotoDraft> list) => list.fold(0, (s, p) => s + p.bytes.length);

/// Fotoğraf çek / galeriden seç; türünü sor. Fotoğraf küçültülerek alınır (1280 px, %60).
Future<PhotoDraft?> pickPhoto(BuildContext context, List<String> kinds, {required int current, required int currentBytes}) async {
  FocusScope.of(context).unfocus();
  if (current >= maxPhotos) {
    showMessage(context, 'En fazla $maxPhotos fotoğraf eklenebilir.', error: true);
    return null;
  }
  var kind = kinds.first;
  final source = await showModalBottomSheet<ImageSource>(
    context: context,
    showDragHandle: true,
    builder: (ctx) => StatefulBuilder(
      builder: (ctx, setLocal) => SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text('Fotoğraf türü', style: Theme.of(ctx).textTheme.titleMedium),
              const SizedBox(height: 8),
              Wrap(
                spacing: 8,
                runSpacing: 4,
                children: [
                  for (final k in kinds)
                    ChoiceChip(label: Text(photoKindLabels[k]!), selected: kind == k, onSelected: (_) => setLocal(() => kind = k)),
                ],
              ),
              const SizedBox(height: 16),
              Row(
                children: [
                  Expanded(
                    child: FilledButton.icon(
                      style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(50)),
                      onPressed: () => Navigator.pop(ctx, ImageSource.camera),
                      icon: const Icon(Icons.photo_camera_outlined),
                      label: const Text('Fotoğraf çek'),
                    ),
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    child: OutlinedButton.icon(
                      style: OutlinedButton.styleFrom(minimumSize: const Size.fromHeight(50)),
                      onPressed: () => Navigator.pop(ctx, ImageSource.gallery),
                      icon: const Icon(Icons.photo_library_outlined),
                      label: const Text('Galeriden'),
                    ),
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    ),
  );
  if (source == null) return null;
  try {
    final file = await ImagePicker().pickImage(source: source, maxWidth: 1280, maxHeight: 1280, imageQuality: 60);
    if (file == null) return null;
    final bytes = await file.readAsBytes();
    if (currentBytes + bytes.length > maxPhotoBytes) {
      if (context.mounted) showMessage(context, 'Fotoğrafların toplam boyutu çok büyük; bu fotoğraf eklenemedi.', error: true);
      return null;
    }
    return PhotoDraft(bytes, kind);
  } catch (e) {
    if (context.mounted) showMessage(context, 'Fotoğraf alınamadı: $e', error: true);
    return null;
  }
}

/// Formdaki fotoğraf bölümü: küçük resimler (türüyle), kaldır, "Fotoğraf ekle"
class PhotoDraftsField extends StatelessWidget {
  const PhotoDraftsField({super.key, required this.photos, required this.kinds, required this.onChanged});
  final List<PhotoDraft> photos;
  final List<String> kinds;
  final VoidCallback onChanged;

  Future<void> _add(BuildContext context) async {
    final p = await pickPhoto(context, kinds, current: photos.length, currentBytes: photosSize(photos));
    if (p != null) {
      photos.add(p);
      onChanged();
    }
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (photos.isNotEmpty)
            SizedBox(
              height: 128,
              child: ListView.separated(
                scrollDirection: Axis.horizontal,
                itemCount: photos.length,
                separatorBuilder: (_, _) => const SizedBox(width: 8),
                itemBuilder: (_, i) {
                  final p = photos[i];
                  return SizedBox(
                    width: 110,
                    child: Column(
                      children: [
                        Stack(
                          children: [
                            ClipRRect(
                              borderRadius: BorderRadius.circular(10),
                              child: Image.memory(p.bytes, width: 110, height: 96, fit: BoxFit.cover),
                            ),
                            Positioned(
                              right: 2,
                              top: 2,
                              child: Material(
                                color: Colors.black54,
                                shape: const CircleBorder(),
                                child: InkWell(
                                  customBorder: const CircleBorder(),
                                  onTap: () {
                                    photos.removeAt(i);
                                    onChanged();
                                  },
                                  child: const Padding(padding: EdgeInsets.all(4), child: Icon(Icons.close, size: 16, color: Colors.white)),
                                ),
                              ),
                            ),
                          ],
                        ),
                        const SizedBox(height: 4),
                        Text(photoKindLabels[p.kind] ?? p.kind, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 12)),
                      ],
                    ),
                  );
                },
              ),
            ),
          const SizedBox(height: 8),
          OutlinedButton.icon(
            style: OutlinedButton.styleFrom(minimumSize: const Size.fromHeight(48)),
            onPressed: photos.length >= maxPhotos ? null : () => _add(context),
            icon: const Icon(Icons.add_a_photo_outlined),
            label: Text(photos.isEmpty ? 'Fotoğraf ekle' : 'Fotoğraf ekle (${photos.length}/$maxPhotos)'),
          ),
        ],
      ),
    );
  }
}

/// Sunucudaki bir fotoğraf (token'lı istekle indirilir, bellekte saklanır)
class AuthImage extends StatefulWidget {
  const AuthImage(this.path, {super.key, this.fit = BoxFit.cover, this.width, this.height});
  final String path;
  final BoxFit fit;
  final double? width;
  final double? height;

  static final _cache = <String, Uint8List>{};

  @override
  State<AuthImage> createState() => _AuthImageState();
}

class _AuthImageState extends State<AuthImage> {
  Uint8List? _bytes;
  bool _failed = false;

  @override
  void initState() {
    super.initState();
    _bytes = AuthImage._cache[widget.path];
    if (_bytes == null) _load();
  }

  Future<void> _load() async {
    try {
      final b = await Api.instance.getBytes(widget.path);
      AuthImage._cache[widget.path] = b;
      if (mounted) setState(() => _bytes = b);
    } catch (_) {
      if (mounted) setState(() => _failed = true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final b = _bytes;
    if (b != null) return Image.memory(b, fit: widget.fit, width: widget.width, height: widget.height);
    return SizedBox(
      width: widget.width,
      height: widget.height,
      child: Center(child: _failed ? const Icon(Icons.broken_image_outlined) : const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2))),
    );
  }
}

/// Belgedeki fotoğraflar: küçük resim + tür; dokununca tam ekran
class PhotoGallery extends StatelessWidget {
  const PhotoGallery({super.key, required this.photos, required this.basePath});

  /// [{id, kind, label}]
  final List<Map<String, dynamic>> photos;

  /// ör. "cases/12/photos"
  final String basePath;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: 128,
      child: ListView.separated(
        padding: const EdgeInsets.symmetric(horizontal: 16),
        scrollDirection: Axis.horizontal,
        itemCount: photos.length,
        separatorBuilder: (_, _) => const SizedBox(width: 8),
        itemBuilder: (context, i) {
          final p = photos[i];
          final path = '$basePath/${p['id']}';
          final label = p['label'] ?? photoKindLabels[p['kind']] ?? '';
          return SizedBox(
            width: 110,
            child: Column(
              children: [
                InkWell(
                  borderRadius: BorderRadius.circular(10),
                  onTap: () => Navigator.of(context).push(MaterialPageRoute(
                    builder: (_) => Scaffold(
                      backgroundColor: Colors.black,
                      appBar: AppBar(backgroundColor: Colors.black, foregroundColor: Colors.white, title: Text(label)),
                      body: InteractiveViewer(child: Center(child: AuthImage(path, fit: BoxFit.contain))),
                    ),
                  )),
                  child: ClipRRect(borderRadius: BorderRadius.circular(10), child: AuthImage(path, width: 110, height: 96)),
                ),
                const SizedBox(height: 4),
                Text(label, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 12)),
              ],
            ),
          );
        },
      ),
    );
  }
}
