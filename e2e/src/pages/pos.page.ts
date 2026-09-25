import { expect, type Locator, type Page } from '@playwright/test';
import { BasePage } from './base.page';

export type PaymentMethod = 'Tunai' | 'QRIS' | 'Transfer' | 'OVO' | 'GoPay';

export interface SaleResult {
  saleNumber: string;
}

export class PosPage extends BasePage {
  readonly path = '/pos/index.php';

  readonly searchInput: Locator;
  readonly searchButton: Locator;
  readonly cartCount: Locator;
  readonly total: Locator;
  readonly cashReceived: Locator;
  readonly payButton: Locator;
  readonly checkoutErrors: Locator;
  readonly successView: Locator;
  readonly successNumber: Locator;
  readonly successTotal: Locator;
  readonly successChange: Locator;
  readonly noCashSessionWarning: Locator;
  readonly scanToast: Locator;

  constructor(page: Page) {
    super(page);
    this.searchInput = page.getByRole('searchbox', { name: /Scan barcode/ });
    this.searchButton = page.getByRole('button', { name: 'Cari', exact: true });
    this.cartCount = page.locator('#pos-cart-count');
    this.total = page.locator('.pos-summary-total-amount');
    this.cashReceived = page.getByLabel('Dibayar');
    this.payButton = page.getByRole('button', { name: 'Bayar Sekarang' });
    this.checkoutErrors = page.locator('#pos-checkout-errors');
    this.successView = page.locator('#pos-checkout-success-view');
    this.successNumber = page.locator('#pos-checkout-success-number');
    this.successTotal = page.locator('#pos-checkout-success-total');
    this.successChange = page.locator('#pos-checkout-success-change-amount');
    this.noCashSessionWarning = page.getByText('Tunai tidak bisa dipilih: belum ada sesi kas aktif.');
    this.scanToast = page.locator('.pos-scan-toast');
  }

  paymentMethod(method: PaymentMethod): Locator {
    return this.page.getByRole('radio', { name: method, exact: true });
  }

  async search(keyword: string): Promise<void> {
    await this.searchInput.fill(keyword);
    await this.searchButton.click();
  }

  /** Baris hasil pencarian: role button dengan nama "Tambah <produk> (<satuan>) ke keranjang". */
  searchResult(name: string, unit: string): Locator {
    return this.page.getByRole('button', { name: `Tambah ${name} (${unit}) ke keranjang`, exact: true });
  }

  /**
   * Simulasi barcode scanner: ketik kode/barcode persis lalu Enter.
   * Jika cocok dengan tepat satu produk, aplikasi langsung menambahkannya
   * ke keranjang (satuan terkecil) dan menampilkan toast "Ditambahkan: ...".
   */
  async scan(codeOrBarcode: string): Promise<void> {
    await this.searchInput.fill(codeOrBarcode);
    await this.searchInput.press('Enter');
  }

  /** Cari dengan kata kunci (bukan kode persis), lalu pilih baris produk + satuan. */
  async addFromSearch(product: { name: string; unit: string }, options: { useKeyboard?: boolean } = {}): Promise<void> {
    await this.search(product.name);
    const row = this.searchResult(product.name, product.unit);
    await expect(row).toBeVisible();
    const countBefore = await this.cartCount.textContent();
    if (options.useKeyboard) {
      await row.focus();
      await row.press('Enter');
    } else {
      await row.click();
    }
    await expect(this.cartCount).not.toHaveText(countBefore ?? '');
  }

  cartLine(name: string): Locator {
    return this.page.locator('#pos-cart-body tr').filter({ hasText: name });
  }

  stockWarning(name: string): Locator {
    return this.cartLine(name).locator('.pos-line-warning', { hasText: 'Stok kurang' });
  }

  async setQuantity(name: string, qty: number): Promise<void> {
    const input = this.cartLine(name).getByRole('spinbutton', { name: 'Jumlah' });
    await input.fill(String(qty));
    await input.press('Enter');
    await expect(this.cartLine(name).getByRole('spinbutton', { name: 'Jumlah' })).toHaveValue(String(qty));
  }

  /** Radio asli disembunyikan CSS; user mengklik "pill" label-nya. */
  async selectPaymentMethod(method: PaymentMethod): Promise<void> {
    await this.page
      .locator('label.pos-method-pill')
      .filter({ hasText: new RegExp(`^\\s*${method}\\s*$`) })
      .click();
    await expect(this.paymentMethod(method)).toBeChecked();
  }

  async payWith(method: PaymentMethod, cashReceived?: number): Promise<void> {
    await this.selectPaymentMethod(method);
    if (cashReceived !== undefined) {
      await this.cashReceived.fill(String(cashReceived));
    }
    await this.payButton.click();
  }

  /** Tunggu tampilan sukses lalu kembalikan nomor transaksi (SL-YYYYMMDD-0001). */
  async expectSaleCompleted(): Promise<SaleResult> {
    await expect(this.successView).toBeVisible();
    await expect(this.successNumber).toHaveText(/No\. Transaksi: \S+/);
    const text = (await this.successNumber.textContent()) ?? '';
    return { saleNumber: text.replace('No. Transaksi:', '').trim() };
  }
}
