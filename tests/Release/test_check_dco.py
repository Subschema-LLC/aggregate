"""Pull requests from forks need a DCO sign-off from each commit's author."""

import importlib.util
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest


SCRIPT = Path(__file__).resolve().parents[2] / "scripts/check-dco.py"
SPEC = importlib.util.spec_from_file_location("check_dco", SCRIPT)
DCO = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(DCO)


def commit(email="ada@example.test", message="Fix a step\n"):
    return DCO.Commit("0" * 40, "Ada Lovelace", email, message.splitlines()[0] if message else "", message)


class SignOffTest(unittest.TestCase):
    def test_a_trailer_from_the_author_counts(self):
        self.assertTrue(DCO.is_signed_off(commit(message="Fix a step\n\nSigned-off-by: Ada Lovelace <ada@example.test>\n")))
        # Case and surrounding spaces do not matter, and other trailers may follow.
        self.assertTrue(DCO.is_signed_off(commit(
            email="Ada@Example.test",
            message="Fix\n\nsigned-off-by:  Ada  <ADA@example.test> \nCo-authored-by: Bo <bo@example.test>\n",
        )))

    def test_a_missing_or_someone_elses_sign_off_does_not_count(self):
        for message in (
            "Fix a step\n",
            "Fix a step\n\nSigned-off-by: Bo <bo@example.test>\n",
            "Fix a step\n\nSigned-off-by: Ada Lovelace\n",
            "Fix a step\n\nSigned-off-by: Ada Lovelace <ada@example.test> and more\n",
            "Fix a step\n\nCo-authored-by: Ada Lovelace <ada@example.test>\n",
            "Fix a step\n\nPlease add Signed-off-by: Ada Lovelace <ada@example.test>\n",
        ):
            with self.subTest(message=message):
                self.assertFalse(DCO.is_signed_off(commit(message=message)))

    def test_annotations_cannot_start_another_workflow_command(self):
        escaped = DCO.escape_annotation("100% done\r\n::add-mask::secret")
        self.assertEqual("100%25 done%0D%0A::add-mask::secret", escaped)
        self.assertNotIn("\n", escaped)

    def test_the_message_is_read_last_so_it_cannot_shift_fields(self):
        parsed = DCO.parse_commit("a" * 40, "Ada\x00ada@example.test\x00Subject\x00Subject\n\nbody\n")
        self.assertEqual(("Ada", "ada@example.test", "Subject"), (parsed.author_name, parsed.author_email, parsed.subject))
        self.assertEqual("Subject\n\nbody\n", parsed.message)


class RepositoryTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.path = self.directory.name
        subprocess.run(["git", "init", "--quiet", "--initial-branch=development", self.path], check=True)
        self.commit("Base", name="Maintainer", email="maintainer@example.test")
        self.base = self.rev_parse("HEAD")

    def git(self, *arguments, name="Ada Lovelace", email="ada@example.test"):
        return subprocess.run(
            ["git", "-C", self.path, "-c", f"user.name={name}", "-c", f"user.email={email}", "-c", "commit.gpgSign=false", *arguments],
            check=True, capture_output=True, text=True,
        )

    def commit(self, message, sign_off=False, **identity):
        self.git("commit", "--quiet", "--allow-empty", *(["--signoff"] if sign_off else []), "-m", message, **identity)

    def rev_parse(self, ref):
        return self.git("rev-parse", ref).stdout.strip()

    def run_script(self, *arguments, actions=False):
        environment = {key: value for key, value in os.environ.items() if key != "GITHUB_ACTIONS"}
        if actions:
            environment["GITHUB_ACTIONS"] = "true"
        return subprocess.run([sys.executable, str(SCRIPT), *arguments], capture_output=True, text=True, cwd=self.path, env=environment)

    def test_passes_when_every_commit_is_signed_off(self):
        self.commit("First", sign_off=True)
        self.commit("Second", sign_off=True)
        result = self.run_script(self.base, "HEAD")
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        self.assertIn("Every commit is signed off (2 checked).", result.stdout)

    def test_lists_each_unsigned_commit_and_how_to_fix_it(self):
        self.commit("Signed", sign_off=True)
        self.commit("Unsigned change")
        result = self.run_script(self.base, "HEAD", actions=True)
        self.assertEqual(1, result.returncode, result.stdout + result.stderr)
        self.assertIn('::error title=DCO sign-off::Missing sign-off: ', result.stdout)
        self.assertIn('"Unsigned change" by Ada Lovelace <ada@example.test>', result.stdout)
        self.assertNotIn('"Signed"', result.stdout)
        self.assertIn("1 of 2 commits", result.stdout)
        self.assertIn("git rebase --signoff", result.stdout)

    def test_ignores_merge_commits_and_commits_already_on_the_base(self):
        self.git("checkout", "--quiet", "-b", "feature")
        self.commit("Contribution", sign_off=True)
        self.git("checkout", "--quiet", "development")
        self.commit("Maintainer change on development", name="Maintainer", email="maintainer@example.test")
        new_base = self.rev_parse("HEAD")
        self.git("checkout", "--quiet", "feature")
        self.git("merge", "--quiet", "--no-ff", "--no-edit", "development")
        result = self.run_script(new_base, "HEAD")
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        self.assertIn("Every commit is signed off (1 checked).", result.stdout)

    def test_an_empty_range_passes_and_a_bad_ref_is_an_error(self):
        empty = self.run_script(self.base, self.base)
        self.assertEqual(0, empty.returncode, empty.stderr)
        self.assertIn("No commits to check.", empty.stdout)
        bad = self.run_script(self.base, "no-such-ref")
        self.assertEqual(2, bad.returncode)
        self.assertIn("check-dco:", bad.stderr)


if __name__ == "__main__":
    unittest.main()
