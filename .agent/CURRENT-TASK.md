# Current Task Contract

## Outcome

Verification and correctness pass over the whole application: establish what it actually does,
fix what is broken, and bring every document back into step with the code.

Superseded: the GEE OS 0.5.0 adoption contract this file previously carried. That work is done —
`AGENTS.md`, `.agent/PROJECT.yml` and this file exist, and no application behaviour was changed
by it.

## Scope

- Verify implemented behaviour against a local database, by exercising it rather than reading it.
- Fix defects found, smallest cohesive change first, root cause before symptom.
- Keep `docs/`, `CLAUDE.md`, `README.md` and `server/README.md` accurate as the code changes.
- Commit on `privacy/readiness`, push the branch, keep the pull request green.

## Outside scope

- Applying migrations to the production database.
- Uploading PHP to the live host, and merging to `main` — which deploys.
- Any change to production data, external services or third-party accounts.
- New features. A defect found is fixed; a feature imagined is written down instead.

## Mode and workflow

- Primary mode: Existing Application.
- Workflow: Change Safety. Inspect the artefact, change the smallest thing that fixes the root
  cause, verify by exercising it, record the evidence.

## Evidence

- HTTP permission matrix across every endpoint and every role, before and after.
- Journeys exercised end to end against a seeded local database, not mocked.
- `npm run typecheck`, `npm run lint -- --max-warnings 0`, `npm test`, `npm run test:php`,
  `npm run build`, `python3 scripts/docs_sync.py --check` — all green, all blocking in CI.
- Every claim in the final report traceable to a command that was run.

## Completion

The critical journeys are verified, the defects found are fixed and retested, the documentation
matches the code, and what remains unverified is stated plainly — including everything that
needs production access, which this contract does not grant.
