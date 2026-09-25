import { test as base } from '@playwright/test';
import { AppClient } from '../api/app-client';
import { env, type Role } from '../config/env';
import { Database } from '../db/database';
import { CashSessionPage } from '../pages/cash-session.page';
import { DashboardPage } from '../pages/dashboard.page';
import { LoginPage } from '../pages/login.page';
import { PosPage } from '../pages/pos.page';
import { ProductFormPage } from '../pages/product-form.page';
import { SalesHistoryPage } from '../pages/sales-history.page';

interface Options {
  /**
   * Login otomatis sebelum test, lewat HTTP (cepat, tanpa UI).
   * Dipasang per describe dengan `test.use({ loginAs: 'cashier' })`.
   * Setiap test mendapat sesi PHP sendiri, jadi keranjang POS tidak
   * bocor antar test yang berjalan paralel.
   */
  loginAs: Role | undefined;
}

interface Pages {
  loginPage: LoginPage;
  dashboardPage: DashboardPage;
  productFormPage: ProductFormPage;
  posPage: PosPage;
  cashSessionPage: CashSessionPage;
  salesHistoryPage: SalesHistoryPage;
}

interface TestFixtures extends Pages {
  /** Klien HTTP yang berbagi cookie (sesi login) dengan `page`. */
  api: AppClient;
}

interface WorkerFixtures {
  db: Database;
}

export const test = base.extend<Options & TestFixtures, WorkerFixtures>({
  loginAs: [undefined, { option: true }],

  page: async ({ page, loginAs }, use) => {
    if (loginAs) {
      await new AppClient(page.request).login(env.users[loginAs]);
    }
    await use(page);
  },

  db: [
    async ({}, use) => {
      const db = Database.connect();
      await use(db);
      await db.close();
    },
    { scope: 'worker' },
  ],

  api: async ({ page }, use) => {
    await use(new AppClient(page.request));
  },

  loginPage: async ({ page }, use) => use(new LoginPage(page)),
  dashboardPage: async ({ page }, use) => use(new DashboardPage(page)),
  productFormPage: async ({ page }, use) => use(new ProductFormPage(page)),
  posPage: async ({ page }, use) => use(new PosPage(page)),
  cashSessionPage: async ({ page }, use) => use(new CashSessionPage(page)),
  salesHistoryPage: async ({ page }, use) => use(new SalesHistoryPage(page)),
});

export { expect } from '@playwright/test';
