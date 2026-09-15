"""Exercise detached signatures and key handling using ephemeral test keys."""

import base64
import json
import os
from pathlib import Path
import shutil
import stat
import subprocess
import tempfile
import unittest


SCRIPT = Path(__file__).resolve().parents[2] / "scripts/sign-release.php"


@unittest.skipUnless(shutil.which("php"), "PHP is required for release signing")
class ReleaseSigningTest(unittest.TestCase):
    def setUp(self):
        sodium = subprocess.run(["php", "-r", "exit(extension_loaded('sodium') ? 0 : 1);"], capture_output=True)
        if sodium.returncode != 0:
            self.skipTest("PHP sodium is required for release signing")
        self.temporary = tempfile.TemporaryDirectory(prefix="aggregate-signing-test-")
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.private = self.root / "private.key"
        self.public = self.root / "public.key"
        self.manifest = self.root / "aggregate-release.json"
        self.manifest.write_text(json.dumps({"schema": 1, "package": {"sha256": "a" * 64}}) + "\n")
        self.generate(self.private, self.public)

    def run_signer(self, arguments, private=None):
        environment = os.environ.copy()
        environment.pop("RELEASE_SIGNING_PRIVATE_KEY", None)
        if private is not None:
            environment["RELEASE_SIGNING_PRIVATE_KEY"] = private.read_text().strip()
        return subprocess.run(["php", str(SCRIPT), *map(str, arguments)], env=environment, capture_output=True, text=True)

    def generate(self, private, public):
        result = self.run_signer(["--generate-keypair", private, public])
        self.assertEqual(result.returncode, 0, result.stderr)

    def test_private_key_is_restricted_and_existing_keys_are_never_overwritten(self):
        self.assertEqual(stat.S_IMODE(self.private.stat().st_mode), 0o600)
        self.assertEqual(len(base64.b64decode(self.private.read_bytes(), validate=False)), 64)
        self.assertEqual(len(base64.b64decode(self.public.read_bytes(), validate=False)), 32)
        before = self.private.read_bytes()
        result = self.run_signer(["--generate-keypair", self.private, self.public])
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(self.private.read_bytes(), before)

    def test_signs_exact_manifest_bytes_and_detects_tampering(self):
        result = self.run_signer([self.manifest, self.public], self.private)
        self.assertEqual(result.returncode, 0, result.stderr)
        signature = self.manifest.with_suffix(".json.sig")
        self.assertEqual(len(base64.b64decode(signature.read_bytes())), 64)
        verify = ["php", "-r", "exit(sodium_crypto_sign_verify_detached(base64_decode(trim(file_get_contents($argv[2]))), file_get_contents($argv[1]), base64_decode(trim(file_get_contents($argv[3])))) ? 0 : 1);", str(self.manifest), str(signature), str(self.public)]
        self.assertEqual(subprocess.run(verify, capture_output=True).returncode, 0)
        self.manifest.write_text(self.manifest.read_text() + " ")
        self.assertEqual(subprocess.run(verify, capture_output=True).returncode, 1)
        result = self.run_signer([self.manifest, self.public], self.private)
        self.assertNotEqual(result.returncode, 0, "Signatures must not be silently overwritten")

    def test_refuses_signing_without_matching_trusted_public_key(self):
        other_private = self.root / "other-private.key"
        other_public = self.root / "other-public.key"
        self.generate(other_private, other_public)
        for public, private in ((self.root / "missing.pub", self.private), (other_public, self.private), (self.public, None)):
            with self.subTest(public=public.name, private=private is not None):
                result = self.run_signer([self.manifest, public], private)
                self.assertNotEqual(result.returncode, 0)
                self.assertFalse(self.manifest.with_suffix(".json.sig").exists())
                self.assertNotIn(self.private.read_text().strip(), result.stdout + result.stderr)


if __name__ == "__main__":
    unittest.main()
