# GEE OS Adoption

- Project: ritagreen_os
- Minimum GEE OS version: 0.9.0 (contract refreshed 2026-10-08; adopted at 0.5.0)
- Current stage: the 2026 build (sections S0 to S10) is complete on the build branch and has not been released. The next contract is the production release, by the owner's decision, following [`docs/runbooks/release-2026.md`](runbooks/release-2026.md). Stage detail is in `.agent/PROJECT.yml`.

## Purpose

Give Claude Code, Codex and compatible agents one shared operating contract while preserving the project's existing knowledge and structure.

## Source map

| Concern | Source of truth |
|---|---|
| Shared agent entry | `AGENTS.md` |
| Standing traps and working rules, for every agent | `CLAUDE.md` |
| Codex mapping | `CODEX.md` |
| Routing, risk, stage | `.agent/PROJECT.yml` |
| Current scope and the section board | `.agent/CURRENT-TASK.md` |
| MCP authority | `.agent/MCP-PROFILE.yml` |
| Product purpose and boundaries | [`docs/PRD.md`](PRD.md) |
| Modules as built | [`docs/BLUEPRINT.md`](BLUEPRINT.md) |
| Owner and build decisions, 2026 | [`docs/DECISIONS-2026.md`](DECISIONS-2026.md) |
| Rules | [`docs/RULES.md`](RULES.md) |
| Architecture, API, schema | [`docs/ARCHITECTURE.md`](ARCHITECTURE.md), [`docs/API.md`](API.md), [`docs/SCHEMA.md`](SCHEMA.md) |
| Design | [`docs/DESIGN_SYSTEM.md`](DESIGN_SYSTEM.md) |
| Progress | `docs/STATUS.json`, rendered as [`docs/PROGRESS.md`](PROGRESS.md) |
| What remains | [`docs/ROADMAP.md`](ROADMAP.md) |
| Release, backup and intake procedures | `docs/runbooks/` |

## Guard hooks

Claude Code runs `scripts/agent_guard.py` from `.claude/settings.json`; Codex and other agents have no hooks and apply the same rules by hand ([`docs/RULES.md`](RULES.md), "Agent guard hooks").
