import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';

import 'core/session.dart';
import 'pages/home_page.dart';
import 'pages/login_page.dart';
import 'pages/shell_page.dart';

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
      // Tarih / saat seçicileri ve sistem metinleri Türkçe
      locale: const Locale('tr'),
      supportedLocales: const [Locale('tr')],
      localizationsDelegates: const [
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
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
          if (s.user == null) return const LoginPage();
          // Saha personeli: alt menülü saha uygulaması; depocu: işlem kartları
          return s.can('cases.create') ? const ShellPage() : const HomePage();
        },
      ),
    );
  }
}
