#!/usr/bin/env python3
"""PreToolUse guard for Claude Code in this repository (.claude/settings.json).

Blocks three things that cause real harm here, and nothing else:

  1. Editing or writing server/config.php. It holds every live secret, is
     gitignored, and is never read, printed or created by an agent.
  2. Hand-editing docs/PROGRESS.md. It is generated from docs/STATUS.json by
     `python3 scripts/docs_sync.py`; a hand edit is overwritten or fails CI.
  3. Pushing to main. Pushing to main deploys production (deploy.yml).

Claude Code sends the tool call as JSON on stdin. Exit 2 blocks the call and the
message on stderr is shown to the agent; exit 0 lets it through. Anything this
script cannot parse is let through, because a guard that breaks every tool call
gets switched off.

Codex and other agents have no hooks: the same three rules apply to them by hand
(AGENTS.md, docs/RULES.md).

Test it with sample input, for example:
    echo '{"tool_name":"Bash","tool_input":{"command":"git push origin main"}}' | python3 scripts/agent_guard.py
"""

from __future__ import annotations

import json
import re
import shlex
import subprocess
import sys

EDIT_TOOLS = {"Edit", "Write", "MultiEdit", "NotebookEdit"}

CONFIG = re.compile(r"(^|/)server/config\.php$")
PROGRESS = re.compile(r"(^|/)docs/PROGRESS\.md$")
# A shell write whose destination is server/config.php.
SHELL_CONFIG_WRITE = re.compile(
    r"(>>?|\btee\b(\s+-a)?|\bcp\b|\bmv\b|\binstall\b|\bln\b|\bsed\s+-i\S*)\s+[^;&|]*server/config\.php\b"
)
# A shell write whose destination is docs/PROGRESS.md (docs_sync.py is the way).
SHELL_PROGRESS_WRITE = re.compile(r"(>>?|\btee\b(\s+-a)?|\bsed\s+-i\S*)\s+[^;&|]*docs/PROGRESS\.md\b")


def block(message: str) -> None:
    print(message, file=sys.stderr)
    sys.exit(2)


def current_branch(cwd: str | None) -> str | None:
    try:
        out = subprocess.run(
            ["git", "symbolic-ref", "--quiet", "--short", "HEAD"],
            cwd=cwd or None, capture_output=True, text=True, timeout=5, check=False,
        )
    except (OSError, subprocess.SubprocessError):
        return None
    return out.stdout.strip() or None


def pushes_to_main(command: str, cwd: str | None) -> bool:
    """Does any `git push` in this command line target main?"""
    for segment in re.split(r"&&|\|\||;|\|", command):
        try:
            words = shlex.split(segment)
        except ValueError:
            words = segment.split()
        if "git" not in words:
            continue
        rest = words[words.index("git") + 1:]
        # Skip git's own options (-C dir, -c key=value, --no-pager ...).
        while rest and rest[0].startswith("-"):
            rest = rest[2:] if rest[0] in ("-C", "-c") else rest[1:]
        if not rest or rest[0] != "push":
            continue
        args = [a for a in rest[1:] if not a.startswith("-")]
        if any(a == "--all" or a == "--mirror" for a in rest[1:]):
            return True
        refspecs = args[1:]  # args[0] is the remote
        for ref in refspecs:
            dest = ref.split(":")[-1].lstrip("+")
            if dest in ("main", "refs/heads/main"):
                return True
            if ":" not in ref and ref.lstrip("+") == "HEAD" and current_branch(cwd) == "main":
                return True
        # `git push` or `git push origin` with no refspec pushes the current branch.
        if not refspecs and current_branch(cwd) == "main":
            return True
    return False


def main() -> int:
    try:
        event = json.load(sys.stdin)
    except (json.JSONDecodeError, ValueError):
        return 0
    tool = event.get("tool_name", "")
    data = event.get("tool_input") or {}
    cwd = event.get("cwd")

    if tool in EDIT_TOOLS:
        path = str(data.get("file_path") or data.get("notebook_path") or "")
        if CONFIG.search(path):
            block("Blocked: server/config.php holds the live secrets and is never edited or created "
                  "by an agent. Change server/config.sample.php (placeholders only) instead.")
        if PROGRESS.search(path):
            block("Blocked: docs/PROGRESS.md is generated. Edit docs/STATUS.json, then run "
                  "python3 scripts/docs_sync.py.")
        return 0

    if tool == "Bash":
        command = str(data.get("command") or "")
        if SHELL_CONFIG_WRITE.search(command):
            block("Blocked: this command writes server/config.php, which holds the live secrets.")
        if SHELL_PROGRESS_WRITE.search(command):
            block("Blocked: docs/PROGRESS.md is generated. Run python3 scripts/docs_sync.py.")
        if pushes_to_main(command, cwd):
            block("Blocked: pushing to main deploys production. Push a branch and open a pull "
                  "request; merging to main is the owner's release decision.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
