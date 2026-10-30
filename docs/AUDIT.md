# Audit record

One audit file for this project. Every audit, check or review adds a dated section at the top; nothing is rewritten. Open work lives in `.agent/CURRENT-TASK.md`.

## 30 October 2026: release route and live parity

- Scope: the move to SSH releases (kit `scripts/release.mjs`) and whether the live server matches `main`.
- Evidence: `npm run release` dry run on `main` (30 Oct 2026): typecheck, lint, 123 Vitest tests, `test:php` 870 assertions all pass; build with the live Clerk key; `dist/` and `server/` identical to the live files (nothing to upload).
- Result: PASS. The FTP deploy workflow was removed; GitHub FTP secrets and the cPanel FTP account are deleted by the owner (`~/Backups/scripts/remove-ftp-deploys.zsh`).
- Not checked: a real `--go` release (nothing to upload yet), restore drill of the server backups.
