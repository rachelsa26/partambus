import { test as base, type Page } from '@playwright/test';
import { AppClient } from '../api/app-client';
import { env, type Credentials } from '../config/env';
import { Database } from '../db/database';
import { CashSessionPage } from '../pages/cash-session.page';
import { DashboardPage } from '../pages/dashboard.page';
import { LoginPage } from '../pages/login.page';
import { PosPage } from '../pages/pos.page';
import { ProductFormPage } from '../pages/product-form.page';

interface Pages {
  loginPage: LoginPage;
  dashboardPage: DashboardPage;
  productFormPage: ProductFormPage;
  posPage: PosPage;
  cashSessionPage: CashSessionPage;
}

interface TestFixtures extends Pages {
  /** Login via HTTP (cepat) — sesi PHP baru per test, jadi keranjang POS tidak bocor antar test. */
  loginAs: (user: Credentials) => Promise<Page>;
  /** Browser yang sudah login sebagai owner. */
  asOwner: Page;
  /** Browser yang sudah login sebagai kasir. */
  asCashier: Page;
  /** Klien HTTP yang berbagi cookie dengan `page`. */
  api: AppClient;
}

interface WorkerFixtures {
  db: Database;
}

export const test = base.extend<TestFixtures, WorkerFixtures>({
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

  loginAs: async ({ page, api }, use) => {
    await use(async (user) => {
      await api.login(user);
      return page;
    });
  },

  asOwner: async ({ loginAs }, use) => {
    await use(await loginAs(env.users.owner));
  },

  asCashier: async ({ loginAs }, use) => {
    await use(await loginAs(env.users.cashier));
  },

  loginPage: async ({ page }, use) => use(new LoginPage(page)),
  dashboardPage: async ({ page }, use) => use(new DashboardPage(page)),
  productFormPage: async ({ page }, use) => use(new ProductFormPage(page)),
  posPage: async ({ page }, use) => use(new PosPage(page)),
  cashSessionPage: async ({ page }, use) => use(new CashSessionPage(page)),
});

export { expect } from '@playwright/test';
