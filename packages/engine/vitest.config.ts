import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    include: ['src/**/*.test.ts', 'tests/**/*.test.ts'],
    coverage: {
      provider: 'v8',
      include: ['src/core/**/*.ts', 'src/trace/**/*.ts', 'src/formula/**/*.ts', 'src/cost/**/*.ts', 'src/batch/**/*.ts', 'src/active/**/*.ts', 'src/neutralize/**/*.ts', 'src/soap/**/*.ts', 'src/sourced-input.ts', 'src/base-equivalent.ts', 'src/calculate.ts'],
      exclude: ['src/**/*.test.ts', 'src/test-fixtures.ts'],
      reporter: ['text', 'json-summary'],
      thresholds: {
        // Core stays at 100%; calculation modules must stay at or above 95%.
        'src/core/**': { lines: 100, branches: 100, functions: 100, statements: 100 },
        'src/{trace,formula,cost,batch,active,neutralize,soap}/**': { lines: 95, branches: 90, functions: 95, statements: 95 },
        'src/{sourced-input,base-equivalent}.ts': { lines: 95, branches: 90, functions: 95, statements: 95 },
        'src/calculate.ts': { lines: 95, branches: 90, functions: 95, statements: 95 },
      },
    },
  },
});
