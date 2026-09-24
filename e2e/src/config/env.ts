import 'dotenv/config';

function read(name: string, fallback: string): string {
  const value = process.env[name];
  return value !== undefined && value !== '' ? value : fallback;
}

export type Role = 'owner' | 'cashier';

export interface Credentials {
  username: string;
  password: string;
  fullName: string;
}

/**
 * Semua nilai bisa di-override lewat environment variable / file .env.
 * Default-nya cocok dengan docker-compose.yml + docker/db/02-test-data.sql,
 * sehingga `npm test` langsung jalan setelah `docker compose up`.
 */
export const env = {
  baseURL: read('BASE_URL', 'http://localhost:8080'),

  users: {
    owner: {
      username: read('OWNER_USERNAME', 'qa_owner'),
      password: read('OWNER_PASSWORD', 'QaOwner123!'),
      fullName: 'QA Owner',
    },
    cashier: {
      username: read('CASHIER_USERNAME', 'qa_kasir'),
      password: read('CASHIER_PASSWORD', 'QaKasir123!'),
      fullName: 'QA Kasir',
    },
    inactive: {
      username: read('INACTIVE_USERNAME', 'qa_nonaktif'),
      password: read('INACTIVE_PASSWORD', 'QaKasir123!'),
      fullName: 'QA Nonaktif',
    },
  } satisfies Record<string, Credentials>,

  db: {
    host: read('DB_HOST', '127.0.0.1'),
    port: Number(read('DB_PORT', '3307')),
    database: read('DB_NAME', 'partambus'),
    user: read('DB_USER', 'partambus'),
    password: read('DB_PASS', 'partambus'),
  },
} as const;
