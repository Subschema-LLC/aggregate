"""Publisher YAML must not silently switch invalid configuration to master."""

from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest


PROJECT = Path(__file__).resolve().parents[2]


@unittest.skipUnless(shutil.which("php") and shutil.which("git") and (PROJECT / "vendor/autoload.php").is_file(), "PHP, Git, and Composer dependencies are required")
class ReleaseBranchTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="aggregate-branch-test-")
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        for directory in ("scripts", "vendor", "config"):
            (self.root / directory).mkdir()
        shutil.copyfile(PROJECT / "scripts/release-branch.php", self.root / "scripts/release-branch.php")
        # Keep tests isolated from the real publishing YAML while loading the
        # actual application branch validator and installed Symfony components.
        path = str(PROJECT / "vendor/autoload.php").replace("\\", "\\\\").replace("'", "\\'")
        (self.root / "vendor/autoload.php").write_text("<?php require '" + path + "';\n")

    def run_helper(self, yaml):
        (self.root / "config/release.yaml").write_text(yaml)
        return subprocess.run(["php", str(self.root / "scripts/release-branch.php")], cwd=self.root, capture_output=True, text=True)

    def test_accepts_master_default_and_configured_literal_branches(self):
        for yaml, branch in (
            ("{}", "master"),
            ("branch: master\n", "master"),
            ("branch: releases/stable\n", "releases/stable"),
            ("branch: 'release+production'\n", "release+production"),
            ("branch: master\nminimum_php_version: '8.2'\n", "master"),
        ):
            with self.subTest(yaml=yaml):
                result = self.run_helper(yaml)
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertEqual(result.stdout, branch + "\n")

    def test_rejects_wrong_roots_unknown_keys_and_invalid_explicit_branches(self):
        for yaml in ("", "null", "[]", "master", "[master]", "branch: [", "brnach: master", "branch: master\nother: value", "branch: null", "branch: ''", "branch: 123", "branch: {}", "branch: HEAD", "branch: ../master", "branch: " + "a" * 256):
            with self.subTest(yaml=yaml):
                result = self.run_helper(yaml)
                self.assertNotEqual(result.returncode, 0)
                self.assertEqual(result.stdout, "")


if __name__ == "__main__":
    unittest.main()
