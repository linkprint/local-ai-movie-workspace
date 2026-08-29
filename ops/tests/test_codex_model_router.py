#!/usr/bin/env python3
"""Unit tests for the mixed OpenAI/Qwen loopback router."""

from __future__ import annotations

import importlib.util
import json
import os
import pathlib
import socket
import tempfile
import time
import tomllib
import unittest
from unittest import mock


ROOT = pathlib.Path(__file__).resolve().parents[2]
ROUTER_PATH = ROOT / "images/workspace/codex_model_router.py"
SPEC = importlib.util.spec_from_file_location("test_codex_model_router_module", ROUTER_PATH)
assert SPEC is not None and SPEC.loader is not None
ROUTER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(ROUTER)


class CodexModelRouterConnectionTest(unittest.TestCase):
    def test_catalog_adds_qwen_and_deepseek_once(self):
        source = json.dumps(
            {
                "models": [
                    {
                        "slug": "gpt-5.5",
                        "display_name": "GPT-5.5",
                        "description": "OpenAI",
                        "visibility": "list",
                        "model_messages": {"instructions_template": "base"},
                    }
                ]
            }
        ).encode()

        first = ROUTER.append_local_models_to_catalog(source)
        second = ROUTER.append_local_models_to_catalog(first)
        models = json.loads(second)["models"]

        self.assertEqual(
            [model["slug"] for model in models],
            ["gpt-5.5", ROUTER.QWEN_MODEL, ROUTER.DEEPSEEK_MODEL],
        )
        self.assertEqual(models[2]["display_name"], "DeepSeek V4 Flash 0731 Uncensored (External)")
        self.assertEqual(models[2]["context_window"], 500000)
        self.assertFalse(models[2]["supports_search_tool"])

    def test_local_ai_grant_is_absent_until_a_matching_unexpired_file_exists(self):
        runtime_id = "12345678-1234-4123-8123-123456789abc"
        reservation_id = "87654321-4321-4123-8123-cba987654321"
        with tempfile.TemporaryDirectory() as temporary:
            grant_file = pathlib.Path(temporary) / "grant.json"
            with mock.patch.object(ROUTER, "GRANT_FILE", grant_file), \
                 mock.patch.object(ROUTER, "RUNTIME_ID", runtime_id), \
                 mock.patch.object(ROUTER, "RUNTIME_GENERATION", 7):
                self.assertIsNone(ROUTER.active_grant())
                grant_file.write_text(
                    '{"enabled":true,"runtime_id":"%s","generation":7,'
                    '"reservation_id":"%s","expires_at":%d,"token":"%s"}'
                    % (runtime_id, reservation_id, int(time.time()) + 60, "t" * 96),
                    encoding="utf-8",
                )
                self.assertEqual(ROUTER.active_grant()["runtime_id"], runtime_id)

    def test_wrong_runtime_generation_or_expired_local_ai_grant_is_denied(self):
        runtime_id = "12345678-1234-4123-8123-123456789abc"
        reservation_id = "87654321-4321-4123-8123-cba987654321"
        with tempfile.TemporaryDirectory() as temporary:
            grant_file = pathlib.Path(temporary) / "grant.json"
            with mock.patch.object(ROUTER, "GRANT_FILE", grant_file), \
                 mock.patch.object(ROUTER, "RUNTIME_ID", runtime_id), \
                 mock.patch.object(ROUTER, "RUNTIME_GENERATION", 7):
                for candidate_runtime, generation, expiry in (
                    (reservation_id, 7, int(time.time()) + 60),
                    (runtime_id, 8, int(time.time()) + 60),
                    (runtime_id, 7, int(time.time()) - 1),
                ):
                    grant_file.write_text(
                        '{"enabled":true,"runtime_id":"%s","generation":%d,'
                        '"reservation_id":"%s","expires_at":%d,"token":"%s"}'
                        % (candidate_runtime, generation, reservation_id, expiry, "t" * 96),
                        encoding="utf-8",
                    )
                    self.assertIsNone(ROUTER.active_grant())

    def test_client_disconnect_closes_inflight_upstream(self):
        client, peer = socket.socketpair()
        upstream = mock.Mock()
        cancellation = ROUTER.ClientDisconnectCancellation(client)
        cancellation.attach_connection(upstream)
        cancellation.start()
        try:
            peer.close()
            self.assertTrue(cancellation.cancelled.wait(timeout=2))
            upstream.close.assert_called()
        finally:
            cancellation.stop()
            client.close()

    def test_movie_provider_is_not_misclassified_as_openai(self):
        config = tomllib.loads(
            (ROOT / "images/workspace/movie.config.toml").read_text(encoding="utf-8")
        )
        provider = config["model_providers"]["movie_router"]
        self.assertEqual(provider["name"], "Movie Router")
        self.assertTrue(provider["requires_openai_auth"])

    def test_broker_origin_defaults_to_v1_responses_surface(self):
        self.assertEqual(
            ROUTER.broker_upstream_path("", "/responses"), "/v1/responses"
        )
        self.assertEqual(
            ROUTER.broker_upstream_path("/v1", "/responses"), "/v1/responses"
        )

    @mock.patch.object(ROUTER.http.client, "HTTPSConnection")
    def test_openai_connection_uses_workspace_egress_connect(self, connection_class):
        connection = connection_class.return_value
        environment = {
            "MOVIE_CODEX_HTTPS_PROXY": "http://movie-egress:3128",
            "HTTPS_PROXY": "http://ignored.example:9999",
        }

        with mock.patch.dict(os.environ, environment, clear=True):
            result = ROUTER.openai_connection("chatgpt.com")

        self.assertIs(result, connection)
        connection_class.assert_called_once_with("movie-egress", 3128, timeout=310)
        connection.set_tunnel.assert_called_once_with("chatgpt.com", 443)

    @mock.patch.object(ROUTER.http.client, "HTTPSConnection")
    def test_openai_connection_keeps_direct_fallback_outside_workspace(
        self, connection_class
    ):
        with mock.patch.dict(os.environ, {}, clear=True):
            ROUTER.openai_connection("api.openai.com")

        connection_class.assert_called_once_with("api.openai.com", 443, timeout=310)
        connection_class.return_value.set_tunnel.assert_not_called()

    def test_openai_connection_rejects_credentialed_or_non_http_proxy(self):
        invalid_proxies = (
            "https://movie-egress:3128",
            "http://user:secret@movie-egress:3128",
            "not-a-url",
        )
        for proxy in invalid_proxies:
            with self.subTest(proxy=proxy):
                with mock.patch.dict(
                    os.environ, {"MOVIE_CODEX_HTTPS_PROXY": proxy}, clear=True
                ):
                    with self.assertRaisesRegex(
                        ValueError, "movie_egress_proxy_invalid"
                    ):
                        ROUTER.openai_connection("chatgpt.com")


if __name__ == "__main__":
    unittest.main()
