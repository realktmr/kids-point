const { test, expect } = require('@playwright/test');
const {
  loginAsParent,
  logout,
  createChild,
  createEarnItem,
  createUseItem,
  loginAsChild,
  navClick,
} = require('./helpers');

const CHILD_NAME = 'えいつーいち';
const CHILD_PIN = '1234';
const EARN_ITEM_NAME = 'おてつだいE2E';
const EARN_ITEM_LABEL = `${EARN_ITEM_NAME}（+100P）`;
const USE_ITEM_NAME = 'おやつE2E';
const USE_ITEM_LABEL = `${USE_ITEM_NAME} 30P`;
const EARN_MEMO = 'E2E-やったよ申請';
const USE_MEMO = 'E2E-つかう申請';

test.describe.serial('主要な利用の流れ', () => {
  /** @type {import('@playwright/test').Page} */
  let page;

  test.beforeAll(async ({ browser }) => {
    page = await browser.newPage();
  });

  test.afterAll(async () => {
    await page.close();
  });

  test('初期画面: 子供選択画面が表示される', async () => {
    await page.goto('/');
    await expect(page.locator('#screen-child-select')).toBeVisible();
    await expect(page.getByRole('button', { name: '保護者はこちら' })).toBeVisible();
  });

  test('親としてログインし、子供・もらう項目・つかう項目を用意する', async () => {
    await loginAsParent(page);
    await createChild(page, { name: CHILD_NAME, pin: CHILD_PIN });
    await createEarnItem(page, { name: EARN_ITEM_NAME, points: 100 });
    await createUseItem(page, { name: USE_ITEM_NAME, points: 30 });
    await navClick(page, 'parent-home');
    await logout(page);
  });

  test('子がログインするとホーム画面に残高0が表示される', async () => {
    await loginAsChild(page, { name: CHILD_NAME, pin: CHILD_PIN });
    await expect(page.locator('#child-home-name')).toHaveText(CHILD_NAME);
    await expect(page.locator('#child-balance')).toHaveText('0');
    await expect(page.locator('#child-balance-yen')).toHaveText('0');
  });

  test('子がやったよ申請をすると申請一覧に「申請中」で表示される', async () => {
    await navClick(page, 'child-earn');
    await expect(page.locator('#screen-child-earn')).toBeVisible();
    await page.locator('#earn-item').selectOption({ label: EARN_ITEM_LABEL });
    await page.locator('#earn-memo').fill(EARN_MEMO);
    await page.locator('#earn-form button[type=submit]').click();

    await expect(page.locator('#screen-child-home')).toBeVisible();
    await navClick(page, 'child-requests');
    const card = page.locator('.entry-card').filter({ hasText: EARN_MEMO });
    await expect(card).toBeVisible();
    await expect(card.locator('.status-pending')).toBeVisible();
    await navClick(page, 'child-home');
    await logout(page);
  });

  test('親が承認待ちで承認すると子の残高が加算される', async () => {
    await loginAsParent(page);
    await navClick(page, 'parent-approvals');
    await expect(page.locator('#screen-parent-approvals')).toBeVisible();

    const card = page.locator('.entry-card').filter({ hasText: EARN_MEMO });
    await expect(card).toBeVisible();
    await card.getByRole('button', { name: '承認' }).click();
    await expect(card).toHaveCount(0);

    await navClick(page, 'parent-home');
    await logout(page);
  });

  test('承認後、子の残高に100ポイント加算されている', async () => {
    await loginAsChild(page, { name: CHILD_NAME, pin: CHILD_PIN });
    await expect(page.locator('#child-balance')).toHaveText('100');
  });

  test('子がつかう項目を選んでつかう申請をする', async () => {
    await navClick(page, 'child-use');
    await expect(page.locator('#screen-child-use')).toBeVisible();
    await expect(page.locator('#use-available')).toHaveText('100');
    await page.locator('#use-item-select').selectOption({ label: USE_ITEM_LABEL });
    await expect(page.locator('#use-item-fixed')).toContainText('30P');
    await page.locator('#use-memo').fill(USE_MEMO);
    await page.locator('#use-form button[type=submit]').click();

    await expect(page.locator('#screen-child-home')).toBeVisible();
    await navClick(page, 'child-requests');
    const card = page.locator('.entry-card').filter({ hasText: USE_MEMO });
    await expect(card).toBeVisible();
    await expect(card.locator('.status-pending')).toBeVisible();
    await navClick(page, 'child-home');
    await logout(page);
  });

  test('親が承認すると子の残高が減算される', async () => {
    await loginAsParent(page);
    await navClick(page, 'parent-approvals');

    const card = page.locator('.entry-card').filter({ hasText: USE_MEMO });
    await expect(card).toBeVisible();
    await expect(card).toContainText('つかう');
    await expect(card).toContainText('-30P');
    await card.getByRole('button', { name: '承認' }).click();
    await expect(card).toHaveCount(0);

    await navClick(page, 'parent-home');
    await logout(page);
  });

  test('承認後、子の残高が70(100-30)になっている', async () => {
    await loginAsChild(page, { name: CHILD_NAME, pin: CHILD_PIN });
    await expect(page.locator('#child-balance')).toHaveText('70');
  });

  test('リロードしてもログイン状態と残高が保持される', async () => {
    await page.reload();
    await expect(page.locator('#screen-child-home')).toBeVisible();
    await expect(page.locator('#child-home-name')).toHaveText(CHILD_NAME);
    await expect(page.locator('#child-balance')).toHaveText('70');
  });

  test('カレンダーの日付をタップすると今日の明細（履歴）が見られる', async () => {
    const day = String(new Date().getDate());
    const cell = page.locator('#child-calendar .calendar-cell.has-entries').filter({
      has: page.locator('.day-num', { hasText: new RegExp(`^${day}$`) }),
    });
    await expect(cell).toBeVisible();
    await cell.click();

    const modal = page.locator('#day-modal');
    await expect(modal).toBeVisible();
    await expect(modal.locator('.entry-card').filter({ hasText: EARN_MEMO })).toBeVisible();
    await expect(modal.locator('.entry-card').filter({ hasText: USE_MEMO })).toBeVisible();

    await page.locator('#day-modal-close').click();
    await expect(modal).toBeHidden();
  });
});
