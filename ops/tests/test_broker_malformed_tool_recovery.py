#!/usr/bin/env python3

from __future__ import annotations

import contextlib
import importlib.util
import io
import json
import os
import pathlib
import tempfile
import unittest


ROOT = pathlib.Path(
    os.environ.get("MOVIE_BROKER_TEST_ROOT", pathlib.Path(__file__).resolve().parents[2])
)


def load_broker():
    temporary = tempfile.TemporaryDirectory()
    secret_root = pathlib.Path(temporary.name)
    broker_secret = secret_root / "broker.secret"
    manager_secret = secret_root / "manager.secret"
    broker_secret.write_bytes(b"b" * 32)
    manager_secret.write_bytes(b"m" * 32)
    os.environ["MOVIE_BROKER_SECRET_FILE"] = str(broker_secret)
    os.environ["MOVIE_BROKER_MANAGER_SECRET_FILE"] = str(manager_secret)
    os.environ["MOVIE_H3_STYLE_SKILLS_FILE"] = str(
        ROOT / "images/control/h3-style-skills.txt"
    )
    spec = importlib.util.spec_from_file_location(
        "movie_broker_malformed_tool_test", ROOT / "images/control/broker.py"
    )
    if spec is None or spec.loader is None:
        raise RuntimeError("unable to load broker module")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    module._test_temporary = temporary
    return module


BROKER = load_broker()


class MalformedToolRecoveryTest(unittest.TestCase):
    def setUp(self):
        with BROKER.MALFORMED_TOOL_CALL_LOCK:
            BROKER.MALFORMED_TOOL_CALLS_QUARANTINED = 0

    def test_quarantines_bad_call_and_matching_output_without_leaking_arguments(self):
        malformed = '{"cmd": "movie-ai h3 generate --spec /workspace/job.json'
        payload = {
            "model": BROKER.DEEPSEEK_MODEL,
            "instructions": "base",
            "input": [
                {"type": "message", "role": "user", "content": "start"},
                {
                    "type": "function_call",
                    "call_id": "call_bad",
                    "name": "exec_command",
                    "arguments": malformed,
                },
                {
                    "type": "function_call_output",
                    "call_id": "call_bad",
                    "output": "failed to parse function arguments",
                },
                {
                    "type": "function_call",
                    "call_id": "call_good",
                    "name": "exec_command",
                    "arguments": '{"cmd":"pwd"}',
                },
                {
                    "type": "function_call_output",
                    "call_id": "call_good",
                    "output": "/workspace",
                },
                {"type": "message", "role": "user", "content": "continue"},
            ],
        }

        logged = io.StringIO()
        with contextlib.redirect_stdout(logged):
            rewritten = BROKER.rewrite_deepseek_responses_payload(payload)

        self.assertEqual(
            [item.get("call_id") for item in rewritten["input"] if isinstance(item, dict)],
            [None, "call_good", "call_good", None],
        )
        self.assertNotIn(malformed, json.dumps(rewritten))
        self.assertIn(
            BROKER.MALFORMED_TOOL_CALL_RECOVERY_INSTRUCTION,
            rewritten["instructions"],
        )
        self.assertEqual(BROKER.malformed_tool_call_quarantine_count(), 1)
        self.assertIn("count=1 total=1", logged.getvalue())
        self.assertNotIn("movie-ai", logged.getvalue())

    def test_valid_history_is_preserved_without_recovery_instruction(self):
        payload = {
            "model": BROKER.DEEPSEEK_MODEL,
            "input": [
                {
                    "type": "function_call",
                    "call_id": "call_good",
                    "name": "exec_command",
                    "arguments": '{"cmd":"pwd"}',
                },
                {
                    "type": "function_call_output",
                    "call_id": "call_good",
                    "output": "/workspace",
                },
            ],
        }

        rewritten = BROKER.rewrite_deepseek_responses_payload(payload)

        self.assertEqual(rewritten["input"], payload["input"])
        self.assertNotIn(
            BROKER.MALFORMED_TOOL_CALL_RECOVERY_INSTRUCTION,
            rewritten["instructions"],
        )
        self.assertEqual(BROKER.malformed_tool_call_quarantine_count(), 0)

    def test_empty_non_object_and_missing_arguments_are_quarantined(self):
        raw_input = []
        for index, arguments in enumerate(("", "[]", None)):
            call_id = f"call_bad_{index}"
            call = {
                "type": "function_call",
                "call_id": call_id,
                "name": "exec_command",
            }
            if arguments is not None:
                call["arguments"] = arguments
            raw_input.extend(
                [
                    call,
                    {
                        "type": "function_call_output",
                        "call_id": call_id,
                        "output": "not executed",
                    },
                ]
            )

        filtered, count = BROKER.quarantine_malformed_function_call_history(raw_input)

        self.assertEqual(filtered, [])
        self.assertEqual(count, 3)

    def test_non_list_input_is_untouched(self):
        raw_input = "hello"
        filtered, count = BROKER.quarantine_malformed_function_call_history(raw_input)
        self.assertEqual(filtered, raw_input)
        self.assertEqual(count, 0)


if __name__ == "__main__":
    unittest.main()
