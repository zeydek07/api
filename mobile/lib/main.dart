import 'package:flutter/material.dart';

import 'core/session.dart';
import 'pages/home_page.dart';
import 'pages/login_page.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  Session.instance.restore();
  runApp(const MedikalApp());
}

class MedikalApp extends StatelessWidget {
  const MedikalApp({super.key});

  @override
  Widget build(BuildContext context) {
    final scheme = ColorScheme.fromSeed(seedColor: const Color(0xFF0F766E));
    return MaterialApp(
      title: 'Medikal Stok',
      debugShowCheckedModeBanner: false,
      theme: ThemeData(
        colorScheme: scheme,
        inputDecorationTheme: const InputDecorationTheme(border: OutlineInputBorder()),
        cardTheme: const CardThemeData(margin: EdgeInsets.symmetric(horizontal: 16, vertical: 4)),
      ),
      // Oturum değişince (giriş, çıkış, token süresi dolması) kök ekran değişir
      home: ListenableBuilder(
        listenable: Session.instance,
        builder: (context, _) {
          final s = Session.instance;
          if (!s.ready) return const Scaffold(body: Center(child: CircularProgressIndicator()));
          return s.user == null ? const LoginPage() : const HomePage();
        },
      ),
    );
  }
}
