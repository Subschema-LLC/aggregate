#!/usr/bin/env python3
"""Build an installable release from prepared production dependencies and assets.

SPDX-License-Identifier: AGPL-3.0-only
This script never runs Composer, reads deployment env files, or signs artifacts.
Run sign-release.php separately after inspecting the package and manifest.
"""

import argparse
import datetime as dt
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import sys
import zipfile


REPOSITORY = "Subschema-LLC/aggregate"
ROOT_FILES = (
    "LICENSE", "README.md", "DEPLOYMENT.md", "PLESK-DEPLOYMENT.md",
    "AGENTS.md", "CONTRIBUTING.md", "ROADMAP.md", "SECURITY.md", "CODE_OF_CONDUCT.md",
    "composer.json", "composer.lock", "symfony.lock", "importmap.php", "js/LICENSE.txt",
    "install.sh", "plesk-setup.sh",
    "package.json", "package-lock.json", ".env.local.example", ".env.prod.example",
    "bin/console", "public/.htaccess", "public/index.php", "public/robots.txt",
    "public/aggregate.js", "public/aggregate.min.js", "public/internal-traffic-marker.min.js",
    "var/browser/aggregate.template.min.js", "var/browser/manifest.json",
    "public/consent.js", "public/consent.min.js", "public/consent.css", "public/tag-manager.js", "public/tag-manager.min.js",
    "var/browser/consent.template.min.js", "var/browser/consent-manifest.json",
    "var/browser/tag-manager.template.min.js", "var/browser/tag-manager-manifest.json",
    "var/browser/standalone-consent.template.min.js", "var/browser/standalone-consent-manifest.json",
)
CONFIG_FILES = (
    "aggregate.yaml.example", "websites.yaml.example", "bundles.php", "preload.php",
    "services.yaml", "services_dashboard.yaml", "routes_public.yaml", "routes_dashboard.yaml",
    "navigation.yaml", "goals.yaml", "release.yaml", "release-signing.pub",
)
SOURCE_TREES = ("src", "templates", "translations", "migrations", "assets", "micro-consent-dropins", "scripts")
DOCUMENTATION_EXAMPLES = ("docs/examples/ecommerce-purchase.json",)
REQUIRED_FILES = (
    "LICENSE", "js/LICENSE.txt", "composer.json", "composer.lock", "bin/console", "public/index.php",
    "vendor/autoload.php", "vendor/autoload_runtime.php", "vendor/composer/installed.json",
    "vendor/composer/platform_check.php",
    "public/assets/manifest.json", "public/assets/importmap.json", "public/assets/entrypoint.app.json",
    "public/aggregate.js", "public/aggregate.min.js", "public/internal-traffic-marker.min.js",
    "var/browser/aggregate.template.min.js", "var/browser/manifest.json",
    "public/consent.js", "public/consent.min.js", "public/consent.css", "public/tag-manager.js", "public/tag-manager.min.js",
    "var/browser/consent.template.min.js", "var/browser/consent-manifest.json",
    "var/browser/tag-manager.template.min.js", "var/browser/tag-manager-manifest.json",
    "config/aggregate.yaml.example", "config/websites.yaml.example", "config/services.yaml",
    "config/bundles.php", "src/Kernel.php", "importmap.php",
    "var/browser/standalone-consent.template.min.js", "var/browser/standalone-consent-manifest.json",
    "micro-consent-dropins/js/consent-ui.js", "micro-consent-dropins/js/consent-ui.min.js",
    "micro-consent-dropins/js/aggregate-consent.js", "micro-consent-dropins/js/aggregate-consent.min.js",
    "micro-consent-dropins/js/gtm-consent-mode.js", "micro-consent-dropins/js/gtm-consent-mode.min.js",
    "micro-consent-dropins/css/consent-ui.css",
)
PRODUCTION_ENV = """# Aggregate release defaults. Configure .env.local before running /install.
# Generate APP_SECRET with: openssl rand -hex 32
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=
TRUSTED_PROXIES=
DATABASE_URL="sqlite:///%kernel.project_dir%/var/data.db"
MESSENGER_TRANSPORT_DSN=sync://
MAILER_DSN=null://null
"""


def json_bytes(value):
    return (json.dumps(value, indent=2, ensure_ascii=False) + "\n").encode("utf-8")


def validate_branch(branch):
    if (
        not isinstance(branch, str) or not branch or len(branch.encode("utf-8")) > 255
        or branch in ("HEAD", "@") or branch.startswith("-") or branch.endswith(".")
        or re.search(r"[\x00-\x20\x7f~^:?*\[\\]", branch)
        or ".." in branch or "@{" in branch
        or any(not part or part.startswith(".") or part.endswith(".lock") for part in branch.split("/"))
    ):
        raise ValueError("Invalid publishing branch.")
    return branch


def source_file(source, relative):
    path = source / relative
    if any(part.is_symlink() for part in (path, *path.parents) if part != source.parent):
        raise ValueError(f"Symlinks are not allowed in release inputs: {relative}")
    if not path.is_file():
        raise ValueError(f"Missing required production build input: {relative}")
    return path


def read_json(source, relative):
    return json.loads(source_file(source, relative).read_text(encoding="utf-8"))


def production_requirements(source):
    # Composer merges the root and installed dependencies' PHP constraints into
    # this generated runtime check. Do not infer requirements from the builder's
    # own PHP runtime, which could be newer than the supported installation.
    checker = source_file(source, "vendor/composer/platform_check.php").read_text(encoding="utf-8")
    minimum_pattern = r"PHP_VERSION_ID\s*>=\s*([0-9]+)"
    minimums = re.findall(minimum_pattern, checker)
    if not minimums or "PHP_VERSION_ID" in re.sub(minimum_pattern, "", checker):
        raise ValueError("Composer's generated PHP platform check must declare a simple minimum; the release manifest cannot express other PHP version constraints.")
    minimum = max(80200, *(int(value) for value in minimums))
    if minimum > 999999:
        raise ValueError("Composer's PHP minimum cannot be represented by the release manifest schema.")
    major, minor, patch = minimum // 10000, minimum // 100 % 100, minimum % 100
    php = f">={major}.{minor}" + (f".{patch}" if patch else "")

    extensions = {"ctype", "iconv", "pdo", "mbstring", "xml", "curl", "intl", "sodium", "zip"}
    composer = read_json(source, "composer.json")
    lock = read_json(source, "composer.lock")
    for package in (composer, *lock.get("packages", [])):
        for name in package.get("require", {}):
            if name.startswith("ext-"):
                extension = name[4:]
                if not re.fullmatch(r"[a-z][a-z0-9_]{0,63}", extension):
                    raise ValueError(f"PHP extension requirement cannot be represented by the release manifest schema: {name}")
                extensions.add(extension)
    if len(extensions) > 100:
        raise ValueError("Too many PHP extension requirements for the release manifest schema.")
    return {"php": php, "extensions": sorted(extensions)}


def validate_prepared_source(source):
    for relative in REQUIRED_FILES:
        source_file(source, relative)
    installed = read_json(source, "vendor/composer/installed.json")
    if not isinstance(installed, dict) or installed.get("dev") is not False:
        raise ValueError("Production vendor/ is required; run composer install --no-dev in a clean staging directory.")
    lock = read_json(source, "composer.lock")
    packages = installed.get("packages", [])
    installed_names = {package["name"] for package in packages}
    dev_names = {package["name"] for package in lock.get("packages-dev", [])}
    if installed_names & dev_names:
        raise ValueError("Production vendor/ contains development dependencies.")
    locked_packages = {package["name"]: package for package in lock.get("packages", [])}
    if set(locked_packages) != installed_names:
        raise ValueError("Production vendor/ must contain exactly the locked production dependencies.")
    for package in packages:
        if package.get("version") != locked_packages[package["name"]].get("version"):
            raise ValueError("Production vendor/ versions do not match composer.lock.")
    if any((source / "vendor" / name).exists() for name in dev_names):
        raise ValueError("Production vendor/ contains leftover development package directories; use a clean staging directory.")
    for name, manifest in (
        ("aggregate", "manifest.json"),
        ("consent", "consent-manifest.json"),
        ("tag-manager", "tag-manager-manifest.json"),
    ):
        browser = read_json(source, "var/browser/" + manifest)
        inputs = [
            ("public/" + name + ".js", "sourceSha256"),
            ("var/browser/" + name + ".template.min.js", "templateSha256"),
        ]
        if name == "consent":
            inputs.append(("public/consent.css", "stylesheetSha256"))
        for relative, field in inputs:
            if hashlib.sha256(source_file(source, relative).read_bytes()).hexdigest() != browser.get(field):
                raise ValueError("Browser assets are stale; run npm run build:js before packaging.")
    standalone = read_json(source, "var/browser/standalone-consent-manifest.json")
    for relative, field in (
        ("micro-consent-dropins/js/consent-ui.js", "sourceSha256"),
        ("micro-consent-dropins/js/aggregate-consent.js", "adapterSha256"),
        ("micro-consent-dropins/css/consent-ui.css", "stylesheetSha256"),
        ("var/browser/standalone-consent.template.min.js", "templateSha256"),
    ):
        if hashlib.sha256(source_file(source, relative).read_bytes()).hexdigest() != standalone.get(field):
            raise ValueError("Standalone consent assets are stale; run npm run build:js before packaging.")
    asset_manifest = read_json(source, "public/assets/manifest.json")
    if not isinstance(asset_manifest, dict) or not asset_manifest:
        raise ValueError("Compiled Symfony assets are missing.")
    for value in asset_manifest.values():
        # Symfony writes public paths such as /assets/app-hash.js.
        if not isinstance(value, str) or not value.startswith("/assets/") or ".." in Path(value).parts or "\\" in value:
            raise ValueError("Invalid compiled asset path.")
        source_file(source, "public" + value)


def tree_files(source, relative):
    directory = source / relative
    if not directory.exists():
        return
    if directory.is_symlink():
        raise ValueError(f"Symlinks are not allowed in release inputs: {relative}")
    for current, directories, files in os.walk(directory, followlinks=False):
        for name in directories + files:
            if (Path(current) / name).is_symlink():
                raise ValueError(f"Symlinks are not allowed in release inputs: {Path(current) / name}")
        # Ignore VCS/editor metadata even inside downloaded dependency archives.
        directories[:] = sorted(name for name in directories if not name.startswith(".") and name != "__pycache__")
        for name in sorted(files):
            if name.startswith(".") or name.endswith(("~", ".pyc", ".swp")):
                continue
            path = (Path(current) / name).relative_to(source).as_posix()
            if path.startswith("src/DataFixtures/"):
                continue
            yield path


def payload_paths(source):
    paths = {path for path in ROOT_FILES if (source / path).exists()}
    paths.update("config/" + name for name in CONFIG_FILES if (source / "config" / name).exists())
    for relative in (*SOURCE_TREES, "config/packages", "config/routes", "public/assets", "public/bundles", "vendor"):
        paths.update(tree_files(source, relative))
    # Documentation only, excluding local images, DB exports, and development SQL.
    paths.update(path.relative_to(source).as_posix() for path in (source / "docs").glob("*.md") if path.name.lower() != "todo.md")
    paths.update(path for path in DOCUMENTATION_EXAMPLES if (source / path).exists())
    return sorted(paths)


def build_release(source, output, version, branch, commit, built_at=None):
    source = Path(source).resolve()
    output = Path(output).resolve()
    version = version.removeprefix("v")
    if not re.fullmatch(r"(?:0|[1-9][0-9]{0,8})\.(?:0|[1-9][0-9]{0,8})\.(?:0|[1-9][0-9]{0,8})", version):
        raise ValueError("Release version must be a stable semantic version, for example 1.2.3 or v1.2.3.")
    if not re.fullmatch(r"[0-9a-f]{40}|[0-9a-f]{64}", commit):
        raise ValueError("Release commit must be a full lowercase Git commit hash.")
    validate_branch(branch)
    timestamp = dt.datetime.now(dt.timezone.utc).replace(microsecond=0) if built_at is None else dt.datetime.fromisoformat(built_at.replace("Z", "+00:00"))
    if timestamp.tzinfo is None or timestamp.utcoffset() != dt.timedelta(0):
        raise ValueError("Build timestamp must include the UTC timezone.")
    if not 1980 <= timestamp.year <= 2107:
        raise ValueError("Build timestamp is outside the ZIP timestamp range.")
    timestamp = timestamp.replace(microsecond=0)
    validate_prepared_source(source)
    metadata = {
        "schema": 1,
        "version": version,
        "repository": REPOSITORY,
        "branch": branch,
        "commit": commit,
        "built_at": timestamp.isoformat().replace("+00:00", "Z"),
        "requirements": production_requirements(source),
    }
    paths = payload_paths(source)
    # Never permit a build output to be recursively included as an input.
    if output == source or any(output.is_relative_to(source / relative) for relative in (*SOURCE_TREES, "vendor", "public/assets", "public/bundles", "config")):
        raise ValueError("Output directory must be outside packaged input directories.")
    output.mkdir(parents=True, exist_ok=True)
    package = output / f"aggregate-{version}.zip"
    manifest_path = output / "aggregate-release.json"
    signature_path = output / "aggregate-release.json.sig"
    if package.exists() or manifest_path.exists() or signature_path.exists():
        raise ValueError("Release outputs already exist; choose an empty output directory.")

    def write_entry(archive, relative, content, executable=False):
        entry = zipfile.ZipInfo(relative, timestamp.timetuple()[:6])
        entry.create_system = 3
        entry.compress_type = zipfile.ZIP_DEFLATED
        entry.external_attr = (stat.S_IFREG | (0o755 if executable else 0o644)) << 16
        archive.writestr(entry, content, compresslevel=9)

    package_created = False
    manifest_created = False
    try:
        with zipfile.ZipFile(package, "x", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
            package_created = True
            for relative in paths:
                path = source_file(source, relative)
                write_entry(archive, relative, path.read_bytes(), relative == "bin/console" or relative.startswith("vendor/bin/"))
            write_entry(archive, ".env", PRODUCTION_ENV.encode("utf-8"))
            write_entry(archive, "release.json", json_bytes(metadata))
        with package.open("rb") as handle:
            digest = hashlib.file_digest(handle, "sha256").hexdigest()
        manifest = dict(metadata, package={
            "filename": package.name,
            "sha256": digest,
            "size": package.stat().st_size,
        })
        with manifest_path.open("xb") as handle:
            manifest_created = True
            handle.write(json_bytes(manifest))
    except BaseException:
        if package_created:
            package.unlink(missing_ok=True)
        if manifest_created:
            manifest_path.unlink(missing_ok=True)
        raise
    return package, manifest_path


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source-dir", default=Path(__file__).resolve().parent.parent, type=Path)
    parser.add_argument("--output-dir", required=True, type=Path)
    parser.add_argument("--version", required=True)
    parser.add_argument("--branch", default="master")
    parser.add_argument("--commit", required=True)
    parser.add_argument("--built-at", help="UTC ISO 8601 timestamp; set this for reproducible package timestamps")
    args = parser.parse_args()
    try:
        package, manifest = build_release(args.source_dir, args.output_dir, args.version, args.branch, args.commit, args.built_at)
    except (OSError, ValueError, KeyError, TypeError) as error:
        parser.exit(1, f"Release build failed: {error}\n")
    print(f"Built {package}\nManifest: {manifest}\nSign the manifest with scripts/sign-release.php before publishing.")


if __name__ == "__main__":
    main()
