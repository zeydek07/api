import { execFileSync } from 'node:child_process';
import { copyFileSync, existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * Her test çalıştırmasından önce: test ortamı ayarını hazırla, test veritabanını
 * oluştur, tabloları baştan kur ve örnek veriyi yükle. Gerçek veritabanına dokunmaz.
 */
export default function globalSetup(): void {
  const root = resolve(__dirname, '../..');
  const envFile = resolve(root, '.env.e2e');
  const env = { ...process.env, APP_ENV: 'e2e' };
  const artisan = (...args: string[]) =>
    execFileSync('php', ['artisan', ...args], { cwd: root, env, stdio: 'pipe' }).toString();

  if (!existsSync(envFile)) {
    copyFileSync(resolve(root, '.env.e2e.example'), envFile);
    artisan('key:generate', '--force');
  }

  const vars = Object.fromEntries(
    readFileSync(envFile, 'utf8')
      .split(/\r?\n/)
      .filter((l) => /^[A-Z_]+=/.test(l))
      .map((l) => {
        const i = l.indexOf('=');
        return [l.slice(0, i), l.slice(i + 1).replace(/^"|"$/g, '')];
      }),
  );
  if (!/_e2e$/.test(vars['DB_DATABASE'] ?? '')) {
    // Yanlışlıkla gerçek veritabanını silmemek için güvenlik kilidi
    throw new Error(`.env.e2e içindeki DB_DATABASE "_e2e" ile bitmeli (şu an: ${vars['DB_DATABASE']}).`);
  }

  // Veritabanı yoksa oluştur
  const php = `$d=new PDO("mysql:host=${vars['DB_HOST']};port=${vars['DB_PORT']}",${JSON.stringify(vars['DB_USERNAME'])},${JSON.stringify(vars['DB_PASSWORD'] ?? '')});
    $d->exec("CREATE DATABASE IF NOT EXISTS \`${vars['DB_DATABASE']}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");`;
  execFileSync('php', ['-r', php], { stdio: 'pipe' });

  artisan('migrate:fresh', '--seed', '--force');
  artisan('db:seed', '--class=DemoSeeder', '--force');
}
