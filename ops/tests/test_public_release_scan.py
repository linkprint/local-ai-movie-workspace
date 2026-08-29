import importlib.util
import pathlib
import sys
import unittest


MODULE_PATH = pathlib.Path(__file__).with_name("public_release_scan.py")
SPEC = importlib.util.spec_from_file_location("movie_public_release_scan", MODULE_PATH)
assert SPEC is not None and SPEC.loader is not None
SCAN = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = SCAN
SPEC.loader.exec_module(SCAN)


class PublicReleaseScanPathTest(unittest.TestCase):
    def categories(self, path: str) -> set[str]:
        return {finding.category for finding in SCAN.path_findings(path)}

    def test_schema_migration_source_is_allowed(self):
        self.assertEqual(
            set(),
            self.categories("app/database/migrations/2026_01_01_create_table.php"),
        )

    def test_database_exports_are_rejected(self):
        for path in (
            "database/production.sql",
            "backups/portal.dump",
            "exports/users.backup",
            "runtime/local.sqlite3",
        ):
            with self.subTest(path=path):
                self.assertIn(
                    "forbidden database export filename suffix",
                    self.categories(path),
                )

    def test_postgres_runtime_directories_are_rejected(self):
        self.assertIn(
            "forbidden database runtime path",
            self.categories("pgdata/base/16384/2609"),
        )

    def text_categories(self, text: str, path: str = "example.txt") -> set[str]:
        return {
            finding.category
            for finding in SCAN.text_findings(path, text.encode("utf-8"))
        }

    def test_personal_paths_and_operator_identifiers_are_rejected_without_public_hashes(self):
        self.assertIn(
            "personal filesystem path",
            self.text_categories("workspace=" + "/" + "Users" + "/operator/project"),
        )
        findings = SCAN.text_findings(
            "example.txt",
            b"The private deployment codename is Blue Orchard.",
            ("Blue Orchard",),
        )
        self.assertEqual({"operator-provided private identifier"}, {item.category for item in findings})
        path_findings = SCAN.text_findings(
            "docs/Blue-Orchard-runbook.md",
            b"public content",
            ("Blue-Orchard",),
        )
        self.assertEqual(
            {"operator-provided private identifier in path"},
            {item.category for item in path_findings},
        )

    def test_additional_key_and_network_formats_are_rejected(self):
        cases = {
            "APP_KEY=base64:" + ("A" * 44): "Laravel application key",
            "HF_TOKEN=hf_" + ("A" * 32): "Hugging Face token",
            "host=" + "2001" + ":4860:4860::8888": "public IPv6 address",
            "adapter=" + ":".join(("00", "11", "22", "33", "44", "55")): "hardware MAC address",
            'account={"type":"service_' + 'account"}': "Google service account",
        }
        for text, category in cases.items():
            with self.subTest(category=category):
                self.assertIn(category, self.text_categories(text))

    def test_nonempty_example_credentials_are_rejected_but_empty_values_are_allowed(self):
        self.assertIn(
            "nonempty credential assignment",
            self.text_categories("SERVICE_PASSWORD=not-for-source-control"),
        )
        self.assertNotIn(
            "nonempty credential assignment",
            self.text_categories("SERVICE_PASSWORD="),
        )

    def test_non_example_email_bypass_patterns_are_rejected(self):
        self.assertIn("non-example email", self.text_categories("ops@" + "internal.service"))
        self.assertIn("non-example email", self.text_categories("ops-prod@" + "internal.service"))
        self.assertIn("non-example email", self.text_categories("ops@" + "example..com"))
        self.assertNotIn(
            "non-example email",
            self.text_categories("movie-model-tunnel@qwen.service"),
        )


if __name__ == "__main__":
    unittest.main()
