import { Page, expect } from '@playwright/test';

/** Test ortamındaki hesaplar (.env.e2e.example ve DemoSeeder ile aynı) */
export const USERS = {
  admin: { email: 'admin@example.com', password: 'E2e-Admin-2026!', state: 'e2e/.auth/admin.json' },
  depocu: { email: 'depocu1@medikal.test', password: 'Demo1234!', state: 'e2e/.auth/depocu.json' },
  mumessil: { email: 'mumessil1@medikal.test', password: 'Demo1234!', state: 'e2e/.auth/mumessil.json' },
} as const;

export async function login(page: Page, email: string, password: string): Promise<void> {
  await page.goto('/giris');
  await page.locator('#email').fill(email);
  await page.locator('#password').fill(password);
  await page.getByRole('button', { name: 'Giriş yap' }).click();
  await expect(page).not.toHaveURL(/\/giris/);
}

/** Menüdeki bağlantı adları */
export async function menuLabels(page: Page): Promise<string[]> {
  const texts = await page.locator('aside nav a').allTextContents();
  return texts.map((t) => t.trim().replace(/^\S+\s*/, '')); // baştaki simge adını at
}

/** İmza alanına parmakla imza atar gibi çizer */
export async function sign(page: Page): Promise<void> {
  const canvas = page.locator('app-signature-pad canvas');
  await canvas.scrollIntoViewIfNeeded();
  const box = (await canvas.boundingBox())!;
  await page.mouse.move(box.x + 30, box.y + box.height * 0.6);
  await page.mouse.down();
  for (let i = 1; i <= 20; i++) {
    await page.mouse.move(box.x + 30 + i * 12, box.y + box.height * 0.6 - Math.sin(i / 2) * 25, { steps: 2 });
  }
  await page.mouse.up();
}

/** Oturumdaki token ile API'den okur (doğrulama için; ekranda gösterilmeyen bilgileri kontrol etmek) */
export async function apiGet<T>(page: Page, path: string): Promise<T> {
  return page.evaluate(async (p) => {
    const token = localStorage.getItem('medikal.token');
    const r = await fetch('/api' + p, { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } });
    if (!r.ok) throw new Error(`${r.status} ${p}`);
    return r.json();
  }, path);
}

/**
 * Aramalı seçim kutusundan seçer: açar, yazar, aranan seçenek listede görününce Enter'a basar.
 * (Liste sunucudan gelmeden Enter'a basılırsa seçim yapılmaz; bu yüzden beklenir.)
 */
export async function pickFromSearchSelect(page: Page, trigger: string, typed: string, option: string | RegExp): Promise<void> {
  await expect(page.locator(trigger)).toBeEnabled();
  await page.locator(trigger).click();
  await page.keyboard.type(typed);
  await expect(page.locator('.search-select-pane [role=option]', { hasText: option }).first()).toBeVisible();
  await page.keyboard.press('Enter');
  await expect(page.locator(trigger)).toContainText(option);
}

/** Sayfa yatayda ekrandan taşıyor mu */
export async function overflowsHorizontally(page: Page): Promise<boolean> {
  return page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
}

/**
 * Sayfadaki beklenmeyen hataları toplar (JavaScript hatası, 5xx yanıt).
 * Test sonunda expect(errors).toEqual([]) ile kontrol edilir.
 */
export function collectErrors(page: Page): string[] {
  const errors: string[] = [];
  page.on('pageerror', (e) => errors.push(`JS: ${e.message}`));
  page.on('response', (r) => {
    if (r.status() >= 500) errors.push(`HTTP ${r.status()} ${r.url()}`);
  });
  return errors;
}
