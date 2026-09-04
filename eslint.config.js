import js from '@eslint/js'
import globals from 'globals'
import reactHooks from 'eslint-plugin-react-hooks'
import reactRefresh from 'eslint-plugin-react-refresh'
import tseslint from 'typescript-eslint'
import unusedImports from 'eslint-plugin-unused-imports' // ✅ ADD THIS
import { defineConfig, globalIgnores } from 'eslint/config'

export default defineConfig([
  globalIgnores(['dist']),
  {
    files: ['**/*.{ts,tsx}'],
    extends: [
      js.configs.recommended,
      tseslint.configs.recommended,
      reactHooks.configs.flat.recommended,
      reactRefresh.configs.vite,
    ],
    plugins: {
      'unused-imports': unusedImports, // ✅ ADD THIS
    },
    rules: {
      // ❌ disable default rule
      '@typescript-eslint/no-unused-vars': 'off',

      // ✅ remove unused imports automatically
      'unused-imports/no-unused-imports': 'error',

      // ✅ handle unused variables (optional but clean)
      'unused-imports/no-unused-vars': [
        'warn',
        {
          vars: 'all',
          varsIgnorePattern: '^_',
          args: 'after-used',
          argsIgnorePattern: '^_',
        },
      ],
    },
    languageOptions: {
      ecmaVersion: 2020,
      globals: globals.browser,
    },
  },
  {
    // Vendored shadcn/ui primitives.
    //
    // `react-refresh/only-export-components` fires on every one of these because
    // shadcn's own design puts the component and its variant helper in one file
    // — `Button` beside `buttonVariants`, `badgeVariants`, `toggleVariants`. The
    // rule is about hot-reload granularity during development, not correctness,
    // and splitting upstream files to satisfy it would mean re-doing the split
    // by hand on every `shadcn add`.
    //
    // Scoped off HERE rather than disabled globally, so the rule still applies
    // to code we write. Nothing else is relaxed for these files.
    files: ['src/components/ui/**/*.{ts,tsx}'],
    rules: {
      'react-refresh/only-export-components': 'off',
    },
  },
])