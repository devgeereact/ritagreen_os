# Decisions, 2026 build

The owner's decisions of 9 October 2026, which extended the lean CMS launch plan of
14 September 2026, and the decisions taken while building them. Each says where it landed. Pull
request numbers are into the build branch `lean-cms/build`; section state is the board in
[`.agent/CURRENT-TASK.md`](../.agent/CURRENT-TASK.md); release notes are in
[`docs/CHANGELOG.md`](CHANGELOG.md).

| Section | Pull request |
|---|---|
| S0 Plan pack and tracker | #9 |
| S1 Brand and shell | #10 |
| S2 People and compliance | #11 |
| S6 Website | #12 |
| S3 Rota | #13 |
| S7 Careers and CMS links | #14 |
| S4 Staff portal | #15 |
| S8 Spreadsheet import | #16 |
| S5 Today and Reports | #17 |
| S9 Retirements | #18 |
| S10 Launch gate and docs consolidation | branch `mvp/s10-launch-gate` |

## Owner decisions, 9 October 2026

| ID | Decision | Where it landed |
|---|---|---|
| D01 | One brand, **RitaGreen Services**, with four services named **Healthcare, Cleaning, Security, Training**. The public labels "Care" and "Academy" retire; the internal ids `care` and `academy` stay | S1 #10 (labels in `src/lib/services.ts`, the sidebar and filters); S6 #12 (website copy, `index.html`, `/verify`). [`docs/PRD.md`](PRD.md) §1 |
| D02 | Healthcare staffing leads. Supported living and home care get a page and an enquiry route. The app schedules and checks in care visits; it does not hold care plans or medication records | S6 #12 (`/healthcare`, `/healthcare/supported-living`, care enquiries to `contact.php`). [`docs/PRD.md`](PRD.md) §1 and §6 |
| D03 | Recruitment uses one Google Form per role, embedded on the role's page with an "open in a new tab" fallback. Each response reaches the app automatically, and an office user can import the response spreadsheet after review | S7 #14 (vacancies, role pages, forms directory, click to load, intake secrets, [`docs/runbooks/google-forms-intake.md`](runbooks/google-forms-intake.md)); S8 #16 (spreadsheet import). [`docs/BLUEPRINT.md`](BLUEPRINT.md) 2.5 and 2.6; [`docs/RULES.md`](RULES.md) rules 26 to 28 |
| D04 | The office app is rebuilt around what the company needs; extras get a keep, switch-off or retire verdict | S1 #10 (eight destinations, feature switches); S5 #17 (Today, one Reports page); S9 #18 (Content Studio, AI assistant, quiz builder, weather widget, Analytics and Insights retired, removal record in the changelog). [`docs/BLUEPRINT.md`](BLUEPRINT.md) 2.1 |
| D05 | New design in `#33512d` with warm neutrals, across website, office app and staff portal | S1 #10 (tokens, fonts, icons); S6 #12 (website); S4 #15 (staff portal). [`docs/DESIGN_SYSTEM.md`](DESIGN_SYSTEM.md) |
| D06 | Built section by section, each on its own branch and pull request, tracked on the section board | S0 #9 (board in `.agent/CURRENT-TASK.md`), then every section above |

## Decisions taken during the build

| Decision | Section | Where it landed |
|---|---|---|
| Company details and social links live in a one-row `company_settings` table, edited by the owner in Website, Company details; `src/lib/company.ts` stays as the fallback | S7 #14 | Migration 039, `server/company.php` |
| The **finance** switch hides the Finance pages in the office app only. The server does not refuse: invoicing, quotes, contracts and payroll keep their role and zone checks. Online payments and the inbox are refused on the server while off, and SMS and WhatsApp sends are logged as skipped | S1 #10 | Migration 036, `rg_feature_defaults()` in `server/pure.php` |
| Seeds keep production behaviour: finance, online payments, SMS and WhatsApp, and the inbox start **on**, so applying the migration changes nothing for the live office. The owner switches off what is not used (release runbook, section 8) | S1 #10 | Migration 036 |
| Spreadsheet import takes **CSV only**. A spreadsheet file is refused with the instruction to download the Google Sheet as CSV | S8 #16 | `src/pages/app/SheetImport.tsx`, `server/sheet-import.php` |
| A worker can clock in from **15 minutes before** the shift starts, and no earlier | S4 #15 | `RG_CLOCK_IN_LEAD_MINUTES` in `server/pure.php`, enforced in `server/field.php` |
| Migration numbers **040 and 041 are deliberately unused.** S8 took 042 while S4 and S5 might have needed 040 and 041; neither did (S5 took 043), and numbers are not reused or renumbered after the fact | S8 #16 | `ls server/migrations` |
| The minimum rest between shifts is a warning the office acknowledges, not a refusal; 11 hours by default, owner-editable in Settings, Features, "Rota rules" | S3 #13, screen in S10 | `app_settings.rest_gap_min_hours`, `scheduling.php?action=settings` |
| Deletion requests follow a four-eyes rule: the person who asks cannot decide. The rule predates 2026 in `server/governance.php`; S10 gave it a screen | S10 (screen) | Settings, Data requests |
| Staff, workers and portal clients sign in with **Clerk (email code only)**. Roles stay in `users`; the first sign-in links `users.clerk_user_id` by the verified email, an unknown email gets 401 and sign-up never creates a user. Google, passwords and the lock screen stay in the code until the new path is proven live and the owner confirms; the lock screen is off under Clerk. Owner instruction, 30 Oct 2026 | Clerk (2026-10-30) | Migration 044, `server/clerk-lib.php`, `src/auth/ClerkArea.tsx`, `src/pages/auth/ClerkLogin.tsx` |
