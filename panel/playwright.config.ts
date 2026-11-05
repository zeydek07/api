import { defineConfig, devices } from '@playwright/test';

/**
 * Kalıcı tarayıcı testleri.
 *
 * Testler gerçek veriye dokunmaz: ayrı bir veritabanında (medikal_stok_e2e), ayrı portlarda
 * (API 8020, panel 4300) çalışır. Her çalıştırmada veritabanı sıfırlanıp örnek veriyle doldurulur.
 *
 *   npm run e2e          # tüm testler
 *   npm run e2e:ui       # adım adım izleyerek
 *   npm run e2e:report   # son çalıştırmanın raporu
 */
const API_PORT = 8020;
const PANEL_PORT = 4300;

export default defineConfig({
  testDir: './e2e',
  globalSetup: './e2e/global-setup.ts',
  // Testler aynı veritabanını değiştirdiği için sırayla çalışır
  fullyParallel: false,
  workers: 1,
  retries: process.env['CI'] ? 1 : 0,
  timeout: 60_000,
  expect: { timeout: 10_000 },
  reporter: [['list'], ['html', { open: 'never' }]],
  use: {
    baseURL: `http://127.0.0.1:${PANEL_PORT}`,
    locale: 'tr-TR',
    timezoneId: 'Europe/Istanbul',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    // Bilgisayardaki Chrome kullanılır; CI'da PW_CHANNEL boş bırakılıp chromium indirilir
    channel: process.env['PW_CHANNEL'] ?? 'chrome',
    // Sahada konum işlem anında alınır: testlerde cihaz Kartal'da, izin verilmiş
    permissions: ['geolocation'],
    geolocation: { latitude: 40.9067, longitude: 29.1932, accuracy: 15 },
  },
  projects: [
    { name: 'oturum', testMatch: /auth\.setup\.ts/ },
    {
      name: 'masaüstü',
      dependencies: ['oturum'],
      testIgnore: /mobile\.spec\.ts/,
      use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 900 } },
    },
    {
      name: 'telefon',
      dependencies: ['oturum'],
      testMatch: /mobile\.spec\.ts/,
      use: { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true },
    },
  ],
  webServer: [
    {
      // API: test ortamı (.env.e2e). OPcache PHP 8.5 yerleşik sunucusunda sorun çıkardığı için kapalı.
      command: `php -d opcache.enable=0 -S 127.0.0.1:${API_PORT} ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php`,
      cwd: '../public',
      env: { APP_ENV: 'e2e' },
      url: `http://127.0.0.1:${API_PORT}/up`,
      // PHP sunucusu her isteği yazar; çıktı kalabalık olmasın (Laravel hataları storage/logs'a düşer)
      stdout: 'ignore',
      stderr: 'ignore',
      reuseExistingServer: false,
      timeout: 60_000,
    },
    {
      command: `npx ng serve --port ${PANEL_PORT} --host 127.0.0.1 --proxy-config proxy.e2e.json`,
      url: `http://127.0.0.1:${PANEL_PORT}`,
      reuseExistingServer: false,
      timeout: 180_000,
    },
  ],
});
