import type { Locator, Page } from '@playwright/test';
import { BasePage } from './base.page';

export class CashSessionPage extends BasePage {
  readonly path = '/cash/index.php';

  readonly openSessionLink: Locator;
  readonly openingCash: Locator;
  readonly openSubmit: Locator;
  readonly actualCash: Locator;
  readonly note: Locator;
  readonly closeSubmit: Locator;
  readonly expectedCashText: Locator;

  constructor(page: Page) {
    super(page);
    this.openSessionLink = page.getByRole('link', { name: 'Buka Sesi Kas' });
    this.openingCash = page.getByLabel('Kas Awal di Laci');
    this.openSubmit = page.getByRole('button', { name: 'Buka Sesi' });
    this.actualCash = page.getByLabel('Kas Aktual (hasil hitung fisik)');
    this.note = page.getByLabel('Catatan (wajib jika ada selisih)');
    this.closeSubmit = page.getByRole('button', { name: 'Tutup Sesi' });
    this.expectedCashText = page.getByText(/^Kas diharapkan:/);
  }

  async open(openingCash: number): Promise<void> {
    await this.page.goto('/cash/open.php');
    await this.openingCash.fill(String(openingCash));
    await this.openSubmit.click();
  }

  /** Tombol "Tutup Sesi" memakai window.confirm() — otomatis disetujui. */
  async close(actualCash: number, note = ''): Promise<void> {
    await this.page.goto('/cash/close.php');
    await this.actualCash.fill(String(actualCash));
    await this.note.fill(note);
    this.page.once('dialog', (dialog) => dialog.accept());
    await this.closeSubmit.click();
  }
}
