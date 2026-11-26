// ESLint, "flat" configuration: browser JavaScript (assets/js), tests (e2e, unit) and Node tools.
// Formatting belongs to Prettier (.prettierrc.json): eslint-config-prettier turns off the rules about it.
import js from '@eslint/js';
import prettier from 'eslint-config-prettier';
import globals from 'globals';

export default [
    {
        ignores: [
            'assets/vendor/**',
            'public/**',
            'vendor/**',
            'var/**',
            'node_modules/**',
            'documentation/site/**',
            'documentation/.cache/**',
            'playwright-report/**',
            'test-results/**',
            'reports/**',
            'release/**',
            'image/**',
        ],
    },
    js.configs.recommended,
    {
        // Classic scripts loaded by the browser (AssetMapper without importmap); Chart.js is global.
        files: ['assets/js/**/*.js'],
        languageOptions: {
            ecmaVersion: 2022,
            sourceType: 'script',
            globals: { ...globals.browser, Chart: 'readonly' },
        },
    },
    {
        // Root of the online documentation (language choice): classic browser script.
        files: ['documentation/racine/*.js'],
        languageOptions: { ecmaVersion: 2022, sourceType: 'script', globals: { ...globals.browser } },
    },
    {
        files: ['**/*.mjs', 'tests/**/*.js'],
        languageOptions: { ecmaVersion: 2024, sourceType: 'module', globals: { ...globals.node, ...globals.browser } },
    },
    {
        // k6 load scenarios: k6 global variables.
        files: ['tests/perf/**/*.js'],
        languageOptions: { globals: { __ENV: 'readonly', __VU: 'readonly', __ITER: 'readonly' } },
    },
    {
        rules: {
            eqeqeq: 'error',
            'no-var': 'error',
            'prefer-const': 'error',
            'no-implicit-globals': 'error',
            'no-unused-vars': ['error', { argsIgnorePattern: '^_', caughtErrors: 'none' }],
        },
    },
    prettier,
];
