#!/usr/bin/env python3

from __future__ import annotations

import hashlib
import hmac
import importlib.util
import json
import os
import pathlib
import tempfile
import time
import unittest
from base64 import urlsafe_b64encode


ROOT = pathlib.Path(__file__).resolve().parents[2]


def load_module(secret: pathlib.Path):
    os.environ["MOVIE_TERMINAL_ROUTER_SECRET_FILE"] = str(secret)
    spec = importlib.util.spec_from_file_location(
        "movie_terminal_router_under_test",
        ROOT / "images/control/terminal_router.py",
    )
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(module)
    return module


class TerminalRouterTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.secret = pathlib.Path(self.temporary.name) / "router-secret"
        self.secret.write_bytes(b"r" * 64)
        self.module = load_module(self.secret)
        self.runtime_id = "12345678-1234-4123-8123-123456789abc"
        self.user_id = "87654321-4321-4123-8123-cba987654321"

    def tearDown(self):
        self.temporary.cleanup()

    def claim(self, **overrides):
        payload = {
            "aud": "movie-terminal-router",
            "sub": self.user_id,
            "runtime_id": self.runtime_id,
            "generation": 3,
            "exp": int(time.time()) + 25,
            "nonce": "n" * 48,
            **overrides,
        }
        encoded = urlsafe_b64encode(
            json.dumps(payload, separators=(",", ":")).encode()
        ).decode().rstrip("=")
        signature = hmac.new(b"r" * 64, encoded.encode(), hashlib.sha256).hexdigest()
        return f"{encoded}.{signature}"

    def test_valid_claim_routes_only_to_its_runtime_container(self):
        decoded = self.module.decode_claim(self.claim())
        self.assertEqual(decoded["sub"], self.user_id)
        self.assertEqual(decoded["generation"], 3)
        self.assertEqual(
            self.module.upstream_host(decoded),
            f"movie-ws-{self.runtime_id}",
        )

    def test_tampered_expired_and_long_lived_claims_are_rejected(self):
        tampered = self.claim()[:-1] + ("0" if self.claim()[-1] != "0" else "1")
        with self.assertRaisesRegex(ValueError, "route_claim_invalid"):
            self.module.decode_claim(tampered)
        with self.assertRaisesRegex(ValueError, "route_expired"):
            self.module.decode_claim(self.claim(exp=int(time.time()) - 1))
        with self.assertRaisesRegex(ValueError, "route_expired"):
            self.module.decode_claim(self.claim(exp=int(time.time()) + 31))

    def test_claim_cannot_supply_an_arbitrary_upstream_hostname(self):
        decoded = self.module.decode_claim(self.claim(upstream="attacker.example"))
        self.assertNotIn("upstream", self.module.upstream_host(decoded))
        self.assertEqual(
            self.module.upstream_host(decoded),
            f"movie-ws-{self.runtime_id}",
        )


if __name__ == "__main__":
    unittest.main()
