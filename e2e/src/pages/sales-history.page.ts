import type { Locator } from '@playwright/test';
import { BasePage } from './base.page';

export class SalesHistoryPage extends BasePage {
  readonly path = '/sales/index.php';

  /** Baris/teks transaksi dengan nomor tertentu, mis. SL-20260925-0001. */
  sale(saleNumber: string): Locator {
    return this.page.getByText(saleNumber);
  }
}
