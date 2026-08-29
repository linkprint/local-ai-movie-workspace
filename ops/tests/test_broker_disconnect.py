#!/usr/bin/env python3
"""Cancellation tests for the Qwen Broker proxy."""

from __future__ import annotations

import importlib.util
import os
import pathlib
import socket
import tempfile
import time
import unittest
from unittest import mock


ROOT = pathlib.Path(__file__).resolve().parents[2]


class BrokerDisconnectTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.temporary = tempfile.TemporaryDirectory()
        secret_root = pathlib.Path(cls.temporary.name)
        broker_secret = secret_root / "broker"
        manager_secret = secret_root / "manager"
        broker_secret.write_text("b" * 64, encoding="ascii")
        manager_secret.write_text("m" * 64, encoding="ascii")
        os.environ["MOVIE_BROKER_SECRET_FILE"] = str(broker_secret)
        os.environ["MOVIE_BROKER_MANAGER_SECRET_FILE"] = str(manager_secret)
        spec = importlib.util.spec_from_file_location(
            "movie_broker_disconnect_test", ROOT / "images/control/broker.py"
        )
        cls.broker = importlib.util.module_from_spec(spec)
        assert spec.loader is not None
        spec.loader.exec_module(cls.broker)

    @classmethod
    def tearDownClass(cls) -> None:
        cls.temporary.cleanup()

    def test_workspace_disconnect_closes_qwen_upstream(self) -> None:
        client, peer = socket.socketpair()
        upstream = mock.Mock()
        cancellation = self.broker.ClientDisconnectCancellation(client)
        cancellation.attach_connection(upstream)
        cancellation.start()
        try:
            peer.close()
            self.assertTrue(cancellation.cancelled.wait(timeout=2))
            upstream.close.assert_called()
        finally:
            cancellation.stop()
            client.close()

    def test_active_broker_lease_cannot_be_replaced_by_a_second_runtime(self) -> None:
        state_path = pathlib.Path(self.temporary.name) / "broker-state.json"
        self.broker.STATE_PATH = state_path
        now = int(time.time())
        first_reservation = "12345678-1234-4123-8123-123456789abc"
        second_reservation = "22345678-1234-4123-8123-123456789abc"
        first_user = "32345678-1234-4123-8123-123456789abc"
        second_user = "42345678-1234-4123-8123-123456789abc"
        first_runtime = "52345678-1234-4123-8123-123456789abc"
        second_runtime = "62345678-1234-4123-8123-123456789abc"

        self.broker.register_active_claim(
            first_reservation,
            first_user,
            first_runtime,
            1,
            "a" * 96,
            now + 3600,
            now=now,
        )
        with self.assertRaisesRegex(ValueError, "broker_occupied"):
            self.broker.register_active_claim(
                second_reservation,
                second_user,
                second_runtime,
                1,
                "b" * 96,
                now + 3600,
                now=now,
            )

        active = self.broker.load_state()["active"]
        self.assertEqual(active["reservation_id"], first_reservation)
        self.assertEqual(active["runtime_id"], first_runtime)


if __name__ == "__main__":
    unittest.main()
