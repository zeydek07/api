import { test as setup } from '@playwright/test';
import { USERS, login } from './helpers';

/**
 * Her rol bir kez giriş yapar; oturum (token) dosyaya kaydedilir ve testler bunu kullanır.
 * Böylece her test yeniden giriş yapmaz.
 */
for (const [role, u] of Object.entries(USERS)) {
  setup(`${role} giriş yapar`, async ({ page }) => {
    await login(page, u.email, u.password);
    await page.context().storageState({ path: u.state });
  });
}
