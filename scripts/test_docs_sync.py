#!/usr/bin/env python3
"""Tests for the consistency guards in scripts/docs_sync.py.

    python3 scripts/test_docs_sync.py

Each test builds a small tree in a temporary folder, so nothing in the
repository is read or changed.
"""

from __future__ import annotations

import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import docs_sync  # noqa: E402


def tree(files: dict[str, str]) -> Path:
    root = Path(tempfile.mkdtemp(prefix="docs-sync-test-"))
    for rel, text in files.items():
        path = root / rel
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(text, encoding="utf-8")
    return root


class LinkTests(unittest.TestCase):
    def test_a_good_relative_link_passes(self) -> None:
        root = tree({"docs/A.md": "See [B](B.md#part).", "docs/B.md": "# B"})
        self.assertEqual(docs_sync.check_links(root), [])

    def test_a_broken_link_is_reported_with_its_line(self) -> None:
        root = tree({"docs/A.md": "one\n[gone](Plans%202026/04-APP-DESIGN.md)\n"})
        problems = docs_sync.check_links(root)
        self.assertEqual(len(problems), 1)
        self.assertIn("docs/A.md:2", problems[0])

    def test_encoded_spaces_resolve(self) -> None:
        root = tree({"docs/A.md": "[pack](Plans%202026/README.md)", "docs/Plans 2026/README.md": "x"})
        self.assertEqual(docs_sync.check_links(root), [])

    def test_external_anchor_and_site_paths_are_skipped(self) -> None:
        root = tree({"README.md": "[w](https://example.com) [h](#top) [r](/careers) [m](mailto:a@b.c)"})
        self.assertEqual(docs_sync.check_links(root), [])

    def test_links_in_code_are_not_links(self) -> None:
        root = tree({"docs/A.md": "```\n[x](missing.md)\n```\nand `[y](missing.md)`"})
        self.assertEqual(docs_sync.check_links(root), [])

    def test_root_agent_and_server_readme_are_checked(self) -> None:
        root = tree({
            "AGENTS.md": "[a](nope.md)",
            ".agent/CURRENT-TASK.md": "[b](nope.md)",
            "server/README.md": "[c](nope.md)",
            "src/notes.md": "[d](nope.md)",
        })
        problems = "\n".join(docs_sync.check_links(root))
        self.assertIn("AGENTS.md", problems)
        self.assertIn(".agent/CURRENT-TASK.md", problems)
        self.assertIn("server/README.md", problems)
        self.assertNotIn("src/notes.md", problems)


class ColourTests(unittest.TestCase):
    def test_a_retired_colour_in_src_fails(self) -> None:
        root = tree({"src/x.css": ":root { --brand: #0c447c; }"})
        self.assertEqual(len(docs_sync.check_retired_colours(root)), 1)

    def test_in_docs_too(self) -> None:
        root = tree({"docs/DESIGN.md": "Accent #5DCAA5 for links."})
        self.assertEqual(len(docs_sync.check_retired_colours(root)), 1)

    def test_a_line_naming_it_retired_is_allowed(self) -> None:
        root = tree({"docs/DESIGN.md": "Navy #0C447C and mint #5DCAA5 are retired."})
        self.assertEqual(docs_sync.check_retired_colours(root), [])

    def test_the_changelog_is_history(self) -> None:
        root = tree({"docs/CHANGELOG.md": "Was #0C447C."})
        self.assertEqual(docs_sync.check_retired_colours(root), [])

    def test_other_folders_are_not_scanned(self) -> None:
        root = tree({"public/old.css": "#0C447C"})
        self.assertEqual(docs_sync.check_retired_colours(root), [])

    def test_the_colour_with_an_alpha_channel_is_still_the_colour(self) -> None:
        root = tree({"src/x.css": "#0C447C80 is navy at half strength"})
        self.assertEqual(len(docs_sync.check_retired_colours(root)), 1)


if __name__ == "__main__":
    unittest.main(verbosity=1)
