#!/usr/bin/env python3

from __future__ import annotations

import argparse
import importlib.util
import pathlib
import unittest
from unittest import mock


ROOT = pathlib.Path(__file__).resolve().parents[2]
CLI_PATH = ROOT / "images/workspace/movie-ai.py"
SPEC = importlib.util.spec_from_file_location("movie_ai_image_style", CLI_PATH)
CLI = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(CLI)

MODEL = "svdq-fp4_r32-flux.1-krea-dev.safetensors"


class MovieAiImageStyleTest(unittest.TestCase):
    def args(self, **overrides: object) -> argparse.Namespace:
        values = {
            "list": False,
            "model": MODEL,
            "prompt": "A cinematic lighthouse at blue hour",
            "width": 1024,
            "height": 1024,
            "seed": 7,
            "output": None,
        }
        values.update(overrides)
        return argparse.Namespace(**values)

    def test_noninteractive_style_waits_downloads_and_returns_url(self) -> None:
        catalog = {"models": [{"id": MODEL, "display_name": "FLUX.1 Krea Dev"}]}
        completed = {"job": {"id": "12345678-1234-4123-8123-123456789abc", "status": "completed"}}
        artifact = {"output": "/outputs/result.jpg", "sha256": "a" * 64, "url": "https://example.test/result"}
        with (
            mock.patch.object(CLI, "request", side_effect=[catalog, {"job": {"id": completed["job"]["id"]}}]) as request,
            mock.patch.object(CLI, "wait_for_job", return_value=completed) as wait,
            mock.patch.object(CLI, "download_job", return_value=artifact) as download,
        ):
            result = CLI.image_style(self.args())

        request.assert_any_call("POST", "/v1/image/style/jobs", {
            "model": MODEL,
            "prompt": "A cinematic lighthouse at blue hour",
            "width": 1024,
            "height": 1024,
            "seed": 7,
        }, timeout=120)
        wait.assert_called_once_with(completed["job"]["id"])
        output = download.call_args.args[1]
        self.assertTrue(output.startswith("/outputs/image-style-svdq-fp4_r32-flux.1-krea-dev-"))
        self.assertEqual(result["artifact"]["url"], artifact["url"])

    def test_interactive_selection_accepts_number_and_prompt(self) -> None:
        catalog = {"models": [{"id": MODEL, "display_name": "FLUX.1 Krea Dev"}]}
        failed = {"job": {"id": "12345678-1234-4123-8123-123456789abc", "status": "failed"}}
        with (
            mock.patch.object(CLI, "request", side_effect=[catalog, {"job": {"id": failed["job"]["id"]}}]),
            mock.patch.object(CLI, "wait_for_job", return_value=failed),
            mock.patch("builtins.input", side_effect=["1", "A red paper kite"]),
            mock.patch.object(CLI, "download_job") as download,
        ):
            result = CLI.image_style(self.args(model=None, prompt=None, seed=None))
        self.assertEqual(result, failed)
        download.assert_not_called()

    def test_list_does_not_submit(self) -> None:
        catalog = {"models": [{"id": MODEL}], "reference_only_models": []}
        with mock.patch.object(CLI, "request", return_value=catalog) as request:
            self.assertEqual(CLI.image_style(self.args(list=True)), catalog)
        request.assert_called_once_with("GET", "/v1/image/style/models", timeout=60)


if __name__ == "__main__":
    unittest.main()
