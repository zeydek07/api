import 'package:flutter/material.dart';

import '../core/session.dart';
import 'cases_page.dart';
import 'delivery_page.dart';
import 'field_home_page.dart';
import 'lookup_page.dart';
import 'receive_page.dart';
import 'transfer_page.dart';
import 'vehicle_page.dart';

/// Saha personelinin uygulaması: alt menüde Ana sayfa · Vakalar · Aracım · Tümü.
/// Sekmeye her geçişte o sekme verisini yeniler.
class ShellPage extends StatefulWidget {
  const ShellPage({super.key});

  /// Alt sayfalardan sekme değiştirmek için (ör. ana sayfadaki "Tümünü gör")
  static void goTo(BuildContext context, int tab) => context.findAncestorStateOfType<_ShellPageState>()?._select(tab);

  @override
  State<ShellPage> createState() => _ShellPageState();
}

class _ShellPageState extends State<ShellPage> {
  int _tab = 0;

  /// Sekme seçilince artar; sayfalar dinleyip yeniden yükler
  final _refresh = ValueNotifier<int>(0);

  void _select(int i) {
    setState(() => _tab = i);
    _refresh.value++;
  }

  @override
  void dispose() {
    _refresh.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: IndexedStack(
        index: _tab,
        children: [
          FieldHomePage(refresh: _refresh, active: () => _tab == 0),
          CasesPage(refresh: _refresh, active: () => _tab == 1),
          VehiclePage(refresh: _refresh, active: () => _tab == 2),
          const MorePage(),
        ],
      ),
      bottomNavigationBar: NavigationBar(
        selectedIndex: _tab,
        onDestinationSelected: _select,
        destinations: const [
          NavigationDestination(icon: Icon(Icons.home_outlined), selectedIcon: Icon(Icons.home), label: 'Ana sayfa'),
          NavigationDestination(icon: Icon(Icons.medical_services_outlined), selectedIcon: Icon(Icons.medical_services), label: 'Vakalar'),
          NavigationDestination(icon: Icon(Icons.local_shipping_outlined), selectedIcon: Icon(Icons.local_shipping), label: 'Aracım'),
          NavigationDestination(icon: Icon(Icons.apps_outlined), selectedIcon: Icon(Icons.apps), label: 'Tümü'),
        ],
      ),
    );
  }
}

/// Tüm işlemler (yetkiye göre) ve hesap
class MorePage extends StatelessWidget {
  const MorePage({super.key});

  @override
  Widget build(BuildContext context) {
    final s = Session.instance;
    final user = s.user!;
    void open(Widget page) => Navigator.of(context).push(MaterialPageRoute(builder: (_) => page));
    Widget tile(IconData icon, String title, String subtitle, Widget page) => ListTile(
          leading: Icon(icon, color: Theme.of(context).colorScheme.primary),
          title: Text(title, style: const TextStyle(fontWeight: FontWeight.w600)),
          subtitle: Text(subtitle),
          trailing: const Icon(Icons.chevron_right),
          onTap: () => open(page),
        );

    return Scaffold(
      appBar: AppBar(title: const Text('Tümü')),
      body: ListView(
        children: [
          ListTile(
            leading: CircleAvatar(child: Text(user.name.isEmpty ? '?' : user.name[0])),
            title: Text(user.name, style: const TextStyle(fontWeight: FontWeight.w600)),
            subtitle: Text(user.roleName),
          ),
          const Divider(),
          if (s.can('deliveries.create')) ...[
            tile(Icons.move_to_inbox_outlined, 'Hastaneye teslim et', 'Araçtan hastane dolabına, imzalı belge', const DeliveryPage()),
            tile(Icons.undo, 'Hastaneden geri al', 'Hastane dolabından araca', const DeliveryPage(initialReturn: true)),
          ],
          if (s.can('products.view')) tile(Icons.qr_code_scanner, 'Ürün sorgula', 'Barkod okut, ürün nerede gör', const LookupPage()),
          if (s.can('stock.receive')) tile(Icons.inventory_2_outlined, 'Stok girişi', 'Depoya mal kabul', const ReceivePage()),
          if (s.can('stock.transfer')) tile(Icons.outbox_outlined, 'Stok çıkışı', 'Depodan araca / başka depoya', const TransferPage()),
          const Divider(),
          ListTile(
            leading: const Icon(Icons.logout),
            title: const Text('Çıkış yap'),
            onTap: () async {
              final ok = await showDialog<bool>(
                context: context,
                builder: (ctx) => AlertDialog(
                  title: const Text('Çıkış yapılsın mı?'),
                  actions: [
                    TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Vazgeç')),
                    FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Çıkış yap')),
                  ],
                ),
              );
              if (ok == true) await s.logout();
            },
          ),
        ],
      ),
    );
  }
}
