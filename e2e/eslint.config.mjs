import js from '@eslint/js';
import playwright from 'eslint-plugin-playwright';
import tseslint from 'typescript-eslint';

export default tseslint.config(
  { ignores: ['node_modules', 'playwright-report', 'test-results', 'blob-report', 'all-blob-reports'] },
  js.configs.recommended,
  ...tseslint.configs.recommended,
  {
    ...playwright.configs['flat/recommended'],
    files: ['tests/**/*.ts'],
    rules: {
      ...playwright.configs['flat/recommended'].rules,
      // Assertion ada di dalam Page Object (expectSaleCompleted, dll.)
      'playwright/expect-expect': ['warn', { assertFunctionNames: ['expectSaleCompleted', 'expectLoggedInAs'] }],
      'playwright/no-conditional-in-test': 'error',
      'playwright/no-wait-for-timeout': 'error',
    },
  },
  {
    files: ['src/fixtures/**/*.ts'],
    // Playwright mewajibkan destructuring object kosong untuk fixture tanpa dependensi.
    rules: { 'no-empty-pattern': 'off' },
  },
);
