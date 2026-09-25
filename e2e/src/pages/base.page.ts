import { expect, type Locator, type Page } from '@playwright/test';

/**
 * Elemen yang ada di semua halaman setelah login (layout, sidebar, flash).
 *
 * Konvensi Page Object di suite ini:
 * - Page Object berisi locator dan aksi (goto, klik, isi form).
 * - `expect` di dalam Page Object hanya dipakai untuk MENUNGGU aksi selesai
 *   (mis. qty keranjang sudah berubah), bukan untuk memverifikasi hasil bisnis.
 * - Pengecualian: method berawalan `expect...` adalah pengecekan yang dipakai
 *   ulang di banyak test (didaftarkan di eslint `assertFunctionNames`).
 * - Verifikasi hasil (nilai, pesan, isi database) ditulis di file test.
 */
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
