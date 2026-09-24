import { expect, type Locator, type Page } from '@playwright/test';

/** Elemen yang ada di semua halaman setelah login (layout, sidebar, flash). */
export abstract class BasePage {
  abstract readonly path: string;

  readonly heading: Locator;
  readonly sidebar: Locator;
  readonly successFlash: Locator;
  readonly errorFlash: Locator;
  readonly warningFlash: Locator;

  constructor(protected readonly page: Page) {
    this.heading = page.locator('main h1');
    this.sidebar = page.locator('nav.sidebar');
    this.successFlash = page.locator('.flash-success');
    this.errorFlash = page.locator('.flash-error');
    this.warningFlash = page.locator('.flash-warning');
  }

  async goto(): Promise<void> {
    await this.page.goto(this.path);
  }

  sidebarLink(label: string): Locator {
    return this.sidebar.getByRole('link', { name: label, exact: true });
  }

  async logout(): Promise<void> {
    await this.sidebar.getByRole('link', { name: 'Keluar' }).click();
  }

  async expectLoggedInAs(fullName: string, role: 'owner' | 'cashier'): Promise<void> {
    await expect(this.sidebar.locator('.sidebar-user-name')).toHaveText(fullName);
    await expect(this.sidebar.locator('.sidebar-user-role')).toHaveText(role);
  }
}
