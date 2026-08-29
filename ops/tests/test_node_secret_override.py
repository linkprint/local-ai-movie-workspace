import importlib.util
import pathlib
import sys
import unittest


MODULE_PATH = pathlib.Path(__file__).parents[1] / "render-node-secret-override.py"
SPEC = importlib.util.spec_from_file_location("movie_node_secret_override", MODULE_PATH)
assert SPEC is not None and SPEC.loader is not None
MODULE = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = MODULE
SPEC.loader.exec_module(MODULE)


class NodeSecretOverrideTest(unittest.TestCase):
    def test_renders_uuid_bound_router_secret_without_reading_the_file(self):
        node_id = "30000000-0000-4000-8000-000000000300"
        rendered = MODULE.render([(node_id, "node_broker_hmac_secret.300")])

        self.assertIn(f"target: node_{node_id}", rendered)
        self.assertIn("file: ./env/node_broker_hmac_secret.300", rendered)
        self.assertNotIn("secret-value", rendered)

    def test_rejects_duplicate_nodes_and_unsafe_paths(self):
        node_id = "30000000-0000-4000-8000-000000000300"
        with self.assertRaisesRegex(ValueError, "duplicate"):
            MODULE.render([(node_id, "one"), (node_id, "two")])
        for value in (f"{node_id}=../unsafe-file", "invalid=node-file"):
            with self.subTest(value=value), self.assertRaises(Exception):
                MODULE.parse_mapping(value)


if __name__ == "__main__":
    unittest.main()
