#!/usr/bin/env python3
"""Build or verify a plugin release from committed Git objects, without loading WordPress."""

import argparse
import hashlib
import io
import json
from pathlib import Path, PurePosixPath
import re
import subprocess
import tarfile
import zipfile


ROOT = Path(__file__).resolve().parents[1]
PLUGIN = "scout-accounting-toolkit"
SOURCE = f"wordpress/{PLUGIN}"
BUILD_INFO = "sat-build.json"


def git(repo, *args):
    return subprocess.run(
        ["git", "-C", str(repo), *args], check=True, capture_output=True
    ).stdout


def plugin_version(source):
    header = re.search(r"^\s*\*\s*Version:\s*(\d+\.\d+\.\d+)\s*$", source, re.M)
    constant = re.search(r"define\('SAT_VERSION', '(\d+\.\d+\.\d+)'\);", source)
    if not header or not constant or header[1] != constant[1]:
        raise ValueError("Plugin Version header and SAT_VERSION must contain identical semantic versions.")
    return header[1]


def allowed_file(name):
    return bool(re.fullmatch(
        r"scout-accounting-toolkit\.php|includes/class-sat-[a-z-]+\.php|assets/[a-z-]+\.(css|js)",
        name,
    ))


def archive_bytes(repo, ref):
    commit = git(repo, "rev-parse", "--verify", f"{ref}^{{commit}}").decode().strip()
    raw = git(repo, "archive", "--format=tar", commit, SOURCE)
    files = {}
    with tarfile.open(fileobj=io.BytesIO(raw)) as archive:
        for member in archive:
            if member.isdir():
                continue
            name = member.name.removeprefix(SOURCE + "/")
            if not member.name.startswith(SOURCE + "/") or not member.isfile() or not allowed_file(name):
                raise ValueError(f"Unexpected plugin package member: {member.name}")
            with archive.extractfile(member) as content:
                files[name] = content.read()
    version = plugin_version(files[f"{PLUGIN}.php"].decode())
    changelog = git(repo, "show", f"{commit}:CHANGELOG.md").decode()
    if f"## {version}\n" not in changelog:
        raise ValueError(f"CHANGELOG.md has no entry for {version}.")
    info = {
        "version": version,
        "commit": commit,
        "files": {name: hashlib.sha256(data).hexdigest() for name, data in sorted(files.items())},
    }
    files[BUILD_INFO] = (json.dumps(info, indent=2, sort_keys=True) + "\n").encode()
    output = io.BytesIO()
    with zipfile.ZipFile(output, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for name, data in sorted(files.items()):
            entry = zipfile.ZipInfo(f"{PLUGIN}/{name}", date_time=(1980, 1, 1, 0, 0, 0))
            entry.compress_type = zipfile.ZIP_DEFLATED
            entry.create_system = 3
            entry.external_attr = 0o100644 << 16
            archive.writestr(entry, data)
    return info, output.getvalue()


def require_clean_head(repo):
    if git(repo, "status", "--porcelain", "--untracked-files=all"):
        raise ValueError("Release builds require a clean, committed checkout. Commit and rerun validation first.")
    return git(repo, "rev-parse", "HEAD").decode().strip()


def verify_installed(repo, directory):
    directory = Path(directory)
    if directory.is_symlink() or not directory.is_dir():
        raise ValueError("Installed plugin must be a real directory, not a symlink.")
    info = json.loads((directory / BUILD_INFO).read_text())
    if not isinstance(info, dict):
        raise ValueError("Installed build metadata must be a JSON object.")
    commit = info["commit"]
    if not isinstance(commit, str) or not re.fullmatch(r"[0-9a-f]{40}", commit):
        raise ValueError("Installed build metadata has an invalid Git commit.")
    expected, data = archive_bytes(repo, commit)
    if info != expected:
        raise ValueError("Installed build metadata does not match its committed source.")
    actual = set()
    for path in directory.rglob("*"):
        if path.is_symlink():
            raise ValueError(f"Unexpected installed symlink: {path}")
        if path.is_file():
            actual.add(path.relative_to(directory).as_posix())
    with zipfile.ZipFile(io.BytesIO(data)) as archive:
        names = {str(PurePosixPath(name).relative_to(PLUGIN)) for name in archive.namelist()}
        if actual != names:
            raise ValueError("Installed plugin file list does not match the release.")
        for name in sorted(names):
            if (directory / name).read_bytes() != archive.read(f"{PLUGIN}/{name}"):
                raise ValueError(f"Installed plugin differs from its committed release: {name}")
    return expected


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    action = parser.add_mutually_exclusive_group(required=True)
    action.add_argument("--check", action="store_true", help="Validate clean committed release inputs; write nothing.")
    action.add_argument("--output-dir", type=Path, help="Existing directory outside the checkout; never overwrite artifacts.")
    action.add_argument("--verify-installed", type=Path, help="Read-only verification of an installed/copied plugin directory.")
    args = parser.parse_args()
    try:
        if args.verify_installed is not None:
            info = verify_installed(ROOT, args.verify_installed)
            print(f"Verified SAT {info['version']} at commit {info['commit']}.")
            return
        commit = require_clean_head(ROOT)
        info, data = archive_bytes(ROOT, commit)
        if args.check:
            print(f"Release inputs verified: SAT {info['version']} at commit {commit}.")
            return
        directory = args.output_dir.resolve(strict=True)
        if not directory.is_dir() or directory == ROOT or ROOT in directory.parents:
            raise ValueError("Choose an existing output directory outside the checkout.")
        name = f"{PLUGIN}-{info['version']}-{commit[:12]}.zip"
        checksum = directory / f"{name}.sha256"
        destination = directory / name
        if destination.exists() or checksum.exists():
            raise ValueError("Release ZIP or checksum already exists; refusing to overwrite.")
        digest = hashlib.sha256(data).hexdigest()
        with destination.open("xb") as output:
            output.write(data)
        with checksum.open("x") as output:
            output.write(f"{digest}  {name}\n")
        print(f"Built SAT {info['version']} from {commit}: {destination}")
        print(f"SHA-256: {digest}")
    except (OSError, ValueError, KeyError, subprocess.CalledProcessError) as error:
        detail = error.stderr.decode().strip() if isinstance(error, subprocess.CalledProcessError) else str(error)
        parser.exit(1, f"Release failed: {detail}\n")


if __name__ == "__main__":
    main()
