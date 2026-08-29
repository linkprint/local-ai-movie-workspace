#!/usr/bin/env python3

from __future__ import annotations

import importlib.util
import pathlib
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[2]
HOOK_PATH = ROOT / "images/workspace/hooks/enforce_image_urls.py"
SPEC = importlib.util.spec_from_file_location("enforce_image_urls", HOOK_PATH)
HOOK = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(HOOK)


class EnforceImageUrlsTest(unittest.TestCase):
    def test_allows_text_without_local_images(self) -> None:
        self.assertEqual(HOOK.response({"last_assistant_message": "Rendering is complete."}), {})

    def test_blocks_local_workspace_images_without_portal_urls(self) -> None:
        result = HOOK.response({
            "last_assistant_message": (
                "1. assets/boards/first.png\n"
                "2. `/workspace/project/assets/second image.jpg`"
            ),
        })

        self.assertEqual(result["decision"], "block")
        self.assertIn("movie-ai image url PATH", result["reason"])
        self.assertIn("movie-ai image publish PATH --link-source", result["reason"])
        self.assertIn("assets/boards/first.png", result["reason"])
        self.assertIn("/workspace/project/assets/second image.jpg", result["reason"])

    def test_allows_one_portal_url_for_each_local_image(self) -> None:
        project = "13628568-b63f-47e3-9550-2829827d6bad"
        message = (
            "Local: assets/boards/first.png\n"
            f"Web: https://movie.example.com/workspace/projects/{project}/images/first.png\n"
            "Local: assets/boards/second.jpg\n"
            f"Web: https://movie.example.com/workspace/projects/{project}/images/second.jpg"
        )

        self.assertEqual(HOOK.response({"last_assistant_message": message}), {})

    def test_allows_portal_only_response(self) -> None:
        project = "13628568-b63f-47e3-9550-2829827d6bad"
        message = f"https://movie.example.com/workspace/projects/{project}/images/result.webp"

        self.assertEqual(HOOK.response({"last_assistant_message": message}), {})

    def test_never_blocks_a_stop_hook_continuation(self) -> None:
        self.assertEqual(HOOK.response({
            "stop_hook_active": True,
            "last_assistant_message": "assets/boards/first.png",
        }), {})


if __name__ == "__main__":
    unittest.main()
