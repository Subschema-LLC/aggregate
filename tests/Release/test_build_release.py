"""Release archives must not leak deployment state or depend on a Git checkout."""

import hashlib
import importlib.util
import json
from pathlib import Path
import stat
import tempfile
import unittest
import zipfile


SCRIPT = Path(__file__).resolve().parents[2] / "scripts/build-release.py"
SPEC = importlib.util.spec_from_file_location("build_release", SCRIPT)
BUILDER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(BUILDER)


class ReleaseBuildTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="aggregate-release-test-")
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.source = self.root / "source"
        for relative in BUILDER.REQUIRED_FILES:
            self.write(relative, "prepared input\n")
        self.write("composer.json", json.dumps({"require": {"php": ">=8.2"}}))
        self.write("composer.lock", json.dumps({"packages": [{"name": "symfony/runtime"}], "packages-dev": [{"name": "phpunit/phpunit"}]}))
        self.write("vendor/composer/installed.json", json.dumps({"dev": False, "packages": [{"name": "symfony/runtime"}]}))
        self.write("vendor/composer/platform_check.php", '<?php if (!(PHP_VERSION_ID >= 80200)) { throw new RuntimeException("PHP 8.2 is required."); }')
        self.write("public/assets/manifest.json", json.dumps({"app.js": "/assets/app-123.js"}))
        self.write("public/assets/app-123.js", "console.log('app');\n")
        for name, manifest in (("aggregate", "manifest.json"), ("consent", "consent-manifest.json"), ("tag-manager", "tag-manager-manifest.json")):
            self.write("var/browser/" + manifest, json.dumps({
                "format": 1,
                "sourceSha256": self.digest("public/" + name + ".js"),
                "templateSha256": self.digest("var/browser/" + name + ".template.min.js"),
                **({"stylesheetSha256": self.digest("public/consent.css")} if name == "consent" else {}),
            }))

        self.write("var/browser/standalone-consent-manifest.json", json.dumps({
            "format": 1,
            "sourceSha256": self.digest("micro-consent-dropins/js/consent-ui.js"),
            "adapterSha256": self.digest("micro-consent-dropins/js/aggregate-consent.js"),
            "stylesheetSha256": self.digest("micro-consent-dropins/css/consent-ui.css"),
            "templateSha256": self.digest("var/browser/standalone-consent.template.min.js"),
        }))

    def write(self, relative, content):
        path = self.source / relative
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(content)

    def digest(self, relative):
        return hashlib.sha256((self.source / relative).read_bytes()).hexdigest()

    def build(self, output="release", **overrides):
        arguments = dict(source=self.source, output=self.root / output, version="v1.2.3", branch="master", commit="a" * 40, built_at="2026-09-14T12:00:00Z")
        arguments.update(overrides)
        return BUILDER.build_release(**arguments)

    def test_installable_archive_excludes_operator_data_and_contains_matching_metadata(self):
        for relative in (
            ".env", ".env.local", ".env.local.php", ".env.prod", ".env.test", ".git/config",
            "config/aggregate.yaml", "config/aggregate_prod.yaml", "config/websites.yaml", "config/reference.php",
            "config/secrets/prod/prod.decrypt.private.php", "config/release-signing.key",
            "public/index_test.php", "public/uploads/private.txt", "var/data.db",
            "var/log/prod.log", "var/cache/prod/container.php", "var/branding/logo.png",
            "var/browser/unrelated-secret.json", "tests/private.txt", "node_modules/private.txt",
            "src/DataFixtures/AppFixtures.php", "docs/install_fresh.sql",
            "docs/examples/private.json", "docs/examples/nested/private.json", "docs/local/private.md",
        ):
            self.write(relative, "deployment-only-secret")
        self.write("config/release-signing.pub", "public verification key")
        ecommerce_example = (SCRIPT.parent.parent / "docs/examples/ecommerce-purchase.json").read_text(encoding="utf-8")
        self.write("docs/examples/ecommerce-purchase.json", ecommerce_example)
        package, manifest_path = self.build()
        manifest = json.loads(manifest_path.read_bytes())
        self.assertEqual(package.name, "aggregate-1.2.3.zip")
        self.assertEqual(hashlib.sha256(package.read_bytes()).hexdigest(), manifest["package"]["sha256"])
        self.assertEqual(package.stat().st_size, manifest["package"]["size"])
        with zipfile.ZipFile(package) as archive:
            names = archive.namelist()
            self.assertIn("vendor/autoload.php", names)
            self.assertIn("config/release-signing.pub", names)
            self.assertIn("public/assets/app-123.js", names)
            self.assertIn("var/browser/aggregate.template.min.js", names)
            self.assertIn("LICENSE", names)
            self.assertIn("js/LICENSE.txt", names)
            self.assertIn("public/consent.css", names)
            self.assertIn("var/browser/standalone-consent.template.min.js", names)
            self.assertIn("var/browser/standalone-consent-manifest.json", names)
            self.assertIn("micro-consent-dropins/js/aggregate-consent.js", names)
            for name in ("consent", "tag-manager"):
                self.assertIn("public/" + name + ".js", names)
                self.assertIn("public/" + name + ".min.js", names)
                self.assertIn("var/browser/" + name + ".template.min.js", names)
                self.assertIn("var/browser/" + name + "-manifest.json", names)
            self.assertEqual(ecommerce_example.encode("utf-8"), archive.read("docs/examples/ecommerce-purchase.json"))
            for name in names:
                self.assertNotIn(b"deployment-only-secret", archive.read(name), name)
                self.assertFalse(name.startswith("aggregate/"))
            self.assertIn(b"APP_ENV=prod\n", archive.read(".env"))
            self.assertIn(b"APP_SECRET=\n", archive.read(".env"))
            embedded = json.loads(archive.read("release.json"))
            self.assertEqual({key: value for key, value in manifest.items() if key != "package"}, embedded)
            self.assertEqual(stat.S_IFREG | 0o755, archive.getinfo("bin/console").external_attr >> 16)

    def test_archive_ships_config_defaults_and_an_inventory_of_every_file(self):
        self.write("config/navigation.yaml", "parameters: {}\n")
        self.write("config/goals.yaml", "parameters: {}\n")
        for relative in ("config/goals.local.yaml", "config/navigation.local.yaml"):
            self.write(relative, "operator override do-not-package")
        package, _ = self.build()
        with zipfile.ZipFile(package) as archive:
            names = set(archive.namelist())
            for relative in ("config/quick_search.yaml", "config/maintenance.php", "config/goals.yaml", "config/navigation.yaml"):
                self.assertIn(relative, names)
            self.assertNotIn("config/goals.local.yaml", names)
            inventory = json.loads(archive.read("release-files.json"))
            self.assertEqual(1, inventory["schema"])
            self.assertEqual("1.2.3", inventory["version"])
            self.assertEqual(names - {"release-files.json"}, set(inventory["files"]))
            for name, digest in inventory["files"].items():
                self.assertEqual(hashlib.sha256(archive.read(name)).hexdigest(), digest, name)

    def test_refuses_operator_paths_in_packaged_trees(self):
        for relative in ("var/browser/aggregate.template.min.js", "config/services.yaml"):
            self.assertFalse(BUILDER.is_protected(relative), relative)
        for relative in (".env.local", ".env.prod.local", "config/goals.local.yaml", "config/aggregate_prod.yaml", "config/tag-manager/sites/a.yaml", "var/data.db"):
            self.assertTrue(BUILDER.is_protected(relative), relative)
        original = BUILDER.SOURCE_TREES
        BUILDER.SOURCE_TREES = original + ("config/secrets",)
        self.addCleanup(setattr, BUILDER, "SOURCE_TREES", original)
        self.write("config/secrets/prod/prod.decrypt.private.php", "secret")
        with self.assertRaisesRegex(ValueError, "operator-owned"):
            self.build()

    def test_archive_excludes_all_site_tag_and_consent_configuration(self):
        private_configuration = "site-specific-operator-configuration-do-not-package"
        for site_id in ("a" * 24, "b" * 24):
            for suffix in ("", "_prod", "_test"):
                self.write(
                    f"config/tag-manager/sites/{site_id}{suffix}.yaml",
                    "consent_manager:\n  enabled: true\n  name: " + private_configuration
                    + "\ntag_manager:\n  enabled: true\n  tags: []\n",
                )
        self.write("config/tag-manager/sites/private/backup.yaml", private_configuration)
        self.write("config/tag-manager/sites/.operator-notes", private_configuration)

        package, _ = self.build()
        with zipfile.ZipFile(package) as archive:
            self.assertIn("public/tag-manager.js", archive.namelist())
            self.assertIn("public/consent.js", archive.namelist())
            for name in archive.namelist():
                self.assertFalse(name.startswith("config/tag-manager/sites/"), name)
                self.assertNotIn(private_configuration.encode("utf-8"), archive.read(name), name)

    def test_builds_are_reproducible_with_fixed_timestamp_and_refuse_overwrite(self):
        first, first_manifest = self.build("one")
        second, second_manifest = self.build("two")
        self.assertEqual(first.read_bytes(), second.read_bytes())
        self.assertEqual(first_manifest.read_bytes(), second_manifest.read_bytes())
        with self.assertRaisesRegex(ValueError, "already exist"):
            self.build("one")

    def test_refuses_stale_standalone_adapter(self):
        self.write("micro-consent-dropins/js/aggregate-consent.js", "changed adapter")
        with self.assertRaisesRegex(ValueError, "Standalone consent assets are stale"):
            self.build()

    def test_refuses_development_vendor_directory(self):
        self.write("vendor/composer/installed.json", '{"dev": true, "packages": []}')
        with self.assertRaisesRegex(ValueError, "Production vendor"):
            self.build()

    def test_refuses_leftover_development_dependency_in_metadata(self):
        self.write("vendor/composer/installed.json", json.dumps({"dev": False, "packages": [{"name": "symfony/runtime"}, {"name": "phpunit/phpunit"}]}))
        with self.assertRaisesRegex(ValueError, "development dependencies"):
            self.build()

    def test_refuses_leftover_development_package_directory(self):
        self.write("vendor/phpunit/phpunit/phpunit", "leftover development binary")
        with self.assertRaisesRegex(ValueError, "leftover development"):
            self.build()

    def test_refuses_stale_production_dependency_version(self):
        self.write("composer.lock", json.dumps({"packages": [{"name": "symfony/runtime", "version": "v7.4.2"}]}))
        self.write("vendor/composer/installed.json", json.dumps({"dev": False, "packages": [{"name": "symfony/runtime", "version": "v7.4.1"}]}))
        with self.assertRaisesRegex(ValueError, "versions do not match"):
            self.build()

    def test_manifest_reflects_composer_runtime_minimum_and_production_extensions(self):
        self.write("vendor/composer/platform_check.php", '<?php if (!(PHP_VERSION_ID >= 80302)) { throw new RuntimeException("PHP 8.3.2 is required."); }')
        self.write("composer.json", json.dumps({"require": {"php": ">=8.2", "ext-gd": "*"}, "require-dev": {"ext-pcov": "*"}}))
        self.write("composer.lock", json.dumps({"packages": [{"name": "symfony/runtime", "require": {"ext-filter": "*"}}]}))
        _, manifest_path = self.build()
        requirements = json.loads(manifest_path.read_text())["requirements"]
        self.assertEqual(requirements["php"], ">=8.3.2")
        self.assertIn("gd", requirements["extensions"])
        self.assertIn("filter", requirements["extensions"])
        self.assertIn("sodium", requirements["extensions"])
        self.assertNotIn("pcov", requirements["extensions"])

    def test_retains_application_php_baseline_even_if_dependencies_allow_older_php(self):
        self.write("vendor/composer/platform_check.php", '<?php if (!(PHP_VERSION_ID >= 80100)) { exit(1); }')
        _, manifest_path = self.build()
        self.assertEqual(json.loads(manifest_path.read_text())["requirements"]["php"], ">=8.2")

    def test_refuses_missing_or_unsupported_platform_check(self):
        (self.source / "vendor/composer/platform_check.php").unlink()
        with self.assertRaisesRegex(ValueError, "Missing required"):
            self.build()
        for checker in ('<?php /* disabled */', '<?php if (PHP_VERSION_ID < 80400) {}'):
            with self.subTest(checker=checker):
                self.write("vendor/composer/platform_check.php", checker)
                with self.assertRaisesRegex(ValueError, "simple minimum"):
                    self.build()

    def test_refuses_missing_or_stale_built_assets(self):
        self.write("public/aggregate.js", "updated source")
        with self.assertRaisesRegex(ValueError, "stale"):
            self.build()

    def test_refuses_stale_drop_in_sources_and_templates(self):
        for name in ("consent", "tag-manager"):
            for relative in ("public/" + name + ".js", "var/browser/" + name + ".template.min.js"):
                with self.subTest(relative=relative):
                    original = (self.source / relative).read_text()
                    self.write(relative, "updated drop-in")
                    with self.assertRaisesRegex(ValueError, "stale"):
                        self.build()
                    self.write(relative, original)

    def test_refuses_missing_or_stale_consent_stylesheet(self):
        self.write("public/consent.css", "updated stylesheet")
        with self.assertRaisesRegex(ValueError, "stale"):
            self.build()
        (self.source / "public/consent.css").unlink()
        with self.assertRaisesRegex(ValueError, "Missing required"):
            self.build()

    def test_refuses_missing_tracker_license(self):
        (self.source / "js/LICENSE.txt").unlink()
        with self.assertRaisesRegex(ValueError, "Missing required"):
            self.build()

    def test_refuses_missing_compiled_asset_and_manifest_traversal(self):
        (self.source / "public/assets/app-123.js").unlink()
        with self.assertRaisesRegex(ValueError, "Missing required"):
            self.build()
        self.write("public/assets/manifest.json", '{"app.js":"/assets/../../.env"}')
        with self.assertRaisesRegex(ValueError, "Invalid compiled asset"):
            self.build()

    def test_refuses_symlink_to_secret(self):
        secret = self.root / "outside-secret"
        secret.write_text("private-key")
        (self.source / "src/link.php").symlink_to(secret)
        with self.assertRaisesRegex(ValueError, "Symlinks"):
            self.build()

    def test_refuses_symlinked_input_directory(self):
        secret = self.root / "outside-directory"
        secret.mkdir()
        (self.source / "vendor/private").symlink_to(secret, target_is_directory=True)
        with self.assertRaisesRegex(ValueError, "Symlinks"):
            self.build()

    def test_refuses_unsafe_metadata(self):
        for values in ({"version": "../../bad"}, {"version": "1.2.3-beta.1"}, {"version": "1234567890.1.2"}, {"branch": "../bad"}, {"branch": "master\nevil"}, {"branch": "a" * 256}, {"branch": "HEAD"}, {"commit": "short"}, {"built_at": "2026-09-14T12:00:00"}):
            with self.subTest(values=values), self.assertRaises(ValueError):
                self.build(**values)

    def test_branch_rules_allow_same_literal_names_as_application_settings(self):
        for branch in ("master", "releases/stable", "_release", "release+production", "équipe/stable", "a" * 255):
            with self.subTest(branch=branch):
                self.assertEqual(BUILDER.validate_branch(branch), branch)
        for branch in ("é" * 128, "name@{1}", "ref/", "ref//name", "ref.lock", "-name"):
            with self.subTest(branch=branch), self.assertRaises(ValueError):
                BUILDER.validate_branch(branch)


if __name__ == "__main__":
    unittest.main()
