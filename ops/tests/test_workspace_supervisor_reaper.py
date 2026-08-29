#!/usr/bin/env python3
"""Process-ownership tests for the Workspace Codex reaper."""

from __future__ import annotations

import importlib.util
import pathlib
import signal
import sys
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location(
    "workspace_supervisor_reaper_test", ROOT / "images/workspace/supervisor.py"
)
assert SPEC is not None and SPEC.loader is not None
SUPERVISOR = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = SUPERVISOR
SPEC.loader.exec_module(SUPERVISOR)


def process(
    pid: int,
    ppid: int,
    pgrp: int,
    *argv: str,
    start_ticks: int | None = None,
):
    return SUPERVISOR.ProcessInfo(
        pid=pid,
        ppid=ppid,
        pgrp=pgrp,
        start_ticks=pid if start_ticks is None else start_ticks,
        argv=tuple(argv),
    )


class WorkspaceCodexReaperTest(unittest.TestCase):
    def process_table(self):
        entries = (
            process(1, 0, 1, "/sbin/docker-init"),
            process(
                7,
                1,
                1,
                "python3",
                "/usr/local/bin/movie-workspace-supervisor",
            ),
            process(66, 1, 66, "tmux", "new-session"),
            process(
                67,
                66,
                67,
                "python3",
                "/usr/local/bin/movie-personal-codex-session",
            ),
            process(94, 67, 67, "node", "/usr/local/bin/codex"),
            process(106, 94, 67, "/opt/vendor/bin/codex", "--profile"),
            process(37, 0, 37, "node", "/usr/local/bin/codex", start_ticks=100),
            process(53, 37, 37, "/opt/vendor/bin/codex", "exec"),
            process(400, 0, 400, "sh", "-lc", start_ticks=200),
            process(414, 400, 400, "node", "/usr/local/bin/codex"),
            # A detached exec leader can be adopted by PID 1; it is still not
            # below either managed Codex session wrapper.
            process(430, 1, 430, "node", "/usr/local/bin/codex"),
            # Missing ancestry is ignored rather than risking the user session.
            process(500, 499, 500, "node", "/usr/local/bin/codex"),
        )
        return {entry.pid: entry for entry in entries}

    def test_only_unmanaged_exec_groups_are_selected(self):
        groups = SUPERVISOR.unmanaged_codex_groups(
            self.process_table(), protected_pgrps={999}
        )
        self.assertEqual(set(groups.values()), {37, 400, 430})
        self.assertNotIn(67, groups.values())
        self.assertNotIn(500, groups.values())

    def test_reaper_escalates_after_grace_and_forgets_exited_groups(self):
        calls: list[tuple[int, int]] = []
        reaper = SUPERVISOR.UnmanagedCodexReaper(
            grace_seconds=15,
            term_seconds=5,
            signal_group=lambda group, sent_signal: calls.append((group, sent_signal)),
        )
        table = {
            37: process(37, 0, 37, "node", "/usr/local/bin/codex", start_ticks=100)
        }

        reaper.tick(now=0, processes=table)
        reaper.tick(now=14.9, processes=table)
        self.assertEqual(calls, [])
        reaper.tick(now=15, processes=table)
        self.assertEqual(calls, [(37, signal.SIGTERM)])
        reaper.tick(now=19.9, processes=table)
        self.assertEqual(len(calls), 1)
        reaper.tick(now=20, processes=table)
        self.assertEqual(calls[-1], (37, signal.SIGKILL))

        reaper.tick(now=21, processes={})
        self.assertEqual(reaper.states, {})


if __name__ == "__main__":
    unittest.main()
