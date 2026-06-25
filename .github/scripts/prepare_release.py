#!/usr/bin/env python3
"""Prepare a release: validate versions, sync the readme Stable tag, and generate the
changelog from git history into readme.txt + README.md + the Upgrade Notice.

The plugin header `Version:` is the single source of truth for the version. The
`AAFM_VERSION` constant must match it (the script fails loudly if it does not, which
catches the classic "bumped one, forgot the other" mistake). The readme `Stable tag`
is synced to that version automatically.

Changelog: if readme.txt already has a `= <version> =` block under == Changelog ==,
that hand-written block is kept as-is (this is the manual-override path). Otherwise a
block is generated from the commit subjects since the previous version tag. The same
body is written to README.md (as `### <version>`) and a one-line Upgrade Notice stub
is added. The changelog body is also written to .github/release-notes.md so the
publish workflow can use it as the GitHub Release notes.

Usage:
  prepare_release.py            # use the version from the plugin header
  prepare_release.py --dry-run  # print what would change, write nothing
  prepare_release.py --version 1.0.1 --dry-run   # preview a specific version
"""

import argparse
import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
PLUGIN = ROOT / "oversio-agent-abilities.php"
README_TXT = ROOT / "readme.txt"
README_MD = ROOT / "README.md"
NOTES = ROOT / ".github" / "release-notes.md"

SEMVER = r"[0-9]+\.[0-9]+\.[0-9]+"


def fail(msg):
    print(f"error: {msg}", file=sys.stderr)
    sys.exit(1)


def git(*args):
    res = subprocess.run(["git", *args], cwd=ROOT, capture_output=True, text=True)
    return res.stdout.strip()


def header_version(txt):
    m = re.search(rf"^\s*\*\s*Version:\s*({SEMVER})", txt, re.M)
    if not m:
        fail("could not read the Version: header from the plugin file")
    return m.group(1)


def const_version(txt):
    m = re.search(rf"define\(\s*'AAFM_VERSION'\s*,\s*'({SEMVER})'", txt)
    if not m:
        fail("could not read the AAFM_VERSION constant from the plugin file")
    return m.group(1)


def previous_tag(version):
    """Highest existing version tag strictly below `version` (v-prefix tolerated)."""
    candidates = []
    for tag in git("tag").splitlines():
        bare = tag.lstrip("v")
        if re.fullmatch(SEMVER, bare) and bare != version:
            candidates.append(bare)
    candidates.sort(key=lambda s: [int(p) for p in s.split(".")], reverse=True)
    return candidates[0] if candidates else ""


def generate_bullets(prev):
    rng = f"{prev}..HEAD" if prev else "HEAD"
    # Tolerate either bare or v-prefixed tags for the range start.
    if prev and not git("rev-parse", "--verify", "--quiet", prev):
        if git("rev-parse", "--verify", "--quiet", f"v{prev}"):
            rng = f"v{prev}..HEAD"
    raw = git("log", rng, "--no-merges", "--pretty=format:%s")
    lines = []
    for subject in raw.splitlines():
        s = subject.strip()
        if not s or s.startswith(("Release ", "Merge ")):
            continue
        lines.append(f"* {s}")
    if not lines:
        lines = ["* Maintenance and internal improvements."]
    return "\n".join(lines)


def existing_block(txt, version):
    """Return the hand-written `= version =` body from the readme Changelog, or None."""
    sec = re.search(r"== Changelog ==\n(.*?)(?=\n== |\Z)", txt, re.S)
    if not sec:
        return None
    m = re.search(
        rf"(?m)^= {re.escape(version)} =\n(.*?)(?=\n= {SEMVER} =|\Z)",
        sec.group(1),
        re.S,
    )
    return m.group(1).strip("\n") if m else None


def insert_after(txt, marker, block):
    """Insert `block` immediately after `marker` (which should end in a blank line)."""
    m = re.search(marker, txt)
    if not m:
        fail(f"could not find the insertion marker: {marker!r}")
    return txt[: m.end()] + block + txt[m.end():]


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--dry-run", action="store_true")
    ap.add_argument("--version", help="override the version (preview/testing only)")
    args = ap.parse_args()

    php = PLUGIN.read_text(encoding="utf-8")
    head_v = header_version(php)
    const_v = const_version(php)
    if head_v != const_v:
        fail(f"version mismatch: header is {head_v} but AAFM_VERSION is {const_v} — fix the PHP file")

    version = args.version or head_v
    if args.version and args.version != head_v and not args.dry_run:
        fail(f"--version {args.version} does not match the plugin header {head_v}; bump the PHP file first")

    prev = previous_tag(version)
    print(f"version: {version}  (header & AAFM_VERSION agree)")
    print(f"previous tag: {prev or '<none>'}")

    rt = README_TXT.read_text(encoding="utf-8")
    kept = existing_block(rt, version)
    if kept:
        print(f"keeping the hand-written changelog already in readme.txt for {version}")
        body = kept
    else:
        body = generate_bullets(prev)

    print("----- changelog body -----")
    print(body)
    print("--------------------------")

    if args.dry_run:
        print("(dry run — no files written)")
        return

    # 1) readme.txt: sync Stable tag.
    rt = re.sub(r"(?m)^Stable tag:.*$", f"Stable tag: {version}", rt)

    # 2) readme.txt: changelog block (skip if already present).
    if not re.search(rf"(?m)^= {re.escape(version)} =$", rt):
        rt = insert_after(rt, r"== Changelog ==\n\n", f"= {version} =\n{body}\n\n")

    # 3) readme.txt: Upgrade Notice stub (skip if already present).
    if "== Upgrade Notice ==" in rt and not re.search(
        rf"(?ms)== Upgrade Notice ==.*?^= {re.escape(version)} =$", rt
    ):
        notice = "Maintenance release. See the changelog for details."
        rt = insert_after(rt, r"== Upgrade Notice ==\n\n", f"= {version} =\n{notice}\n\n")

    README_TXT.write_text(rt, encoding="utf-8")

    # 4) README.md: matching `### version` block (skip if already present).
    md = README_MD.read_text(encoding="utf-8")
    if not re.search(rf"(?m)^### {re.escape(version)}$", md):
        md = insert_after(md, r"## Changelog\n\n", f"### {version}\n\n{body}\n\n")
    README_MD.write_text(md, encoding="utf-8")

    # 5) Release notes for the publish workflow.
    NOTES.write_text(body + "\n", encoding="utf-8")

    print(f"updated readme.txt, README.md, and wrote {NOTES.relative_to(ROOT)}")


if __name__ == "__main__":
    main()
