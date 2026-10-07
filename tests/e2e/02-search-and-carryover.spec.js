const { test, expect } = require('@playwright/test');
const { loginAsParent, createChild, navClick, goParentHome } = require('./helpers');

test.describe.serial('README記載の主な機能: 検索・繰越', () => {
  /** @type {import('@playwright/test').Page} */
  let page;

  test.beforeAll(async ({ browser }) => {
    page = await browser.newPage();
  });

  test.afterAll(async () => {
    await page.close();
  });

  test('検索: 直接付けた記録をキーワードで見つけられる', async () => {
    const childName = 'えいつーに';
    const memo = 'E2E検索用キーワード';

    await loginAsParent(page);
    await createChild(page, { name: childName, pin: '2222' });

    await goParentHome(page);
    await navClick(page, 'parent-direct-add');
    await expect(page.locator('#screen-parent-direct-add')).toBeVisible();
    await page.locator('#direct-child-select').selectOption({ label: childName });
    await page.locator('#direct-points').fill('30');
    await page.locator('#direct-memo').fill(memo);
    await page.locator('#direct-add-form button[type=submit]').click();
    await expect(page.locator('#direct-error')).toBeHidden();

    await navClick(page, 'parent-home');
    await navClick(page, 'parent-search');
    await expect(page.locator('#screen-parent-search')).toBeVisible();
    await page.locator('#search-query').fill(memo);
    await page.locator('#search-form button[type=submit]').click();

    await expect(page.locator('#search-summary')).toContainText('1件');
    await expect(page.locator('#search-summary')).toContainText('+30P');
    const result = page.locator('#search-results .entry-card').filter({ hasText: memo });
    await expect(result).toBeVisible();
  });

  test('繰越: 子供作成時の引き継ぎポイントが残高に反映される', async () => {
    const childName = 'えいつーさん';

    await goParentHome(page);
    await createChild(page, { name: childName, pin: '3333', carryoverPoints: 500 });

    await goParentHome(page);
    await navClick(page, 'parent-calendar');
    await expect(page.locator('#screen-parent-calendar')).toBeVisible();
    await page.locator('#calendar-child-select').selectOption({ label: childName });

    await expect(page.locator('#parent-cal-balance')).toHaveText('500');
    await expect(page.locator('#parent-calendar')).toContainText('繰越');

    const day = String(new Date().getDate());
    const cell = page.locator('#parent-calendar .calendar-cell.has-entries').filter({
      has: page.locator('.day-num', { hasText: new RegExp(`^${day}$`) }),
    });
    await cell.click();
    const modal = page.locator('#day-modal');
    await expect(modal).toBeVisible();
    await expect(modal).toContainText('繰越');
    await expect(modal).toContainText('+500P');
    await page.locator('#day-modal-close').click();
  });
});
