const { expect } = require('@playwright/test');

const PARENT_LOGIN_ID = 'oya';
const PARENT_PASSWORD = 'testpass1234';

// 画面(.screen)は常に全部DOMにあり、.active な1枚だけが表示される。
// data-nav/data-action は複数画面に同名のものがある(例: 「もどる」は data-nav="parent-home"
// が何枚もの画面にある)ため、必ず「今表示されている画面の中」に絞って操作する。
async function navClick(page, name) {
  await page.locator(`.screen.active [data-nav="${name}"]`).click();
}

async function actionClick(page, name) {
  await page.locator(`.screen.active [data-action="${name}"]`).click();
}

async function goParentHome(page) {
  if (await page.locator('#screen-parent-home.active').count()) {
    return;
  }
  await navClick(page, 'parent-home');
  await expect(page.locator('#screen-parent-home')).toBeVisible();
}

async function loginAsParent(page) {
  await page.goto('/');
  await expect(page.locator('#screen-child-select')).toBeVisible();
  await page.getByRole('button', { name: '保護者はこちら' }).click();
  await page.locator('#parent-login-id').fill(PARENT_LOGIN_ID);
  await page.locator('#parent-login-password').fill(PARENT_PASSWORD);
  await page.locator('#parent-login-form button[type=submit]').click();
  await expect(page.locator('#screen-parent-home')).toBeVisible();
}

async function logout(page) {
  await actionClick(page, 'logout');
  await expect(page.locator('#screen-child-select')).toBeVisible();
}

async function createChild(page, { name, pin, carryoverPoints }) {
  await goParentHome(page);
  await navClick(page, 'parent-accounts');
  await expect(page.locator('#screen-parent-accounts')).toBeVisible();
  await page.locator('#new-child-name').fill(name);
  await page.locator('#new-child-pin').fill(pin);
  if (carryoverPoints !== undefined) {
    await page.locator('#new-child-carryover').fill(String(carryoverPoints));
  }
  await page.locator('#child-create-form button[type=submit]').click();
  await expect(page.getByText(`子供: ${name}`)).toBeVisible();
}

async function createEarnItem(page, { name, points }) {
  await goParentHome(page);
  await navClick(page, 'parent-items');
  await expect(page.locator('#screen-parent-items')).toBeVisible();
  await page.locator('#item-new-name').fill(name);
  await page.locator('#item-new-points').fill(String(points));
  await page.locator('#item-create-form button[type=submit]').click();
  await expect(page.getByText(name, { exact: false }).first()).toBeVisible();
}

async function createUseItem(page, { name, points }) {
  await goParentHome(page);
  await navClick(page, 'parent-use-items');
  await expect(page.locator('#screen-parent-use-items')).toBeVisible();
  await page.locator('#use-item-new-name').fill(name);
  await page.locator('#use-item-new-points').fill(String(points));
  await page.locator('#use-item-create-form button[type=submit]').click();
  await expect(page.getByText(name, { exact: false }).first()).toBeVisible();
}

async function loginAsChild(page, { name, pin }) {
  await page.goto('/');
  await expect(page.locator('#screen-child-select')).toBeVisible();
  await page.getByRole('button', { name, exact: true }).click();
  await expect(page.locator('#screen-pin')).toBeVisible();
  for (const digit of pin) {
    await page.locator(`.key[data-key="${digit}"]`).click();
  }
  await expect(page.locator('#screen-child-home')).toBeVisible();
}

module.exports = {
  PARENT_LOGIN_ID,
  PARENT_PASSWORD,
  navClick,
  actionClick,
  goParentHome,
  loginAsParent,
  logout,
  createChild,
  createEarnItem,
  createUseItem,
  loginAsChild,
};
