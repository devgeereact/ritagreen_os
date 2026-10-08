# Current Task Contract

## Outcome

Audit RitaGreen OS against the applicable GEE OS 0.9.0 standards and apply safe, reversible local improvements, preserving its purpose, working behaviour, data and existing work.

## Mode

Existing Application. Workflow: Existing Application, with the Change Safety protocol for each edit.

## Scope

- In scope: baseline inspection and checks, findings ranked P0 to P4, small local fixes to code, documentation and agent instructions, reports under `../reports/ritagreen-os/`.
- Out of scope: deletions, dependency or runtime changes, database migrations, authentication, payment or business-rule changes, deployment, commits and pushes, production or external mutations.

## Authority

- Research: yes, read-only.
- Create planning artefacts: yes, under `../reports/ritagreen-os/`.
- Edit local files: safe, reversible changes only.
- Use external services: no.
- Change production: no.
- MCP reads: documentation lookups only.
- MCP mutations: no.

## Risk and dependencies

- Risk level: production. Pushing `main` deploys `dist/` by FTPS; PHP files are uploaded by hand. Holds worker PII, right-to-work and payroll data.
- Affected areas: React SPA (`src/`), PWA configuration (`vite.config.ts`), PHP API (`server/`), project documentation.
- Required decisions: listed in `../reports/ritagreen-os/AUDIT-AND-POLISH-2026-10-08.md`.

## Evidence

- Required checks: `npm run typecheck`, `npm run lint` (19-error known baseline), `npm test`, `npm run build`, `npm run test:php` (needs PHP), manual review of changed files.
- Completion conditions: every change verified or marked NOT TESTED, project report and workspace summary updated, no unrelated work altered.

## Previous task

GEE OS 0.5.0 local adoption (wave 1) is complete. Its record is `docs/GEE-OS.md`.
