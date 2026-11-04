import { expect, test } from '@playwright/test';
import { USERS, overflowsHorizontally } from './helpers';

/** Telefon genişliğinde (390px) hiçbir sayfa yana taşmamalı */
for (const role of ['admin', 'mumessil'] as const) {
  test.describe(`Telefon görünümü: ${role}`, () => {
    test.use({ storageState: USERS[role].state });

    test('menüdeki sayfaların hiçbiri yana taşmıyor', async ({ page }) => {
      await page.goto('/');
      await page.getByRole('button', { name: 'Menüyü aç' }).click();
      const hrefs = await page.locator('aside nav a').evaluateAll((els) => els.map((e) => e.getAttribute('href')!));
      expect(hrefs.length).toBeGreaterThan(0);

      const overflowing: string[] = [];
      for (const href of hrefs) {
        await page.goto(href);
        await expect(page.locator('h1').first()).toBeVisible();
        await page.waitForLoadState('networkidle');
        if (await overflowsHorizontally(page)) overflowing.push(href);
      }
      expect(overflowing).toEqual([]);
    });
  });
}

test.describe('Telefon görünümü: teslimat adımları', () => {
  test.use({ storageState: USERS.mumessil.state });

  test('hastane seçilince açılan adımlar ve geri alma yana taşmıyor', async ({ page }) => {
    await page.goto('/teslimat');
    await page.getByRole('button', { name: /Kartal Devlet Hastanesi/ }).click();
    await expect(page.locator('section li:has(input[type=number])').first()).toBeVisible();
    expect(await overflowsHorizontally(page)).toBe(false);

    await page.getByRole('tab', { name: /Hastaneden geri al/ }).click();
    await page.waitForLoadState('networkidle');
    expect(await overflowsHorizontally(page)).toBe(false);
  });
});
