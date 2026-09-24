import { randomBytes } from 'node:crypto';

/**
 * Semua data yang dibuat test diberi suffix unik, sehingga test bisa
 * jalan paralel dan berulang kali tanpa bentrok (tidak perlu reset DB
 * di antara test).
 */
export function uniqueSuffix(): string {
  return `${Date.now().toString(36)}${randomBytes(2).toString('hex')}`.toUpperCase();
}

export interface NewProduct {
  code: string;
  name: string;
  baseUnit: string;
  sellingPrice: number;
  lowStockThreshold: number;
}

export function buildProduct(overrides: Partial<NewProduct> = {}): NewProduct {
  const suffix = uniqueSuffix();
  return {
    code: `E2E-${suffix}`,
    name: `Produk E2E ${suffix}`,
    baseUnit: 'PCS',
    sellingPrice: 3500,
    lowStockThreshold: 5,
    ...overrides,
  };
}

/** Produk & harga dari docker/db/02-test-data.sql */
export const seededProducts = {
  kopi: { code: 'QA-KOPI', barcode: '8990000000011', name: 'QA Kopi Sachet', unit: 'PCS', price: 2000 },
  rokokPcs: { code: 'QA-ROKOK', name: 'QA Rokok Filter', unit: 'PCS', price: 2500 },
  rokokSlop: { code: 'QA-ROKOK', name: 'QA Rokok Filter', unit: 'SLOP', price: 24000, conversion: 10 },
  stokKosong: { code: 'QA-HABIS', name: 'QA Stok Kosong', unit: 'PCS', price: 5000 },
} as const;

/**
 * Format rupiah persis seperti helper rupiah() di aplikasi: Rp12.500.
 * Catatan: nilai negatif dirender aplikasi sebagai "Rp-1.000" (bukan
 * "-Rp1.000"); helper ini sengaja meniru perilaku tersebut.
 */
export function rupiah(amount: number): string {
  return `Rp${Math.round(amount).toLocaleString('id-ID')}`;
}
