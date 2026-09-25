// Semua pengaturan bisa diganti lewat environment variable, contoh:
//   k6 run -e BASE_URL=http://localhost:8000 scenarios/load.js
// Default-nya menunjuk ke lingkungan TEST Docker (port 8080) dengan user seed QA.
export const BASE_URL = (__ENV.BASE_URL || 'http://localhost:8080').replace(/\/$/, '');

export const CASHIER = {
  username: __ENV.CASHIER_USER || 'qa_kasir',
  password: __ENV.CASHIER_PASS || 'QaKasir123!',
};

export const OWNER = {
  username: __ENV.OWNER_USER || 'qa_owner',
  password: __ENV.OWNER_PASS || 'QaOwner123!',
};

// Produk yang "dijual" oleh kasir virtual. QA Kopi Sachet dari data seed.
export const PRODUCT_BARCODE = __ENV.PRODUCT_BARCODE || '8990000000011';

// Stok minimum sebelum test dimulai. setup() menambah stok lewat halaman
// Penyesuaian Stok (tercatat di ledger), jadi test DB tetap konsisten.
// Tiap skenario memberi angka default sendiri; MIN_STOCK di env menimpanya.
export const minStock = (scenarioDefault) => Number(__ENV.MIN_STOCK || scenarioDefault);

// Kata yang "diketik" kasir di kotak pencarian (search-as-you-type).
export const SEARCH_TERMS = (__ENV.SEARCH_TERMS || 'kopi,rokok,sachet,qa').split(',');
