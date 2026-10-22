# Codex Adapter

Read `AGENTS.md`, `.agent/PROJECT.yml` and `.agent/CURRENT-TASK.md` first. This project follows the shared GEE OS at `~/.agents/gee-os`.

Use Codex skills, plugins and MCP tools only when selected by the project route and relevant to the current task. Confirm the repository, account and environment before relying on live information. Do not infer remote or production authority from tool availability.

Project facts live in `CLAUDE.md` (standing traps, which apply to every agent), `README.md`, the code and `docs/`. Design is `docs/DESIGN_SYSTEM.md`; what remains is `docs/ROADMAP.md`.

Codex has no guard hooks. The three rules Claude Code's hook enforces apply here by hand: do not touch `server/config.php`, do not hand-edit `docs/PROGRESS.md`, do not push to `main` (`AGENTS.md`, "Guard hooks").
