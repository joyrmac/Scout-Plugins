#!/usr/bin/env python3
"""Code slop check for Scout repos.

Spec: Scout-Media-Raleigh/content/internal/anti-slop-site-playbook.md, Section 8.
Flags the tells that make code read as machine-written: emoji, filler comment
phrases, placeholder leftovers, debug output, and stray "copy" or backup files.

    python3 tools/slop-check.py                     # every tracked file in the repo
    python3 tools/slop-check.py scout-core          # one folder or file
    python3 tools/slop-check.py --commits origin/main..HEAD --client-repo

--client-repo also fails commit messages that name AI models or tools. Client
repos carry none; platform repos follow their own CLAUDE.md.

FAIL exits 1. WARN is printed and never fails the run.
Silence one line on purpose with a trailing comment: slop-ok (say why next to it).
"""
import argparse
import pathlib
import re
import subprocess
import sys

CODE_EXT = {".php", ".js", ".mjs", ".ts", ".css", ".scss", ".py", ".sh", ".html", ".json", ".yml", ".yaml"}
SKIP_DIRS = {".git", "node_modules", "vendor", "dist", "build", "__pycache__"}

EMOJI_RE = re.compile(
    "[\U0001F300-\U0001FAFF\U00002600-\U000027BF\U0001F000-\U0001F2FF\U00002B50\U00002B55\U0000FE0F]"
)
# Symbols that do real UI work: star ratings, check and cross marks.
EMOJI_OK = set("\u2605\u2606\u2713\u2714\u2717\u2718")
COMMENT_RE = re.compile(r"^\s*(//|#(?![!\[{])|/\*|\*(?!/)|<!--)")
FILLER_RE = re.compile(
    r"\b(elegant(ly)?|seamless(ly)?|robust|comprehensive|leverag(e|es|ing)|cutting[- ]edge|"
    r"state[- ]of[- ]the[- ]art|delve|effortless(ly)?|powerful|this (function|method|class) (will )?(handles?|is responsible)|"
    r"here we|let's|we'll now|note that this|ensures? that|for better maintainability)\b",
    re.I,
)
PLACEHOLDER_RE = re.compile(r"\b(lorem ipsum|TODO:? implement|placeholder text|insert (text|copy) here)\b", re.I)
DEBUG_RES = {
    ".js": re.compile(r"\bconsole\.(log|debug)\("),
    ".mjs": re.compile(r"\bconsole\.(log|debug)\("),
    ".ts": re.compile(r"\bconsole\.(log|debug)\("),
    ".php": re.compile(r"\b(var_dump|print_r|var_export|dd)\s*\("),
}
# Dashed section dividers are a normal habit; ===, ### and *** walls are not.
BANNER_RE = re.compile(r"^\s*(//|#|/\*|\*)\s*[=*#]{8,}")
STRAY_FILE_RE = re.compile(r"( copy( \d+)?|[-_]old|[-_]new|[-_]backup|[-_]bak|[-_]final|[-_]v\d+)(\.[^/]+)?$|\.(bak|orig|tmp)$", re.I)
AI_COMMIT_RE = re.compile(
    r"\b(claude|anthropic|chatgpt|gpt-?\d|openai|copilot|gemini|opus|sonnet|haiku|ai[- ]generated|generated (with|by))\b|claude\.(ai|com)",
    re.I,
)


def tracked_files(paths):
    try:
        out = subprocess.run(["git", "ls-files", "--", *paths], capture_output=True, text=True, check=True).stdout
        return [pathlib.Path(p) for p in out.splitlines()]
    except (subprocess.CalledProcessError, FileNotFoundError):
        files = []
        for p in map(pathlib.Path, paths or ["."]):
            files += [p] if p.is_file() else [f for f in p.rglob("*") if f.is_file()]
        return files


def check_file(path, report):
    if any(part in SKIP_DIRS for part in path.parts):
        return
    if STRAY_FILE_RE.search(path.stem + path.suffix) or STRAY_FILE_RE.search(str(path.parent)):
        report("FAIL", path, 0, "stray copy or backup file. Git keeps history, so delete it")
    if path.suffix.lower() not in CODE_EXT or path.name.endswith(".min.js") or path.name.endswith(".min.css"):
        return
    try:
        lines = path.read_text(encoding="utf-8").splitlines()
    except (UnicodeDecodeError, OSError):
        return
    debug_re = DEBUG_RES.get(path.suffix.lower())
    for n, line in enumerate(lines, 1):
        if "slop-ok" in line:
            continue
        if any(EMOJI_RE.match(c) and c not in EMOJI_OK for c in line):
            report("FAIL", path, n, "emoji in code or output. Use plain text")
        if PLACEHOLDER_RE.search(line):
            report("FAIL", path, n, f"placeholder left in: {PLACEHOLDER_RE.search(line).group(0)!r}")
        if debug_re and debug_re.search(line) and not COMMENT_RE.match(line):
            report("FAIL", path, n, f"debug output left in: {debug_re.search(line).group(0)}")
        if COMMENT_RE.match(line):
            m = FILLER_RE.search(line)
            if m:
                report("WARN", path, n, f"filler in comment: {m.group(0)!r}. Say what it does or why, plainly")
            if BANNER_RE.match(line):
                report("WARN", path, n, "decorative comment banner")
        elif "\u2014" in line:
            report("WARN", path, n, "em dash outside a comment. If a visitor can see this text, restructure it")


def check_commits(rev_range, report):
    out = subprocess.run(["git", "log", "--format=%H%x00%B%x01", rev_range], capture_output=True, text=True)
    if out.returncode:
        report("FAIL", pathlib.Path("git"), 0, f"could not read commits {rev_range}: {out.stderr.strip()}")
        return
    for entry in filter(str.strip, out.stdout.split("\x01")):
        sha, _, body = entry.strip().partition("\x00")
        m = AI_COMMIT_RE.search(body)
        if m:
            report("FAIL", pathlib.Path(f"commit {sha[:8]}"), 0, f"AI name in a client-repo commit message: {m.group(0)!r}")


def main():
    ap = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    ap.add_argument("paths", nargs="*", help="files or folders (default: the whole repo)")
    ap.add_argument("--commits", metavar="RANGE", help="also check commit messages in this range, e.g. origin/main..HEAD")
    ap.add_argument("--client-repo", action="store_true", help="fail commit messages that name AI models or tools")
    ap.add_argument("--quiet", action="store_true", help="print FAIL lines only")
    args = ap.parse_args()

    counts = {"FAIL": 0, "WARN": 0}

    def report(level, path, line, msg):
        counts[level] += 1
        if args.quiet and level != "FAIL":
            return
        where = f"{path}:{line}" if line else str(path)
        if level == "FAIL":
            print(f"::error file={path},line={line or 1}::{msg}" if _in_ci() else f"FAIL  {where}  {msg}")
        else:
            print(f"WARN  {where}  {msg}")

    for f in tracked_files(args.paths):
        check_file(f, report)
    if args.commits and args.client_repo:
        check_commits(args.commits, report)

    print(f"\nslop-check: {counts['FAIL']} FAIL, {counts['WARN']} WARN")
    return 1 if counts["FAIL"] else 0


def _in_ci():
    import os
    return os.environ.get("GITHUB_ACTIONS") == "true"


if __name__ == "__main__":
    sys.exit(main())
