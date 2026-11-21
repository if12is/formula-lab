import tseslint from 'typescript-eslint';

export default tseslint.config(
  {
    ignores: [
      '**/node_modules/**', '**/dist/**', '**/coverage/**', 'apps/api/**',
      'playwright-report/**', 'test-results/**', 'apps/desktop/**', 'apps/mobile/**',
    ],
  },
  ...tseslint.configs.recommended,
  {
    // Engine determinism: no wall-clock, no randomness (CLAUDE.md rule 1).
    files: ['packages/engine/src/**/*.ts'],
    ignores: ['**/*.test.ts'],
    rules: {
      'no-restricted-properties': [
        'error',
        { object: 'Date', property: 'now', message: 'Engine must be deterministic: no Date.now.' },
        { object: 'Math', property: 'random', message: 'Engine must be deterministic: no Math.random.' },
      ],
      'no-restricted-syntax': [
        'error',
        { selector: "NewExpression[callee.name='Date']", message: 'Engine must be deterministic: no new Date().' },
        { selector: "CallExpression[callee.name='parseFloat']", message: 'No float math in engine: use decimal.js.' },
        { selector: "CallExpression[callee.name='Number']", message: 'No float math in engine: use decimal.js.' },
      ],
      'no-restricted-globals': [
        'error',
        { name: 'parseFloat', message: 'No float math in engine: use decimal.js.' },
      ],
    },
  },
);
