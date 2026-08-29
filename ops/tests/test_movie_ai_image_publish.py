#!/usr/bin/env python3

from __future__ import annotations

import importlib.util
import pathlib
import tempfile
import unittest
import uuid


ROOT = pathlib.Path(__file__).resolve().parents[2]
CLI_PATH = ROOT / "images/workspace/movie-ai.py"
SPEC = importlib.util.spec_from_file_location("movie_ai_image_publish", CLI_PATH)
CLI = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(CLI)


class MovieAiImagePublishTest(unittest.TestCase):
    def test_link_source_moves_bytes_to_outputs_and_keeps_the_old_path_usable(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = pathlib.Path(temporary)
            workspace = root / "workspace"
            outputs = root / "outputs"
            workspace.mkdir()
            outputs.mkdir()
            source = workspace / "storyboard.png"
            source.write_bytes(b"one-image-only")

            CLI.WRITE_ROOTS = (workspace.resolve(), outputs.resolve())
            CLI.OUTPUTS_ROOT = outputs.resolve()
            CLI.PROJECT_ID = str(uuid.uuid4())
            result = CLI.publish_image(str(source), link_source=True)

            destination = pathlib.Path(result["output"])
            self.assertTrue(source.is_symlink())
            self.assertEqual(source.resolve(), destination)
            self.assertEqual(destination.read_bytes(), b"one-image-only")
            self.assertTrue(result["source_linked"])
            self.assertIn(f"/workspace/projects/{CLI.PROJECT_ID}/images/storyboard.png", result["url"])
            self.assertEqual([destination], [path.resolve() for path in outputs.iterdir() if path.is_file()])

    def test_default_publish_still_preserves_the_regular_source(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = pathlib.Path(temporary)
            workspace = root / "workspace"
            outputs = root / "outputs"
            workspace.mkdir()
            outputs.mkdir()
            source = workspace / "character.webp"
            source.write_bytes(b"image")

            CLI.WRITE_ROOTS = (workspace.resolve(), outputs.resolve())
            CLI.OUTPUTS_ROOT = outputs.resolve()
            CLI.PROJECT_ID = str(uuid.uuid4())
            result = CLI.publish_image(str(source))

            self.assertTrue(source.is_file())
            self.assertFalse(source.is_symlink())
            self.assertFalse(result["source_linked"])


if __name__ == "__main__":
    unittest.main()
