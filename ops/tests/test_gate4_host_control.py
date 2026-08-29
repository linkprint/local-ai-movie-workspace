#!/usr/bin/env python3

from __future__ import annotations

import hashlib
import hmac
import importlib.util
import os
import pathlib
import tempfile
import time
import unittest
from unittest import mock


ROOT = pathlib.Path(__file__).resolve().parents[2]


def load_module(secret: pathlib.Path):
    os.environ["MOVIE_H3_CONTROL_SECRET_FILE"] = str(secret)
    spec = importlib.util.spec_from_file_location("movie_h3_control_under_test", ROOT / "host-control/movie_h3_control.py")
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(module)
    return module


def snapshot(*, qwen="inactive", comfy="inactive", healthy=False, used=100, power=540.0, processes=None, profile=None):
    processes = [] if processes is None else processes
    unknown = [item for item in processes if item["owner"] == "unknown"]
    return {
        "mode": "real",
        "services": {
            "comfyui": {"active": comfy, "healthy": healthy},
            "qwen": {"active": qwen},
        },
        "gpu": {
            "memory_used_mib": used,
            "power_limit_w": power,
            "processes": processes,
        },
        "unknown_processes": unknown,
        "active_profile": profile,
    }


class HostControlTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        root = pathlib.Path(self.temporary.name)
        secret = root / "secret"
        secret.write_text("a" * 64)
        self.module = load_module(secret)
        self.module.LOCK_PATH = root / "operation.lock"
        self.module.PROFILE_PATH = root / "active-profile"

    def tearDown(self):
        self.temporary.cleanup()

    def test_authentication_is_fixed_and_replay_safe(self):
        stamp = int(time.time())
        nonce = "n" * 40
        action = "prepare_h3"
        signature = hmac.new(
            self.module.CONTROL_SECRET,
            f"{stamp}\n{nonce}\n{action}".encode(),
            hashlib.sha256,
        ).hexdigest()
        request = {"timestamp": stamp, "nonce": nonce, "action": action, "signature": signature}
        self.assertEqual(self.module.authenticate(request), action)
        with self.assertRaisesRegex(self.module.RequestError, "replayed_request"):
            self.module.authenticate(request)
        request["nonce"] = "x" * 40
        request["action"] = "systemctl"
        with self.assertRaisesRegex(self.module.RequestError, "unsupported_action"):
            self.module.authenticate(request)

    def test_unknown_process_blocks_without_service_mutation(self):
        unknown = snapshot(processes=[{"owner": "unknown", "pid": 9, "used_memory_mib": 100}])
        with mock.patch.object(self.module, "status_payload", return_value=unknown), \
             mock.patch.object(self.module, "stop_qwen") as stop_qwen, \
             self.assertRaisesRegex(self.module.PolicyError, "unknown_gpu_process"):
            self.module.prepare_comfy("h3")
        stop_qwen.assert_not_called()

    def test_active_h3_is_reused_after_qwen_is_stopped(self):
        first = snapshot(qwen="active", comfy="active", healthy=True, processes=[
            {"owner": "qwen", "pid": 1, "used_memory_mib": 100},
        ])
        cleared = snapshot(comfy="active", healthy=True, used=30000, profile="h3", processes=[
            {"owner": "comfyui", "pid": 2, "used_memory_mib": 29000},
        ])
        with mock.patch.object(self.module, "status_payload", side_effect=[first, cleared]), \
             mock.patch.object(self.module, "stop_qwen") as stop_qwen:
            result = self.module.prepare_comfy("h3")
        stop_qwen.assert_called_once_with()
        self.assertTrue(result["reused_comfyui"])
        self.assertNotIn("idle_readings_mib", result)

    def test_switch_from_image_to_h3_unloads_only_the_cached_model(self):
        current = snapshot(comfy="active", healthy=True, used=30000, profile="image", processes=[
            {"owner": "comfyui", "pid": 2, "used_memory_mib": 29000},
        ])
        switched = snapshot(comfy="active", healthy=True, used=500, profile="h3", processes=[
            {"owner": "comfyui", "pid": 2, "used_memory_mib": 400},
        ])
        with mock.patch.object(self.module, "status_payload", side_effect=[current, current, switched]), \
             mock.patch.object(self.module, "free_comfy_models") as free_models, \
             mock.patch.object(self.module, "set_active_profile") as set_profile:
            result = self.module.prepare_comfy("h3")
        free_models.assert_called_once_with()
        set_profile.assert_called_once_with("h3")
        self.assertEqual(result["unloaded_previous_profile"], "image")
        self.assertFalse(result["restarted_comfyui"])

    def test_switch_recycles_only_fixed_comfy_when_free_retains_image_model(self):
        current = snapshot(comfy="active", healthy=True, used=50000, profile="image", processes=[
            {"owner": "comfyui", "pid": 2, "used_memory_mib": 49900},
        ])
        switched = snapshot(comfy="active", healthy=True, used=500, profile="h3", processes=[
            {"owner": "comfyui", "pid": 3, "used_memory_mib": 400},
        ])
        with mock.patch.object(self.module, "status_payload", side_effect=[current, current]), \
             mock.patch.object(self.module, "free_comfy_models", side_effect=self.module.PolicyError("comfyui_model_unload_timeout")), \
             mock.patch.object(self.module, "recycle_comfy", return_value=(switched, [2, 2])) as recycle:
            result = self.module.prepare_comfy("h3")
        recycle.assert_called_once_with("h3")
        self.assertTrue(result["restarted_comfyui"])
        self.assertFalse(result["reused_comfyui"])
        self.assertEqual(result["idle_readings_mib"], [2, 2])

    def test_recycle_uses_only_fixed_comfy_stop_and_start(self):
        with mock.patch.object(self.module, "run_fixed") as run_fixed, \
             mock.patch.object(self.module, "status_payload", side_effect=[
                 snapshot(used=2), snapshot(used=2), snapshot(used=2),
                 snapshot(comfy="active", healthy=True, used=500, profile="h3", processes=[
                     {"owner": "comfyui", "pid": 3, "used_memory_mib": 400},
                 ]),
             ]), \
             mock.patch.object(self.module, "wait_for_comfy"), \
             mock.patch.object(self.module, "set_active_profile"), \
             mock.patch.object(self.module.time, "sleep"):
            _, readings = self.module.recycle_comfy("h3")
        self.assertEqual(run_fixed.call_args_list, [
            mock.call(("/usr/bin/systemctl", "stop", self.module.COMFY_UNIT), timeout=180),
            mock.call(("/usr/bin/systemctl", "start", self.module.COMFY_UNIT), timeout=60),
        ])
        self.assertEqual(readings, [2, 2])

    def test_inactive_h3_requires_two_idle_samples_before_fixed_start(self):
        before = snapshot(used=120)
        cleared = snapshot(used=130)
        sample_one = snapshot(used=140)
        sample_two = snapshot(used=150)
        ready = snapshot(comfy="active", healthy=True, used=500, profile="image", processes=[
            {"owner": "comfyui", "pid": 2, "used_memory_mib": 400},
        ])
        with mock.patch.object(self.module, "status_payload", side_effect=[before, cleared, sample_one, sample_two, ready]), \
             mock.patch.object(self.module, "run_fixed") as run_fixed, \
             mock.patch.object(self.module, "wait_for_comfy", return_value=ready), \
             mock.patch.object(self.module.time, "sleep") as sleep:
            result = self.module.prepare_comfy("image")
        run_fixed.assert_called_once_with(("/usr/bin/systemctl", "start", self.module.COMFY_UNIT), timeout=60)
        sleep.assert_called_once_with(3)
        self.assertEqual(result["idle_readings_mib"], [140, 150])
        self.assertFalse(result["reused_comfyui"])
        self.assertFalse(result["restarted_comfyui"])

    def test_power_limit_above_550_blocks(self):
        over = snapshot(power=551.0)
        with mock.patch.object(self.module, "status_payload", return_value=over), \
             self.assertRaisesRegex(self.module.PolicyError, "gpu_power_limit_exceeds_550w"):
            self.module.prepare_comfy("h3")


if __name__ == "__main__":
    unittest.main()
