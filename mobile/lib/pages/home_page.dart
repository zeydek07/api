import 'package:flutter/material.dart';

import '../core/session.dart';
import 'delivery_page.dart';
import 'lookup_page.dart';
import 'receive_page.dart';
import 'transfer_page.dart';

/// Kullanıcının yetkisine göre işlem kartları. Yetkisi olmayan işlem hiç görünmez;
/// görünse bile API aynı yetkiyi tekrar kontrol eder.
class HomePage extends StatelessWidget {
  const HomePage({super.key});

  @override
  Widget build(BuildContext context) {
    final s = Session.instance;
    final user = s.user!;
    final actions = <_Action>[
      if (s.can('stock.receive'))
        _Action('Stok Girişi', 'Depoya mal kabul', Icons.move_to_inbox_outlined, (_) => const ReceivePage()),
      if (s.can('stock.transfer'))
        _Action('Stok Çıkışı', 'Depodan araca / başka depoya', Icons.outbox_outlined, (_) => const TransferPage()),
      if (s.can('deliveries.create'))
        _Action('Hastane Teslimatı', 'Teslim et veya geri al', Icons.local_hospital_outlined, (_) => const DeliveryPage()),
      if (s.can('products.view'))
        _Action('Ürün Sorgula', 'Barkod okut, stoğu gör', Icons.qr_code_scanner, (_) => const LookupPage()),
    ];

    return Scaffold(
      appBar: AppBar(
        title: const Text('Medikal Stok'),
        actions: [
          IconButton(
            tooltip: 'Çıkış yap',
            icon: const Icon(Icons.logout),
            onPressed: () async {
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
      body: ListView(
        padding: const EdgeInsets.only(bottom: 24),
        children: [
          ListTile(
            leading: CircleAvatar(child: Text(user.name.isEmpty ? '?' : user.name[0])),
            title: Text(user.name, style: const TextStyle(fontWeight: FontWeight.w600)),
            subtitle: Text(user.roleName),
          ),
          const SizedBox(height: 8),
          if (actions.isEmpty)
            const Padding(
              padding: EdgeInsets.all(24),
              child: Text('Hesabınıza mobilde yapılabilecek bir işlem yetkisi tanımlanmamış. Yöneticinizle görüşün.'),
            ),
          for (final a in actions)
            Card(
              child: ListTile(
                contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
                leading: Icon(a.icon, size: 32, color: Theme.of(context).colorScheme.primary),
                title: Text(a.title, style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 17)),
                subtitle: Text(a.subtitle),
                trailing: const Icon(Icons.chevron_right),
                onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: a.page)),
              ),
            ),
        ],
      ),
    );
  }
}

class _Action {
  _Action(this.title, this.subtitle, this.icon, this.page);
  final String title;
  final String subtitle;
  final IconData icon;
  final WidgetBuilder page;
}
