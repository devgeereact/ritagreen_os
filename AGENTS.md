# ritagreen_os Agent Contract

This existing project adopts the active shared GEE OS, minimum version 0.9.0. Read `.agent/PROJECT.yml` and `.agent/CURRENT-TASK.md`, then apply the constitution, loop and routing rules at `~/.agents/gee-os`.

## Read order

1. `AGENTS.md`, this shared entry point.
2. `.agent/PROJECT.yml`, project routing, risk, current stage and sources of truth.
3. `.agent/CURRENT-TASK.md`, current scope, the section board and evidence.
4. `CLAUDE.md`, the project's standing traps and working rules. It applies to every agent, not only Claude Code.
5. `README.md`, then the relevant files under `docs/`: `docs/RULES.md` before changing anything, `docs/DESIGN_SYSTEM.md` before writing UI, `docs/ROADMAP.md` for what remains, `docs/runbooks/` for release, backup and form intake.

## Commands

- Install: `npm ci`
- Develop: `npm run dev` (port 5122). Local API: `npm run dev:api` (PHP on 127.0.0.1:8122)
- Type check: `npm run typecheck`
- Lint: `npm run lint` (gates at zero in CI, `--max-warnings 0`)
- Test: `npm test` (Vitest), `npm run test:php` (needs PHP) and `npm run test:db` (needs PHP and a local MariaDB; Docker works, see `docs/TESTING.md`)
- Build: `npm run build`
- Docs in step: `python3 scripts/docs_sync.py --check`

## Shared rules

- Select one primary mode and load only relevant systems, workflows, skills and MCP rules.
- Preserve established business rules, architecture and user work.
- Treat documentation as evidence to verify against real code and behaviour.
- Apply the change safety protocol before modifying existing behaviour.
- Never claim fixed, secure, accessible or ready without matching evidence.
- Keep remote and production changes outside scope unless the current user authorises them explicitly.
- MCP and skill availability never expands user authority.

## Guard hooks: Claude Code only

`.claude/settings.json` gives Claude Code a `PreToolUse` hook (`scripts/agent_guard.py`) that blocks an edit or write to `server/config.php`, a hand edit to `docs/PROGRESS.md`, and a push to `main`. **Codex and other agents have no hooks.** Apply the same three rules by hand: never read, print or write `server/config.php`; regenerate `docs/PROGRESS.md` with `python3 scripts/docs_sync.py` instead of editing it; never push to `main`, which deploys production. See `docs/RULES.md`, "Agent guard hooks".
