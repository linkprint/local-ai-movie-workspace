#!/usr/bin/env python3
"""Unit checks for fixed Workspace email-root and project subpath handling."""

from __future__ import annotations

import base64
import hashlib
import importlib.util
import io
import os
import pathlib
import tarfile
import tempfile
import time
import unittest
from unittest import mock


ROOT = pathlib.Path(__file__).resolve().parents[2]


def load_manager(temp: pathlib.Path):
    secret = temp / "secret"
    secret.write_bytes(b"s" * 64)
    os.environ.update({
        "MOVIE_MANAGER_SECRET_FILE": str(secret),
        "MOVIE_BROKER_MANAGER_SECRET_FILE": str(secret),
        "MOVIE_H3_CONTROL_SECRET_FILE": str(secret),
        "MOVIE_WORKSPACE_SECCOMP": str(ROOT / "security/seccomp/workspace.json"),
    })
    path = ROOT / "images/control/manager.py"
    spec = importlib.util.spec_from_file_location("workspace_project_manager", path)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(module)
    return module


class WorkspaceProjectIsolationTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.temp = tempfile.TemporaryDirectory()
        cls.manager = load_manager(pathlib.Path(cls.temp.name))

    @classmethod
    def tearDownClass(cls) -> None:
        cls.temp.cleanup()

    def test_valid_email_root_and_project_are_accepted(self) -> None:
        self.manager.validate_workspace_path("admin@example.com", "qi-yue-liu-huo")
        mount = self.manager.workspace_volume_mount("opaque-volume", "admin@example.com")
        self.assertEqual("volume", mount["Type"])
        self.assertEqual("opaque-volume", mount["Source"])
        self.assertEqual("/workspace", mount["Target"])
        self.assertEqual(
            {"NoCopy": True, "Subpath": "admin@example.com"},
            mount["VolumeOptions"],
        )
        self.assertNotIn("BindOptions", mount)

        output_mount = self.manager.outputs_volume_mount(
            "12345678-1234-4123-8123-123456789abc",
            "22345678-1234-4123-8123-123456789abc",
        )
        self.assertEqual(self.manager.OUTPUTS_VOLUME, output_mount["Source"])
        self.assertEqual("/outputs", output_mount["Target"])
        self.assertEqual(
            {
                "NoCopy": True,
                "Subpath": "12345678-1234-4123-8123-123456789abc/22345678-1234-4123-8123-123456789abc",
            },
            output_mount["VolumeOptions"],
        )

    def test_auth_mode_selects_only_the_fixed_personal_or_company_codex_volume(self) -> None:
        reservation = "12345678-1234-4123-8123-123456789abc"
        storage = "22345678-1234-4123-8123-123456789abc"
        project = "32345678-1234-4123-8123-123456789abc"

        def payload_for(auth_mode: str):
            calls: list[tuple[str, str, object]] = []

            def docker_request(method, path, payload=None, expected=(200, 201, 204)):
                calls.append((method, path, payload))
                if method == "POST" and path.startswith("/containers/create"):
                    return {"Id": f"{auth_mode}-container"}
                return {}

            current = {
                "Id": f"{auth_mode}-container",
                "State": {"Running": True},
                "Config": {"Labels": {"com.linkprint.movie.auth-mode": auth_mode}},
            }
            with (
                mock.patch.object(self.manager, "inspect_container", side_effect=[None, current]),
                mock.patch.object(self.manager, "docker_request", side_effect=docker_request),
                mock.patch.object(self.manager, "ensure_volume"),
                mock.patch.object(self.manager, "prepare_workspace_path"),
                mock.patch.object(self.manager, "prepare_outputs_path"),
                mock.patch.object(self.manager, "write_deadline"),
            ):
                self.manager.create_workspace(
                    reservation,
                    storage,
                    int(time.time()) + 600,
                    "t" * 96,
                    "admin@example.com",
                    project,
                    "movie-project",
                    auth_mode,
                )

            return next(
                payload for method, path, payload in calls
                if method == "POST" and path.startswith("/containers/create")
            )

        personal = payload_for("personal")
        company = payload_for("company")
        names = self.manager.volume_names(storage)

        personal_codex = next(
            mount for mount in personal["HostConfig"]["Mounts"]
            if mount["Target"] == "/home/codex/.codex"
        )
        company_codex = next(
            mount for mount in company["HostConfig"]["Mounts"]
            if mount["Target"] == "/home/codex/.codex"
        )
        self.assertEqual(names["codex"], personal_codex["Source"])
        self.assertEqual(self.manager.COMPANY_CODEX_VOLUME, company_codex["Source"])
        self.assertNotEqual(personal_codex["Source"], company_codex["Source"])
        self.assertIn("MOVIE_CODEX_AUTH_MODE=personal", personal["Env"])
        self.assertIn("MOVIE_CODEX_AUTH_MODE=company", company["Env"])
        self.assertEqual("personal", personal["Labels"]["com.linkprint.movie.auth-mode"])
        self.assertEqual("company", company["Labels"]["com.linkprint.movie.auth-mode"])

        with self.assertRaisesRegex(ValueError, "invalid_auth_mode"):
            self.manager.validate_auth_mode("shared-shell")

    def test_traversal_slashes_and_unsafe_project_names_are_rejected(self) -> None:
        invalid = (
            ("../person@example.com", "movie"),
            ("person/name@example.com", "movie"),
            ("Person@example.com", "movie"),
            ("person@example.com", "../movie"),
            ("person@example.com", "movie/other"),
            ("person@example.com", ".hidden"),
            ("person@example.com", "trailing-"),
            ("person@example.com", "two..dots"),
            ("person@example.com", "Movie"),
        )
        for root, project in invalid:
            with self.subTest(root=root, project=project), self.assertRaises(ValueError):
                self.manager.validate_workspace_path(root, project)

    def test_initializer_is_a_fixed_offline_named_volume_helper(self) -> None:
        calls: list[tuple[str, str, object]] = []

        def docker_request(method, path, payload=None, expected=(200, 201, 204)):
            calls.append((method, path, payload))
            if method == "POST" and path.startswith("/containers/create"):
                return {"Id": "helper-id"}
            if method == "POST" and path.endswith("/wait?condition=not-running"):
                return {"StatusCode": 0}
            return None

        with mock.patch.object(self.manager, "docker_request", side_effect=docker_request):
            self.manager.prepare_workspace_path(
                "12345678-1234-4123-8123-123456789abc",
                "admin@example.com",
                "movie-project",
            )

        create = next(payload for method, path, payload in calls if method == "POST" and path.startswith("/containers/create"))
        host = create["HostConfig"]
        self.assertEqual("none", host["NetworkMode"])
        self.assertTrue(host["ReadonlyRootfs"])
        self.assertEqual(["ALL"], host["CapDrop"])
        self.assertEqual("10001:10001", create["User"])
        self.assertEqual("volume", host["Mounts"][0]["Type"])
        self.assertEqual("/workspace", host["Mounts"][0]["Target"])
        self.assertNotIn("VolumeOptions", host["Mounts"][0])
        self.assertEqual(
            ["WORKSPACE_ROOT=admin@example.com", "PROJECT_DIRECTORY=movie-project"],
            create["Env"],
        )
        self.assertNotIn("admin@example.com", create["Cmd"][0])
        self.assertNotIn("movie-project", create["Cmd"][0])

    def test_output_initializer_is_offline_non_destructive_and_project_scoped(self) -> None:
        calls: list[tuple[str, str, object]] = []

        def docker_request(method, path, payload=None, expected=(200, 201, 204)):
            calls.append((method, path, payload))
            if method == "POST" and path.startswith("/containers/create"):
                return {"Id": "outputs-helper-id"}
            if method == "POST" and path.endswith("/wait?condition=not-running"):
                return {"StatusCode": 0}
            return None

        storage = "12345678-1234-4123-8123-123456789abc"
        project = "22345678-1234-4123-8123-123456789abc"
        with mock.patch.object(self.manager, "docker_request", side_effect=docker_request):
            self.manager.prepare_outputs_path(storage, project)

        create = next(payload for method, path, payload in calls if method == "POST" and path.startswith("/containers/create"))
        self.assertEqual("10001:10001", create["User"])
        self.assertEqual("none", create["HostConfig"]["NetworkMode"])
        self.assertTrue(create["HostConfig"]["ReadonlyRootfs"])
        self.assertEqual(["ALL"], create["HostConfig"]["CapDrop"])
        self.assertEqual(self.manager.OUTPUTS_VOLUME, create["HostConfig"]["Mounts"][0]["Source"])
        self.assertFalse(create["HostConfig"]["Mounts"][0]["ReadOnly"])
        self.assertTrue(create["HostConfig"]["Mounts"][1]["ReadOnly"])
        self.assertIn("shutil.copyfile", create["Cmd"][0])
        self.assertNotIn("unlink(", create["Cmd"][0])
        self.assertNotIn("rename(", create["Cmd"][0])

    def test_directory_renamer_is_fixed_offline_and_refuses_an_active_workspace(self) -> None:
        storage = "12345678-1234-4123-8123-123456789abc"
        calls: list[tuple[str, str, object]] = []

        def docker_request(method, path, payload=None, expected=(200, 201, 204)):
            calls.append((method, path, payload))
            if method == "POST" and path.startswith("/containers/create"):
                return {"Id": "rename-helper-id"}
            if method == "POST" and path.endswith("/wait?condition=not-running"):
                return {"StatusCode": 0}
            return None

        with (
            mock.patch.object(self.manager, "inspect_container", return_value=None),
            mock.patch.object(self.manager, "docker_request", side_effect=docker_request),
        ):
            self.manager.rename_workspace_path(
                storage,
                "admin@example.com",
                "project-2",
                "qi-yue-liu-huo",
            )

        create = next(payload for method, path, payload in calls if method == "POST" and path.startswith("/containers/create"))
        self.assertEqual("none", create["HostConfig"]["NetworkMode"])
        self.assertTrue(create["HostConfig"]["ReadonlyRootfs"])
        self.assertEqual(["ALL"], create["HostConfig"]["CapDrop"])
        self.assertEqual("10001:10001", create["User"])
        self.assertEqual("volume", create["HostConfig"]["Mounts"][0]["Type"])
        self.assertEqual([
            "WORKSPACE_ROOT=admin@example.com",
            "OLD_DIRECTORY=project-2",
            "NEW_DIRECTORY=qi-yue-liu-huo",
        ], create["Env"])
        self.assertNotIn("project-2", create["Cmd"][0])
        self.assertNotIn("qi-yue-liu-huo", create["Cmd"][0])

        with mock.patch.object(self.manager, "inspect_container", return_value={"State": {"Running": True}}):
            with self.assertRaisesRegex(RuntimeError, "workspace_active"):
                self.manager.rename_workspace_path(
                    storage,
                    "admin@example.com",
                    "project-2",
                    "qi-yue-liu-huo",
                )

    def test_project_trash_and_restore_use_fixed_recoverable_offline_helpers(self) -> None:
        storage = "12345678-1234-4123-8123-123456789abc"
        project_id = "22345678-1234-4123-8123-123456789abc"
        calls: list[tuple[str, str, object]] = []

        def docker_request(method, path, payload=None, expected=(200, 201, 204)):
            calls.append((method, path, payload))
            if method == "POST" and path.startswith("/containers/create"):
                return {"Id": "trash-helper-id"}
            if method == "POST" and path.endswith("/wait?condition=not-running"):
                return {"StatusCode": 0}
            return None

        with (
            mock.patch.object(self.manager, "inspect_container", return_value=None),
            mock.patch.object(self.manager, "docker_request", side_effect=docker_request),
        ):
            trashed = self.manager.trash_workspace_path(
                storage,
                "admin@example.com",
                project_id,
                "qi-yue-liu-huo",
            )
            restored = self.manager.restore_workspace_path(
                storage,
                "admin@example.com",
                project_id,
                "qi-yue-liu-huo",
            )

        creates = [payload for method, path, payload in calls if method == "POST" and path.startswith("/containers/create")]
        self.assertEqual(2, len(creates))
        self.assertEqual("private_trash", trashed["disposition"])
        self.assertEqual("restored", restored["disposition"])
        for create, direction in zip(creates, ("trash", "restore"), strict=True):
            host = create["HostConfig"]
            self.assertEqual("none", host["NetworkMode"])
            self.assertTrue(host["ReadonlyRootfs"])
            self.assertEqual(["ALL"], host["CapDrop"])
            self.assertEqual("10001:10001", create["User"])
            self.assertEqual("volume", host["Mounts"][0]["Type"])
            self.assertEqual(f"DIRECTION={direction}", create["Env"][3])
            self.assertNotIn(project_id, create["Cmd"][0])
            self.assertNotIn("qi-yue-liu-huo", create["Cmd"][0])
            self.assertNotIn("/outputs", create["Cmd"][0])
            self.assertNotIn("CODEX_HOME", create["Cmd"][0])

        with mock.patch.object(self.manager, "inspect_container", return_value={"State": {"Running": True}}):
            with self.assertRaisesRegex(RuntimeError, "workspace_active"):
                self.manager.trash_workspace_path(
                    storage,
                    "admin@example.com",
                    project_id,
                    "qi-yue-liu-huo",
                )

        with self.assertRaisesRegex(ValueError, "invalid_project"):
            self.manager.trash_workspace_path(
                storage,
                "admin@example.com",
                "../project",
                "qi-yue-liu-huo",
            )

    def test_project_media_upload_is_integrity_checked_and_bound_to_the_active_project(self) -> None:
        reservation = "12345678-1234-4123-8123-123456789abc"
        storage = "22345678-1234-4123-8123-123456789abc"
        project = "32345678-1234-4123-8123-123456789abc"
        filename = "42345678-1234-4123-8123-123456789abc.png"
        contents = b"\x89PNG\r\n\x1a\n" + b"bounded-image"
        digest = hashlib.sha256(contents).hexdigest()
        state = {
            "reservation_id": reservation,
            "storage_uuid": storage,
            "workspace_root": "admin@example.com",
            "project_id": project,
            "project_directory": "movie-project",
            "deadline_epoch": int(time.time()) + 600,
        }
        current = {
            "State": {"Running": True},
            "Config": {"Labels": {
                "com.linkprint.movie.reservation": reservation,
                "com.linkprint.movie.storage": storage,
                "com.linkprint.movie.workspace-root": "admin@example.com",
                "com.linkprint.movie.project-id": project,
                "com.linkprint.movie.project-directory": "movie-project",
            }},
        }
        archives: list[tuple[str, str, bytes, str]] = []

        def docker_request_bytes(method, path, body, content_type, expected=(200, 201, 204)):
            archives.append((method, path, body, content_type))
            return b""

        with (
            mock.patch.object(self.manager, "load_state", return_value=state),
            mock.patch.object(self.manager, "inspect_container", return_value=current),
            mock.patch.object(self.manager, "docker_request_bytes", side_effect=docker_request_bytes),
        ):
            result = self.manager.write_project_media(
                reservation,
                storage,
                "admin@example.com",
                project,
                "movie-project",
                filename,
                "image/png",
                len(contents),
                digest,
                base64.b64encode(contents).decode(),
            )

        self.assertEqual(f"/workspace/movie-project/uploads/{filename}", result["path"])
        self.assertEqual(1, len(archives))
        method, path, raw_archive, content_type = archives[0]
        self.assertEqual("PUT", method)
        self.assertIn("/containers/movie-active-workspace/archive?", path)
        self.assertIn("%2Fworkspace%2Fmovie-project", path)
        self.assertEqual("application/x-tar", content_type)
        with tarfile.open(fileobj=io.BytesIO(raw_archive), mode="r") as archive:
            directory = archive.getmember("uploads")
            image = archive.getmember(f"uploads/{filename}")
            self.assertEqual(0o700, directory.mode)
            self.assertEqual(0o600, image.mode)
            self.assertEqual(10001, image.uid)
            self.assertEqual(contents, archive.extractfile(image).read())

        with (
            mock.patch.object(self.manager, "load_state", return_value={**state, "project_id": reservation}),
            mock.patch.object(self.manager, "inspect_container", return_value=current),
            mock.patch.object(self.manager, "docker_request_bytes") as docker_bytes,
            self.assertRaisesRegex(RuntimeError, "project_mismatch"),
        ):
            self.manager.write_project_media(
                reservation,
                storage,
                "admin@example.com",
                project,
                "movie-project",
                filename,
                "image/png",
                len(contents),
                digest,
                base64.b64encode(contents).decode(),
            )
        docker_bytes.assert_not_called()

    def test_project_media_upload_rejects_mime_and_integrity_mismatches(self) -> None:
        contents = b"\x89PNG\r\n\x1a\nimage"
        digest = hashlib.sha256(contents).hexdigest()
        filename = "42345678-1234-4123-8123-123456789abc.png"

        with self.assertRaisesRegex(ValueError, "invalid_media_type"):
            self.manager.decode_project_media(
                filename,
                "image/jpeg",
                len(contents),
                digest,
                base64.b64encode(contents).decode(),
            )
        with self.assertRaisesRegex(ValueError, "media_integrity_mismatch"):
            self.manager.decode_project_media(
                filename,
                "image/png",
                len(contents) + 1,
                digest,
                base64.b64encode(contents).decode(),
            )

        video = b"\x00\x00\x00\x18ftypisom" + b"bounded-video"
        video_digest = hashlib.sha256(video).hexdigest()
        decoded = self.manager.decode_project_media(
            "42345678-1234-4123-8123-123456789abc.mp4",
            "video/mp4",
            len(video),
            video_digest,
            base64.b64encode(video).decode(),
        )
        self.assertEqual(video, decoded)
        with self.assertRaisesRegex(ValueError, "media_signature_mismatch"):
            self.manager.decode_project_media(
                "42345678-1234-4123-8123-123456789abc.webm",
                "video/webm",
                len(video),
                video_digest,
                base64.b64encode(video).decode(),
            )


if __name__ == "__main__":
    unittest.main()
