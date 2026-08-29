#!/usr/bin/env python3

from __future__ import annotations

import importlib.util
import os
import pathlib
import tempfile
import time
import unittest
from unittest import mock


ROOT = pathlib.Path(__file__).resolve().parents[2]
LOCAL_NODE_ID = "20000000-0000-4000-8000-000000000020"
REMOTE_NODE_ID = "20000000-0000-4000-8000-000000000200"
RESERVATION_ID = "10000000-0000-4000-8000-000000000001"


def load_router(root: pathlib.Path):
    control_secret = root / "router-control"
    control_secret.write_bytes(b"r" * 64)
    for node_id in (LOCAL_NODE_ID, REMOTE_NODE_ID):
        (root / f"node_{node_id}").write_bytes(b"n" * 64)
    os.environ["MOVIE_AI_ROUTER_SECRET_FILE"] = str(control_secret)
    os.environ["MOVIE_AI_ROUTER_STATE"] = str(root / "state.json")
    os.environ["MOVIE_NODE_SECRET_DIR"] = str(root)
    os.environ["MOVIE_ALLOWED_NODE_CIDRS"] = "192.168.88.0/24"
    os.environ["MOVIE_NODE_WORKER_PORT"] = "8080"
    os.environ["MOVIE_LOCAL_NODE_HOST"] = "movie-ai-broker"
    spec = importlib.util.spec_from_file_location(
        "movie_ai_router_test",
        ROOT / "images/control/ai_router.py",
    )
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(module)
    return module


class AiRouterTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temporary = tempfile.TemporaryDirectory()
        cls.router = load_router(pathlib.Path(cls.temporary.name))

    @classmethod
    def tearDownClass(cls):
        cls.temporary.cleanup()

    def setUp(self):
        self.router.save_state(self.router.default_state())

    def test_node_urls_are_private_fixed_port_and_never_arbitrary_hosts(self):
        self.assertEqual(
            "http://movie-ai-broker:8080",
            self.router.validated_node_url("http://movie-ai-broker:8080"),
        )
        self.assertEqual(
            "http://192.168.88.200:8080",
            self.router.validated_node_url("http://192.168.88.200:8080"),
        )
        for value in (
            "https://192.168.88.200:8080",
            "http://192.168.88.200:8188",
            "http://192.168.88.999:8080",
            "http://example.com:8080",
            "http://user:pass@192.168.88.200:8080",
            "http://192.168.88.200:8080/path",
        ):
            with self.subTest(value=value), self.assertRaises(ValueError):
                self.router.validated_node_url(value)

    def test_registration_binds_token_to_one_selected_node_without_fallback(self):
        token = "t" * 96
        handler = object.__new__(self.router.Handler)
        payload = {
            "reservation_id": RESERVATION_ID,
            "compute_node_id": REMOTE_NODE_ID,
            "token": token,
            "expires_at": int(time.time()) + 3600,
            "node_url": "http://192.168.88.200:8080",
        }
        with mock.patch.object(
            self.router,
            "signed_node_request",
            return_value=(200, b'{"registered":true}'),
        ) as request:
            handler.register(payload)

        request.assert_called_once()
        self.assertEqual(REMOTE_NODE_ID, request.call_args.args[0])
        self.assertEqual("http://192.168.88.200:8080", request.call_args.args[1])
        claim = self.router.active_claim(token)
        self.assertIsNotNone(claim)
        self.assertEqual(REMOTE_NODE_ID, claim["compute_node_id"])
        self.assertEqual("http://192.168.88.200:8080", claim["node_url"])

    def test_failed_selected_worker_does_not_create_a_router_claim(self):
        token = "x" * 96
        handler = object.__new__(self.router.Handler)
        payload = {
            "reservation_id": RESERVATION_ID,
            "compute_node_id": REMOTE_NODE_ID,
            "token": token,
            "expires_at": int(time.time()) + 3600,
            "node_url": "http://192.168.88.200:8080",
        }
        with mock.patch.object(
            self.router,
            "signed_node_request",
            return_value=(503, b'{"error":"worker_unavailable"}'),
        ), self.assertRaisesRegex(RuntimeError, "worker_unavailable"):
            handler.register(payload)

        self.assertIsNone(self.router.active_claim(token))

    def test_health_probe_uses_only_the_selected_validated_worker_url(self):
        handler = object.__new__(self.router.Handler)
        with mock.patch.object(
            self.router,
            "node_health_request",
            return_value={"ok": True, "compute_node_id": REMOTE_NODE_ID},
        ) as request:
            result = handler.node_health({
                "compute_node_id": REMOTE_NODE_ID,
                "node_url": "http://192.168.88.200:8080",
            })

        request.assert_called_once_with("http://192.168.88.200:8080")
        self.assertEqual(REMOTE_NODE_ID, result["compute_node_id"])

    def test_unprovisioned_node_cannot_pass_health_or_become_schedulable(self):
        handler = object.__new__(self.router.Handler)
        unprovisioned_node_id = "30000000-0000-4000-8000-000000000300"
        with self.assertRaises(OSError), mock.patch.object(
            self.router,
            "node_health_request",
        ) as request:
            handler.node_health({
                "compute_node_id": unprovisioned_node_id,
                "node_url": "http://192.168.88.30:8080",
            })
        request.assert_not_called()


if __name__ == "__main__":
    unittest.main()
