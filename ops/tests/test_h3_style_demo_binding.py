#!/usr/bin/env python3

from __future__ import annotations

import hashlib
import importlib.util
import os
import pathlib
import tempfile
import threading
import unittest
import uuid


ROOT = pathlib.Path(__file__).resolve().parents[2]


def load_module(name: str, path: pathlib.Path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(module)
    return module


class H3StyleDemoBindingTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.secrets = tempfile.TemporaryDirectory()
        secret_root = pathlib.Path(cls.secrets.name)
        control_secret = secret_root / "control-secret"
        manager_secret = secret_root / "manager-secret"
        control_secret.write_text("a" * 64, encoding="utf-8")
        manager_secret.write_text("b" * 64, encoding="utf-8")
        os.environ["MOVIE_BROKER_SECRET_FILE"] = str(control_secret)
        os.environ["MOVIE_BROKER_MANAGER_SECRET_FILE"] = str(manager_secret)
        os.environ["MOVIE_H3_STYLE_SKILLS_FILE"] = str(
            ROOT / "images/control/h3-style-skills.txt"
        )
        cls.broker = load_module(
            "movie_broker_h3_style_test", ROOT / "images/control/broker.py"
        )
        cls.cli = load_module(
            "movie_ai_h3_style_test", ROOT / "images/workspace/movie-ai.py"
        )

    @classmethod
    def tearDownClass(cls) -> None:
        cls.secrets.cleanup()

    def test_cli_requires_explicit_style_flag(self) -> None:
        parsed = self.cli.parser().parse_args([
            "h3", "generate", "--spec", "/workspace/job.json",
            "--workflow-preset", "standard",
            "--content-profile", "general",
            "--style-skill", "h3-editorial-fashion-motion",
        ])
        self.assertEqual(parsed.style_skill, "h3-editorial-fashion-motion")
        self.assertEqual(parsed.workflow_preset, "standard")
        self.assertEqual(parsed.content_profile, "general")
        prepared = self.cli.prepare_h3_submission(
            {"mode": "t2va", "prompt": "A fashion film."},
            pathlib.Path("/workspace"),
            parsed.style_skill,
            parsed.workflow_preset,
            parsed.content_profile,
        )
        self.assertEqual(prepared["style_skill"], "h3-editorial-fashion-motion")
        self.assertEqual(prepared["workflow_preset"], "standard")
        self.assertEqual(prepared["content_profile"], "general")
        with self.assertRaisesRegex(SystemExit, "must be supplied with --style-skill"):
            self.cli.prepare_h3_submission(
                {"prompt": "x", "style_skill": "h3-editorial-fashion-motion"},
                pathlib.Path("/workspace"),
                None,
            )

    def test_broker_accepts_only_registered_styles(self) -> None:
        reservation_id = str(uuid.uuid4())
        validated = self.broker.validate_h3_spec({
            "mode": "t2va",
            "prompt": "A registered style render.",
            "style_skill": "h3-editorial-fashion-motion",
        }, reservation_id)
        self.assertEqual(validated["style_skill"], "h3-editorial-fashion-motion")
        with self.assertRaisesRegex(ValueError, "unsupported_h3_style_skill"):
            self.broker.validate_h3_spec({
                "mode": "t2va",
                "prompt": "An unregistered style render.",
                "style_skill": "h3-not-registered",
            }, reservation_id)

    def test_first_complete_mp4_binds_and_later_video_never_overwrites(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = pathlib.Path(directory)
            self.broker.STYLE_DEMO_ROOT = root / "demos"
            first = root / "first.mp4"
            later = root / "later.mp4"
            first.write_bytes(b"first-complete-video" * 256)
            later.write_bytes(b"later-video" * 256)
            first_sha = hashlib.sha256(first.read_bytes()).hexdigest()
            later_sha = hashlib.sha256(later.read_bytes()).hexdigest()

            bound = self.broker.claim_style_demo(
                first, "h3-editorial-fashion-motion", first_sha
            )
            existing = self.broker.claim_style_demo(
                later, "h3-editorial-fashion-motion", later_sha
            )
            destination = self.broker.STYLE_DEMO_ROOT / "h3-editorial-fashion-motion.mp4"

            self.assertEqual(bound["status"], "bound")
            self.assertEqual(existing["status"], "existing")
            self.assertEqual(destination.read_bytes(), first.read_bytes())
            self.assertEqual(destination.stat().st_mode & 0o777, 0o644)

    def test_concurrent_first_writers_have_one_winner(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = pathlib.Path(directory)
            self.broker.STYLE_DEMO_ROOT = root / "demos"
            sources = []
            for index in range(8):
                source = root / f"source-{index}.mp4"
                source.write_bytes(f"complete-video-{index}".encode() * 1024)
                sources.append(source)

            barrier = threading.Barrier(len(sources))
            results: list[dict] = []
            errors: list[BaseException] = []
            lock = threading.Lock()

            def claim(source: pathlib.Path) -> None:
                try:
                    barrier.wait()
                    result = self.broker.claim_style_demo(
                        source,
                        "h3-surreal-miniature-absurdism",
                        hashlib.sha256(source.read_bytes()).hexdigest(),
                    )
                    with lock:
                        results.append(result)
                except BaseException as exc:
                    with lock:
                        errors.append(exc)

            threads = [threading.Thread(target=claim, args=(source,)) for source in sources]
            for thread in threads:
                thread.start()
            for thread in threads:
                thread.join()

            self.assertEqual(errors, [])
            self.assertEqual([item["status"] for item in results].count("bound"), 1)
            self.assertEqual([item["status"] for item in results].count("existing"), 7)
            destination = (
                self.broker.STYLE_DEMO_ROOT / "h3-surreal-miniature-absurdism.mp4"
            )
            self.assertIn(destination.read_bytes(), [source.read_bytes() for source in sources])


if __name__ == "__main__":
    unittest.main()
