#!/usr/bin/env python3

from __future__ import annotations

import importlib.util
import json
import os
import pathlib
import tempfile
import time
import unittest
from unittest import mock


ROOT = pathlib.Path(__file__).resolve().parents[2]


def load_manager(root: pathlib.Path):
    for name in ("manager", "broker-manager", "h3-control"):
        path = root / name
        path.write_text(name * 16, encoding="ascii")
    seccomp = root / "seccomp.json"
    seccomp.write_text(
        json.dumps({"defaultAction": "SCMP_ACT_ERRNO", "syscalls": []}),
        encoding="utf-8",
    )
    os.environ["MOVIE_MANAGER_SECRET_FILE"] = str(root / "manager")
    os.environ["MOVIE_BROKER_MANAGER_SECRET_FILE"] = str(root / "broker-manager")
    os.environ["MOVIE_H3_CONTROL_SECRET_FILE"] = str(root / "h3-control")
    os.environ["MOVIE_WORKSPACE_SECCOMP"] = str(seccomp)
    os.environ["MOVIE_WORKSPACE_SECURITY_REVISION"] = "1"
    spec = importlib.util.spec_from_file_location(
        "movie_workspace_manager_v2_test",
        ROOT / "images/control/manager.py",
    )
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(module)
    return module


class WorkspaceManagerV2Test(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temporary = tempfile.TemporaryDirectory()
        cls.manager = load_manager(pathlib.Path(cls.temporary.name))

    @classmethod
    def tearDownClass(cls):
        cls.temporary.cleanup()

    def test_router_network_allows_independent_runtime_grants(self):
        runtime_id = "12345678-1234-4123-8123-123456789abc"
        other_runtime_id = "22345678-1234-4123-8123-123456789abc"
        user_id = "32345678-1234-4123-8123-123456789abc"
        reservation_id = "42345678-1234-4123-8123-123456789abc"
        current = {"Id": "current", "NetworkSettings": {"Networks": {}}}
        other = {"Id": "other", "NetworkSettings": {"Networks": {"movie_broker": {}}}}
        data = {
            "runtime_id": runtime_id,
            "user_id": user_id,
            "reservation_id": reservation_id,
            "compute_node_id": "52345678-1234-4123-8123-123456789abc",
            "generation": 1,
            "expires_at": int(time.time()) + 3600,
            "token": "t" * 96,
            "capabilities": ["qwen.responses"],
        }

        with mock.patch.object(self.manager, "runtime_context", return_value=current), \
             mock.patch.object(self.manager, "list_runtime_containers", return_value=[{
                 "Labels": {self.manager.RUNTIME_LABEL: other_runtime_id},
             }]), \
             mock.patch.object(self.manager, "inspect_runtime", return_value=other), \
             mock.patch.object(self.manager, "write_runtime_grant") as write_grant, \
             mock.patch.object(self.manager, "connect_network") as connect_network, \
             mock.patch.object(self.manager, "exec_workspace_command"):
            result = self.manager.set_runtime_ai_grant(data)

        self.assertTrue(result["granted"])
        write_grant.assert_called_once()
        self.assertEqual(
            "52345678-1234-4123-8123-123456789abc",
            write_grant.call_args.args[1]["compute_node_id"],
        )
        connect_network.assert_called_once()

    def test_runtime_status_survives_an_image_rotation_at_the_same_security_revision(self):
        runtime_id = "12345678-1234-4123-8123-123456789abc"
        current = {
            "Id": "workspace-id",
            "Image": "sha256:" + ("a" * 64),
            "State": {"Running": True, "Health": {"Status": "healthy"}},
            "Config": {"Labels": {
                "com.linkprint.movie.workspace-runtime": "true",
                self.manager.RUNTIME_LABEL: runtime_id,
                self.manager.USER_LABEL: "22345678-1234-4123-8123-123456789abc",
                self.manager.GENERATION_LABEL: "3",
                self.manager.SECURITY_REVISION_LABEL: self.manager.WORKSPACE_SECURITY_REVISION,
                "com.linkprint.movie.auth-mode": "personal",
            }},
            "NetworkSettings": {"Networks": {}},
        }

        with mock.patch.object(self.manager, "inspect_container", return_value=current), \
             mock.patch.object(
                 self.manager,
                 "expected_workspace_image_id",
                 return_value="sha256:" + ("b" * 64),
             ):
            status = self.manager.runtime_status_payload(runtime_id)

        self.assertTrue(status["running"])
        self.assertTrue(status["healthy"])
        self.assertFalse(status["image_current"])
        self.assertEqual(self.manager.WORKSPACE_SECURITY_REVISION, status["security_revision"])

    def test_runtime_security_revision_is_fail_closed_but_still_inspectable_for_cleanup(self):
        runtime_id = "12345678-1234-4123-8123-123456789abc"
        current = {
            "Config": {"Labels": {
                "com.linkprint.movie.workspace-runtime": "true",
                self.manager.RUNTIME_LABEL: runtime_id,
                self.manager.SECURITY_REVISION_LABEL: "999",
            }},
        }

        with mock.patch.object(self.manager, "inspect_container", return_value=current):
            with self.assertRaisesRegex(RuntimeError, "runtime_security_revision_mismatch"):
                self.manager.inspect_runtime(runtime_id)
            self.assertIs(
                current,
                self.manager.inspect_runtime(
                    runtime_id,
                    require_current_security_revision=False,
                ),
            )

    def test_security_revision_rotation_recreates_only_the_owned_runtime_and_preserves_volumes(self):
        runtime_id = "12345678-1234-4123-8123-123456789abc"
        user_id = "22345678-1234-4123-8123-123456789abc"
        storage_id = "32345678-1234-4123-8123-123456789abc"
        project_id = "42345678-1234-4123-8123-123456789abc"
        current = {
            "Id": "old-workspace-id",
            "State": {"Running": True},
            "Config": {"Labels": {
                "com.linkprint.movie.workspace-runtime": "true",
                self.manager.RUNTIME_LABEL: runtime_id,
                self.manager.USER_LABEL: user_id,
                "com.linkprint.movie.storage": storage_id,
                self.manager.SECURITY_REVISION_LABEL: "999",
            }},
            "NetworkSettings": {"Networks": {self.manager.runtime_network_name(runtime_id): {}}},
        }
        refreshed = {
            "Id": "new-workspace-id",
            "State": {"Running": True, "Health": {"Status": "healthy"}},
            "Config": {"Labels": {
                "com.linkprint.movie.workspace-runtime": "true",
                self.manager.RUNTIME_LABEL: runtime_id,
                self.manager.USER_LABEL: user_id,
                self.manager.SECURITY_REVISION_LABEL: self.manager.WORKSPACE_SECURITY_REVISION,
            }},
        }
        router = {"Id": "router-id", "State": {"Running": True}, "NetworkSettings": {"Networks": {}}}
        egress = {"Id": "egress-id", "State": {"Running": True}, "NetworkSettings": {"Networks": {}}}
        calls: list[tuple[str, str, object]] = []

        def inspect_container(name):
            if name == self.manager.TERMINAL_ROUTER_CONTAINER:
                return router
            if name == self.manager.EGRESS_CONTAINER:
                return egress
            return None

        def docker_request(method, path, payload=None, expected=(200, 201, 204)):
            calls.append((method, path, payload))
            if method == "POST" and path.startswith("/containers/create"):
                return {"Id": "new-workspace-id"}
            return None

        with mock.patch.object(
            self.manager, "inspect_runtime", side_effect=[current, refreshed],
        ) as inspect_runtime, mock.patch.object(
            self.manager, "stop_runtime_workspace",
        ) as stop_runtime, mock.patch.object(
            self.manager, "list_runtime_containers", return_value=[],
        ), mock.patch.object(
            self.manager, "inspect_container", side_effect=inspect_container,
        ), mock.patch.object(self.manager, "ensure_volume"), mock.patch.object(
            self.manager, "prepare_workspace_path",
        ), mock.patch.object(self.manager, "prepare_outputs_path"), mock.patch.object(
            self.manager, "write_runtime_deadline",
        ), mock.patch.object(self.manager, "write_runtime_grant"), mock.patch.object(
            self.manager, "ensure_runtime_network", return_value=self.manager.runtime_network_name(runtime_id),
        ), mock.patch.object(
            self.manager,
            "workspace_volume_mount",
            return_value={"Type": "volume", "Source": "workspace", "Target": "/workspace"},
        ), mock.patch.object(
            self.manager,
            "outputs_volume_mount",
            return_value={"Type": "volume", "Source": "outputs", "Target": "/outputs"},
        ), mock.patch.object(
            self.manager, "docker_request", side_effect=docker_request,
        ), mock.patch.object(self.manager, "connect_network"):
            result = self.manager.create_runtime_workspace(
                runtime_id,
                user_id,
                storage_id,
                4,
                int(time.time()) + 3600,
                "person@example.com",
                project_id,
                "movie-project",
                "personal",
            )

        stop_runtime.assert_called_once_with(runtime_id, preserve_volumes=True)
        self.assertEqual([
            mock.call(runtime_id, require_current_security_revision=False),
            mock.call(runtime_id),
        ], inspect_runtime.call_args_list)
        create_payload = next(
            payload for method, path, payload in calls
            if method == "POST" and path.startswith("/containers/create")
        )
        self.assertEqual(
            self.manager.WORKSPACE_SECURITY_REVISION,
            create_payload["Labels"][self.manager.SECURITY_REVISION_LABEL],
        )
        codex_mount = next(
            mount for mount in create_payload["HostConfig"]["Mounts"]
            if mount.get("Target") == "/home/codex/.codex"
        )
        self.assertEqual(self.manager.volume_names(storage_id)["codex"], codex_mount["Source"])
        self.assertIs(refreshed, result)

    def test_new_workspace_uses_only_its_private_network_for_router_and_egress(self):
        runtime_id = "12345678-1234-4123-8123-123456789abc"
        user_id = "22345678-1234-4123-8123-123456789abc"
        storage_id = "32345678-1234-4123-8123-123456789abc"
        project_id = "42345678-1234-4123-8123-123456789abc"
        network = self.manager.runtime_network_name(runtime_id)
        router = {"Id": "router-id", "State": {"Running": True}, "NetworkSettings": {"Networks": {}}}
        egress = {"Id": "egress-id", "State": {"Running": True}, "NetworkSettings": {"Networks": {}}}

        def inspect_container(name):
            if name == self.manager.TERMINAL_ROUTER_CONTAINER:
                return router
            if name == self.manager.EGRESS_CONTAINER:
                return egress
            return None

        def docker_request(method, path, payload=None, expected=(200, 201, 204)):
            if method == "POST" and path.startswith("/containers/create"):
                return {"Id": "workspace-id"}
            return None

        with mock.patch.object(self.manager, "inspect_runtime", return_value=None), \
             mock.patch.object(self.manager, "list_runtime_containers", return_value=[]), \
             mock.patch.object(self.manager, "inspect_container", side_effect=inspect_container), \
             mock.patch.object(self.manager, "ensure_volume"), \
             mock.patch.object(self.manager, "prepare_workspace_path"), \
             mock.patch.object(self.manager, "prepare_outputs_path"), \
             mock.patch.object(self.manager, "write_runtime_deadline"), \
             mock.patch.object(self.manager, "write_runtime_grant"), \
             mock.patch.object(self.manager, "ensure_runtime_network", return_value=network), \
             mock.patch.object(self.manager, "workspace_volume_mount", return_value={}), \
             mock.patch.object(self.manager, "outputs_volume_mount", return_value={}), \
             mock.patch.object(self.manager, "docker_request", side_effect=docker_request), \
             mock.patch.object(self.manager, "connect_network") as connect_network:
            self.manager.create_runtime_workspace(
                runtime_id,
                user_id,
                storage_id,
                1,
                int(time.time()) + 3600,
                "person@example.com",
                project_id,
                "movie-project",
                "personal",
            )

        self.assertEqual(connect_network.call_args_list, [
            mock.call(network, "router-id", self.manager.TERMINAL_ROUTER_CONTAINER),
            mock.call(network, "egress-id", "movie-egress"),
        ])
        self.assertFalse(any("movie_egress_client" in str(call) for call in connect_network.call_args_list))

    def test_session_delete_uses_codex_delete_and_rejects_the_current_session(self):
        session_id = "12345678-1234-4123-8123-123456789abc"
        current_id = "22345678-1234-4123-8123-123456789abc"
        data = {
            "runtime_id": "32345678-1234-4123-8123-123456789abc",
            "user_id": "42345678-1234-4123-8123-123456789abc",
            "project_id": "52345678-1234-4123-8123-123456789abc",
            "generation": 4,
            "auth_mode": "personal",
            "session_id": session_id,
        }
        listing = {
            "available": True,
            "sessions": [{"id": session_id}],
            "current_session_id": current_id,
        }
        refreshed = {
            "available": True,
            "sessions": [],
            "current_session_id": current_id,
        }
        with mock.patch.object(
            self.manager, "list_runtime_sessions", side_effect=[listing, refreshed],
        ), mock.patch.object(
            self.manager, "runtime_context", return_value={"Id": "a" * 64},
        ), mock.patch.object(self.manager, "exec_workspace_command") as execute:
            result = self.manager.delete_runtime_session(data)

        self.assertEqual({"deleted": True, "session_id": session_id}, result)
        execute.assert_called_once_with(
            "a" * 64,
            [self.manager.CODEX_COMMAND, "delete", session_id, "--force"],
            "session_delete_failed",
        )

        active_listing = {**listing, "current_session_id": session_id}
        with mock.patch.object(
            self.manager, "list_runtime_sessions", return_value=active_listing,
        ), mock.patch.object(self.manager, "exec_workspace_command") as execute, self.assertRaisesRegex(
            RuntimeError, "session_active",
        ):
            self.manager.delete_runtime_session(data)
        execute.assert_not_called()


if __name__ == "__main__":
    unittest.main()
