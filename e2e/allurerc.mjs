import { defineConfig } from 'allure';

// Satu dashboard untuk semua lapisan test PARTAMBUS:
//   epic "UI"  -> Playwright (e2e/)
//   epic "API" -> Postman + Newman (api-tests/)
//   epic "DB"  -> query SQL integritas & constraint (db-tests/)
//   epic "Performance" -> threshold k6 (perf-tests/)
export default defineConfig({
  name: 'PARTAMBUS QA Report',
  plugins: {
    awesome: {
      options: {
        reportName: 'PARTAMBUS QA Report',
        reportLanguage: 'en',
        groupBy: ['epic', 'suite', 'subSuite'],
      },
    },
  },
});
