#!/usr/bin/env python3
"""Snapshot installed skills for Copilot; check snapshots without local sources."""

import argparse
import hashlib
import json
import re
import shutil
import stat
import sys
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
DEST = REPO / ".github" / "skills"
CATALOG = DEST / "catalog.json"
SYSTEM_SKILLS = {"imagegen", "openai-docs", "skill-creator", "skill-installer"}
SKIP_DIRS = {".git", "__pycache__", ".pytest_cache", "node_modules", ".venv"}


def linked(path):
    try:
        details = path.lstat()
    except FileNotFoundError:
        return False
    return stat.S_ISLNK(details.st_mode) or bool(
        getattr(details, "st_file_attributes", 0)
        & getattr(stat, "FILE_ATTRIBUTE_REPARSE_POINT", 0)
    )


def files_in(folder):
    files = []
    for path in sorted(folder.rglob("*"), key=lambda item: item.relative_to(folder).as_posix()):
        relative = path.relative_to(folder)
        if any(part in SKIP_DIRS for part in relative.parts):
            continue
        if linked(path):
            raise ValueError(f"Linked resource requires manual review: {relative}")
        if not path.is_file() or path.suffix in {".pyc", ".pyo"}:
            continue
        if path.name.startswith(".env") or path.suffix in {".pem", ".key", ".p12", ".pfx"}:
            raise ValueError(f"Credential-like resource requires manual review: {relative}")
        files.append(path)
    return files


def fingerprint(folder):
    digest = hashlib.sha256()
    files = files_in(folder)
    for path in files:
        digest.update(path.relative_to(folder).as_posix().encode("utf-8") + b"\0")
        digest.update(hashlib.sha256(path.read_bytes()).digest())
    return digest.hexdigest(), len(files)


def metadata(folder):
    content = (folder / "SKILL.md").read_text(encoding="utf-8-sig")
    match = re.match(r"\A---\r?\n(.*?)\r?\n---(?:\r?\n|$)", content, re.S)
    if not match:
        raise ValueError(f"Missing frontmatter: {folder.name}")
    fields = {}
    for key in ("name", "description"):
        scalar = re.search(rf"^{key}:[ \t]*(.+)$", match[1], re.M)
        if not scalar:
            raise ValueError(f"Missing {key}: {folder.name}")
        value = scalar[1].strip()
        if not value:
            raise ValueError(f"Empty {key}: {folder.name}")
        if value[:1] in {"'", '"'} and value[-1:] == value[:1]:
            value = value[1:-1]
        if value in {"|", ">", "|-", ">-"}:
            raise ValueError(f"Block metadata needs manual review: {folder.name}")
        fields[key] = value
    if not re.fullmatch(r"[a-z0-9]+(?:-[a-z0-9]+)*", fields["name"]):
        raise ValueError(f"Invalid skill name: {folder.name}")
    if fields["name"] != folder.name or len(fields["name"]) > 64:
        raise ValueError(f"Skill name/directory mismatch: {folder.name}")
    return fields


def read_catalog():
    return json.loads(CATALOG.read_text(encoding="utf-8")) if CATALOG.exists() else {"version": 1, "skills": {}}


def check():
    if linked(DEST):
        raise ValueError("Linked skill destination requires manual review")
    catalog = read_catalog()["skills"]
    if not catalog:
        raise ValueError("No skill snapshots found")
    actual = {path.name for path in DEST.iterdir() if path.is_dir() and (path / "SKILL.md").exists()}
    if actual != set(catalog):
        raise ValueError("Skill directory inventory differs from catalog")
    for name, entry in catalog.items():
        folder = DEST / name
        if not re.fullmatch(r"[a-z0-9]+(?:-[a-z0-9]+)*", name) or linked(folder):
            raise ValueError("Invalid skill directory in catalog")
        fields = metadata(folder)
        digest, count = fingerprint(folder)
        if digest != entry["sha256"] or count != entry["files"] or fields != entry["metadata"]:
            raise ValueError(f"Snapshot differs from catalog: {name}")
    print(f"Verified {len(catalog)} skill snapshots and all bundled file hashes.")


def sync(sources, write):
    if linked(DEST):
        raise ValueError("Linked skill destination requires manual review")
    selected = {}
    for root in sources:
        root = root.expanduser().resolve()
        if not root.is_dir():
            continue
        for folder in sorted(root.iterdir()):
            if linked(folder):
                raise ValueError(f"Linked skill requires manual review: {folder.name}")
            if not folder.is_dir() or not (folder / "SKILL.md").is_file():
                continue
            if root.name == ".system" and folder.name not in SYSTEM_SKILLS:
                continue
            selected.setdefault(folder.name, folder)
    if not selected:
        raise ValueError("No installed skills found in source directories")
    catalog = read_catalog()
    planned = []
    for name, source in sorted(selected.items()):
        fields = metadata(source)
        digest, count = fingerprint(source)
        target = DEST / name
        if linked(target):
            raise ValueError(f"Linked destination requires manual review: {name}")
        if target.exists():
            installed = fingerprint(target)[0]
            recorded = catalog["skills"].get(name, {}).get("sha256")
            if installed != digest and installed != recorded:
                raise ValueError(f"Preserving locally edited or unmanaged skill: {name}")
            old_files = {path.relative_to(target) for path in files_in(target)}
            new_files = {path.relative_to(source) for path in files_in(source)}
            if old_files - new_files:
                raise ValueError(f"Source removed resources; review manually before refreshing: {name}")
        try:
            source_label = "~/" + source.relative_to(Path.home()).as_posix()
        except ValueError:
            source_label = source.as_posix()
        planned.append((name, source, target, {"source": source_label, "sha256": digest, "files": count, "metadata": fields}))
    if write:
        DEST.mkdir(parents=True, exist_ok=True)
        for name, source, target, entry in planned:
            for path in files_in(source):
                output = target / path.relative_to(source)
                output.parent.mkdir(parents=True, exist_ok=True)
                shutil.copy2(path, output)
            catalog["skills"][name] = entry
        CATALOG.write_text(json.dumps(catalog, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    print(f"{'Synced' if write else 'Would sync'} {len(planned)} skills; first source wins duplicate names. No source files deleted.")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument("--sync", action="store_true", help="Copy snapshots and update catalog")
    mode.add_argument("--check", action="store_true", help="Verify snapshots without accessing local sources")
    parser.add_argument("--source", type=Path, action="append", help="Skill root; repeat in priority order")
    args = parser.parse_args()
    if args.check:
        check()
    else:
        roots = args.source or [Path.home() / root for root in (
            ".agents/skills", ".copilot/skills", ".claude/skills", ".codex/skills", ".codex/skills/.system"
        )]
        sync(roots, args.sync)


if __name__ == "__main__":
    try:
        main()
    except (OSError, ValueError) as error:
        print(f"Skill sync stopped: {error}", file=sys.stderr)
        sys.exit(1)
