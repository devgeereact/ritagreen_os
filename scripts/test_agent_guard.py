#!/usr/bin/env python3
"""Tests for scripts/agent_guard.py, run the way Claude Code runs it.

    python3 scripts/test_agent_guard.py

Each case pipes the JSON a PreToolUse hook receives into the script and checks
the exit code: 2 blocks, 0 lets the call through. Branch-dependent cases run in a
throwaway git repository, so the real checkout is never touched.
"""

from __future__ import annotations

import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

GUARD = Path(__file__).resolve().parent / "agent_guard.py"


def run(tool: str, tool_input: dict, cwd: str | None = None) -> int:
    event = {"tool_name": tool, "tool_input": tool_input, "cwd": cwd or str(GUARD.parent)}
    return subprocess.run(
        [sys.executable, str(GUARD)], input=json.dumps(event), capture_output=True, text=True, cwd=cwd,
    ).returncode


def repo_on(branch: str) -> str:
    d = tempfile.mkdtemp(prefix="guard-test-")
    subprocess.run(["git", "init", "-q", "-b", branch, d], check=True)
    return d


class EditTests(unittest.TestCase):
    def test_config_php_cannot_be_written(self) -> None:
        self.assertEqual(run("Write", {"file_path": "/repo/server/config.php", "content": "x"}), 2)
        self.assertEqual(run("Edit", {"file_path": "server/config.php", "old_string": "a", "new_string": "b"}), 2)

    def test_the_sample_config_is_fine(self) -> None:
        self.assertEqual(run("Edit", {"file_path": "/repo/server/config.sample.php"}), 0)

    def test_progress_md_is_generated(self) -> None:
        self.assertEqual(run("Write", {"file_path": "/repo/docs/PROGRESS.md"}), 2)
        self.assertEqual(run("Edit", {"file_path": "/repo/docs/STATUS.json"}), 0)

    def test_shell_writes_to_config_are_blocked(self) -> None:
        self.assertEqual(run("Bash", {"command": "echo x > server/config.php"}), 2)
        self.assertEqual(run("Bash", {"command": "cp server/config.sample.php server/config.php"}), 2)
        self.assertEqual(run("Bash", {"command": "php -l server/lib.php"}), 0)

    def test_shell_writes_to_progress_are_blocked(self) -> None:
        self.assertEqual(run("Bash", {"command": "echo x >> docs/PROGRESS.md"}), 2)
        self.assertEqual(run("Bash", {"command": "python3 scripts/docs_sync.py"}), 0)


class PushTests(unittest.TestCase):
    def test_pushing_to_main_is_blocked(self) -> None:
        for cmd in ("git push origin main", "git push origin HEAD:main", "git push -f origin main",
                    "git push origin +main", "git push origin feature:refs/heads/main",
                    "npm test && git push origin main", "git -C /x push origin main", "git push --all origin"):
            with self.subTest(cmd=cmd):
                self.assertEqual(run("Bash", {"command": cmd}), 2)

    def test_pushing_a_branch_is_fine(self) -> None:
        for cmd in ("git push -u origin mvp/s10-launch-gate", "git push origin HEAD:mvp/s10-launch-gate",
                    "git push origin maintenance", "git status", "echo main"):
            with self.subTest(cmd=cmd):
                self.assertEqual(run("Bash", {"command": cmd}, repo_on("feature")), 0)

    def test_a_bare_push_on_main_is_blocked(self) -> None:
        self.assertEqual(run("Bash", {"command": "git push"}, repo_on("main")), 2)
        self.assertEqual(run("Bash", {"command": "git push origin HEAD"}, repo_on("main")), 2)
        self.assertEqual(run("Bash", {"command": "git push"}, repo_on("feature")), 0)


class RobustnessTests(unittest.TestCase):
    def test_unparseable_input_is_let_through(self) -> None:
        r = subprocess.run([sys.executable, str(GUARD)], input="not json", capture_output=True, text=True)
        self.assertEqual(r.returncode, 0)

    def test_other_tools_are_ignored(self) -> None:
        self.assertEqual(run("Read", {"file_path": "/repo/docs/PROGRESS.md"}), 0)


if __name__ == "__main__":
    unittest.main(verbosity=1)
