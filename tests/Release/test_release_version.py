"""Release tags use vYYYY.MM.NN, where NN numbers that month's releases from 01."""

import importlib.util
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest


SCRIPT = Path(__file__).resolve().parents[2] / "scripts/release-version.py"
SPEC = importlib.util.spec_from_file_location("release_version", SCRIPT)
VERSIONS = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(VERSIONS)


class ReleaseVersionTest(unittest.TestCase):
    def test_next_version_counts_releases_within_the_month_not_days(self):
        tags = ["v2026.08.01", "v2026.09.01", "v2026.09.02", "v0.2", "v1.0.0", "v2026.09.x", "notes"]
        self.assertEqual("2026.09.03", VERSIONS.next_version(tags, "2026.09"))
        self.assertEqual("2026.10.01", VERSIONS.next_version(tags, "2026.10"))
        # Gaps are allowed; numbering continues after the highest release.
        self.assertEqual("2026.08.08", VERSIONS.next_version(["v2026.08.01", "v2026.08.07"], "2026.08"))

    def test_next_version_refuses_bad_months_and_a_full_month(self):
        for month in ("2026.9", "2026.13", "2026-09", "1999.09"):
            with self.subTest(month=month), self.assertRaises(ValueError):
                VERSIONS.next_version([], month)
        with self.assertRaisesRegex(ValueError, "99 releases"):
            VERSIONS.next_version(["v2026.09.99"], "2026.09")

    def test_check_accepts_only_calendar_tags(self):
        for tag in ("v2026.09.01", "v2026.12.10", "v2099.01.99"):
            with self.subTest(tag=tag):
                self.assertEqual(0, self.run_script("check", tag).returncode)
        for tag in ("2026.09.01", "v2026.9.1", "v2026.09.1", "v2026.09.00", "v2026.00.01", "v2026.09.01-rc1", "v1.2.3", "v0.2"):
            with self.subTest(tag=tag):
                self.assertEqual(1, self.run_script("check", tag).returncode)

    def test_next_reads_existing_tags_from_git(self):
        with tempfile.TemporaryDirectory() as directory:
            git = ["git", "-C", directory, "-c", "user.name=Release Test", "-c", "user.email=release@example.test", "-c", "commit.gpgSign=false", "-c", "tag.gpgSign=false"]
            subprocess.run(["git", "init", "--quiet", directory], check=True)
            subprocess.run([*git, "commit", "--quiet", "--allow-empty", "-m", "fixture"], check=True)
            for tag in ("v2026.09.01", "v2026.09.02"):
                subprocess.run([*git, "tag", tag], check=True)
            result = self.run_script("next", "--month", "2026.09", cwd=directory)
            self.assertEqual(0, result.returncode, result.stderr)
            self.assertEqual("v2026.09.03\n", result.stdout)

    def run_script(self, *arguments, cwd=None):
        return subprocess.run([sys.executable, str(SCRIPT), *arguments], capture_output=True, text=True, cwd=cwd)


if __name__ == "__main__":
    unittest.main()
