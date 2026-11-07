import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:mobile_scanner/mobile_scanner.dart';

/// Kamerayla barkod / GS1 DataMatrix / QR okur ve okunan ham metni döndürür.
/// Kamera yoksa (emülatör vb.) metni elle yazma seçeneği de var.
Future<String?> scanBarcode(BuildContext context) {
  // Dönüşte klavye kendiliğinden açılmasın
  FocusManager.instance.primaryFocus?.unfocus();
  return Navigator.of(context).push<String>(MaterialPageRoute(builder: (_) => const ScannerPage()));
}

class ScannerPage extends StatefulWidget {
  const ScannerPage({super.key});

  @override
  State<ScannerPage> createState() => _ScannerPageState();
}

class _ScannerPageState extends State<ScannerPage> {
  final _controller = MobileScannerController(
    detectionSpeed: DetectionSpeed.noDuplicates,
    formats: const [
      BarcodeFormat.dataMatrix,
      BarcodeFormat.qrCode,
      BarcodeFormat.ean13,
      BarcodeFormat.ean8,
      BarcodeFormat.code128,
      BarcodeFormat.code39,
      BarcodeFormat.upcA,
      BarcodeFormat.itf14,
    ],
  );
  bool _done = false;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  void _finish(String value) {
    if (_done || value.trim().isEmpty) return;
    _done = true;
    HapticFeedback.mediumImpact();
    Navigator.of(context).pop(value);
  }

  Future<void> _manual() async {
    final ctrl = TextEditingController();
    final value = await showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Barkodu elle gir'),
        content: TextField(
          controller: ctrl,
          autofocus: true,
          decoration: const InputDecoration(hintText: 'ör. (01)08691234567893(17)271231(10)LOT42'),
          onSubmitted: (v) => Navigator.pop(ctx, v),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Vazgeç')),
          FilledButton(onPressed: () => Navigator.pop(ctx, ctrl.text), child: const Text('Tamam')),
        ],
      ),
    );
    if (value != null) _finish(value);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors.black,
      appBar: AppBar(
        title: const Text('Barkod okut'),
        actions: [
          IconButton(
            tooltip: 'Fener',
            icon: const Icon(Icons.flashlight_on_outlined),
            onPressed: () => _controller.toggleTorch(),
          ),
        ],
      ),
      body: Stack(
        children: [
          MobileScanner(
            controller: _controller,
            onDetect: (capture) {
              final raw = capture.barcodes.map((b) => b.rawValue).whereType<String>().firstOrNull;
              if (raw != null) _finish(raw);
            },
            errorBuilder: (context, error) => Center(
              child: Padding(
                padding: const EdgeInsets.all(24),
                child: Text(
                  error.errorCode == MobileScannerErrorCode.permissionDenied
                      ? 'Kamera izni verilmedi. Ayarlardan izin verin veya barkodu elle girin.'
                      : 'Kamera açılamadı. Barkodu elle girebilirsiniz.',
                  style: const TextStyle(color: Colors.white),
                  textAlign: TextAlign.center,
                ),
              ),
            ),
          ),
          Center(
            child: Container(
              width: 260,
              height: 260,
              decoration: BoxDecoration(
                border: Border.all(color: Colors.white, width: 2),
                borderRadius: BorderRadius.circular(16),
              ),
            ),
          ),
          Positioned(
            left: 16,
            right: 16,
            bottom: 32,
            child: Column(
              children: [
                const Text(
                  'Karekodu (DataMatrix) veya barkodu çerçeveye getirin',
                  style: TextStyle(color: Colors.white),
                  textAlign: TextAlign.center,
                ),
                const SizedBox(height: 12),
                OutlinedButton.icon(
                  style: OutlinedButton.styleFrom(foregroundColor: Colors.white, side: const BorderSide(color: Colors.white)),
                  onPressed: _manual,
                  icon: const Icon(Icons.keyboard),
                  label: const Text('Elle gir'),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
