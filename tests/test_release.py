#!/usr/bin/env python3
"""Release packaging checks: no WordPress loading, database access or Wave requests."""

import hashlib
import importlib.util
import io
import json
from pathlib import Path
import tempfile
import tarfile
import sys
import unittest
from unittest.mock import patch
import zipfile


ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("build_release", ROOT / "scripts/build-release.py")
release = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release)
REAL_GIT = release.git


class ReleaseTests(unittest.TestCase):
    def setUp(self):
        self.commit = "1" * 40
        self.changelog = (ROOT / "CHANGELOG.md").read_bytes()
        self.members = {
            name: (ROOT / release.SOURCE / name).read_bytes()
            for name in ("scout-accounting-toolkit.php", "includes/class-sat-wave.php", "assets/inbox.js")
        }
        self.raw = self.tar_bytes()
        self.git_patch = patch.object(release, "git", side_effect=self.fixture_git)
        self.git_patch.start()
        self.addCleanup(self.git_patch.stop)

    def tar_bytes(self):
        output = io.BytesIO()
        with tarfile.open(fileobj=output, mode="w") as archive:
            for name, data in self.members.items():
                entry = tarfile.TarInfo(f"{release.SOURCE}/{name}")
                entry.size = len(data)
                archive.addfile(entry, io.BytesIO(data))
        return output.getvalue()

    def fixture_git(self, repo, *args):
        if args in (("rev-parse", "--verify", "HEAD^{commit}"),
                    ("rev-parse", "--verify", self.commit + "^{commit}")):
            return self.commit.encode() + b"\n"
        if args == ("archive", "--format=tar", self.commit, release.SOURCE):
            return self.raw
        if args == ("show", self.commit + ":CHANGELOG.md"):
            return self.changelog
        raise AssertionError(f"Unexpected Git call: {args}")

    def test_current_version_matches(self):
        source = (ROOT / release.SOURCE / f"{release.PLUGIN}.php").read_text()
        version = release.plugin_version(source)
        self.assertIn(f"## {version}\n", (ROOT / "CHANGELOG.md").read_text())

    def test_actual_git_archive_layout(self):
        commit = REAL_GIT(ROOT, "rev-parse", "HEAD").decode().strip()
        raw = REAL_GIT(ROOT, "archive", "--format=tar", commit, release.SOURCE)
        with tarfile.open(fileobj=io.BytesIO(raw)) as archive:
            names = [member.name for member in archive if not member.isdir()]
        self.assertIn(f"{release.SOURCE}/scout-accounting-toolkit.php", names)
        for name in names:
            self.assertTrue(name.startswith(release.SOURCE + "/"))
            self.assertTrue(release.allowed_file(name.removeprefix(release.SOURCE + "/")), name)

    def test_mismatched_or_missing_versions_are_rejected(self):
        for source in (
            " * Version: 1.3.0\ndefine('SAT_VERSION', '1.2.0');",
            " * Version: 1.3.0",
            "define('SAT_VERSION', '1.3.0');",
            " * Version: 1.3\ndefine('SAT_VERSION', '1.3');",
        ):
            with self.assertRaises(ValueError):
                release.plugin_version(source)

    def test_dirty_checkout_is_rejected(self):
        with patch.object(release, "git", return_value=b" M README.md\n"):
            with self.assertRaisesRegex(ValueError, "clean, committed"):
                release.require_clean_head(ROOT)

    def test_package_allowlist(self):
        for name in ("config.php", ".env", "../secret.php", "tests/test.php", "scripts/proof.php",
                     "assets/fixture.ofx", "includes/credentials.php", "local-example.json"):
            self.assertFalse(release.allowed_file(name), name)
        for name in ("scout-accounting-toolkit.php", "includes/class-sat-wave-export.php", "assets/inbox.js"):
            self.assertTrue(release.allowed_file(name), name)

    def test_committed_archive_is_reproducible_and_verifiable(self):
        # The fixture supplies committed objects without creating a Git commit or release ZIP.
        info, first = release.archive_bytes(ROOT, "HEAD")
        self.assertEqual((info, first), release.archive_bytes(ROOT, info["commit"]))
        with zipfile.ZipFile(io.BytesIO(first)) as archive:
            names = archive.namelist()
            self.assertEqual(names, sorted(names))
            metadata = json.loads(archive.read(f"{release.PLUGIN}/{release.BUILD_INFO}"))
            self.assertEqual(metadata, info)
            for name, digest in info["files"].items():
                self.assertTrue(release.allowed_file(name), name)
                self.assertEqual(hashlib.sha256(archive.read(f"{release.PLUGIN}/{name}")).hexdigest(), digest)
            with tempfile.TemporaryDirectory(prefix="sat-release-test-") as temporary:
                archive.extractall(temporary)
                installed = Path(temporary) / release.PLUGIN
                self.assertEqual(release.verify_installed(ROOT, installed), info)
                entry = installed / f"{release.PLUGIN}.php"
                entry.write_bytes(entry.read_bytes() + b"\n")
                with self.assertRaisesRegex(ValueError, "differs"):
                    release.verify_installed(ROOT, installed)

    def test_missing_release_notes_are_rejected(self):
        self.changelog = b"# Changelog\n"
        with self.assertRaisesRegex(ValueError, "no entry"):
            release.archive_bytes(ROOT, "HEAD")

    def test_unexpected_committed_package_member_is_rejected(self):
        self.members["credentials.php"] = b"not a production class"
        self.raw = self.tar_bytes()
        with self.assertRaisesRegex(ValueError, "Unexpected plugin package member"):
            release.archive_bytes(ROOT, "HEAD")

    def test_installed_extra_file_is_rejected(self):
        info, data = release.archive_bytes(ROOT, "HEAD")
        with tempfile.TemporaryDirectory(prefix="sat-release-test-") as temporary:
            with zipfile.ZipFile(io.BytesIO(data)) as archive:
                archive.extractall(temporary)
            installed = Path(temporary) / release.PLUGIN
            (installed / "stray.php").write_text("unexpected")
            with self.assertRaisesRegex(ValueError, "file list"):
                release.verify_installed(ROOT, installed)
            (installed / "stray.php").unlink()
            metadata = installed / release.BUILD_INFO
            info["version"] = "99.0.0"
            metadata.write_text(json.dumps(info))
            with self.assertRaisesRegex(ValueError, "metadata"):
                release.verify_installed(ROOT, installed)

    def test_cli_checksum_and_no_overwrite(self):
        with tempfile.TemporaryDirectory(prefix="sat-release-test-") as temporary:
            with patch.object(release, "require_clean_head", return_value=self.commit), \
                 patch.object(sys, "argv", ["build-release.py", "--output-dir", temporary]), \
                 patch("sys.stdout", new_callable=io.StringIO), \
                 patch("sys.stderr", new_callable=io.StringIO):
                release.main()
                artifacts = sorted(Path(temporary).iterdir())
                self.assertEqual(len(artifacts), 2)
                package, checksum = artifacts
                original = package.read_bytes()
                self.assertEqual(
                    checksum.read_text(),
                    f"{hashlib.sha256(original).hexdigest()}  {package.name}\n",
                )
                with self.assertRaises(SystemExit) as error:
                    release.main()
                self.assertEqual(error.exception.code, 1)
                self.assertEqual(original, package.read_bytes())

    def test_installed_symlink_and_missing_file_are_rejected(self):
        _, data = release.archive_bytes(ROOT, "HEAD")
        with tempfile.TemporaryDirectory(prefix="sat-release-test-") as temporary:
            with zipfile.ZipFile(io.BytesIO(data)) as archive:
                archive.extractall(temporary)
            installed = Path(temporary) / release.PLUGIN
            link = installed / "stray-link"
            link.symlink_to(installed / f"{release.PLUGIN}.php")
            with self.assertRaisesRegex(ValueError, "symlink"):
                release.verify_installed(ROOT, installed)
            link.unlink()
            (installed / "assets/inbox.js").unlink()
            with self.assertRaisesRegex(ValueError, "file list"):
                release.verify_installed(ROOT, installed)


if __name__ == "__main__":
    unittest.main()
