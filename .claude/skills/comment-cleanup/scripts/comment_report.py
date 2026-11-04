#!/usr/bin/env python3
"""Inventory comments across a PHP/Laravel codebase.

Walks a project root, counts comments per file by kind, and flags likely
commented-out code. Read-only. Use this before editing to gauge scale and
prioritise the noisiest files.

Usage:
    python3 comment_report.py <project_root> [--top N] [--json]
"""
import argparse
import os
import re
import sys

EXCLUDE_DIRS = {
    "vendor", "node_modules", "storage", "bootstrap", ".git",
    "public/build", "dist", "build", ".idea", ".vscode",
}

# Lines that strongly look like commented-out PHP code rather than prose.
CODE_LIKE = re.compile(
    r"(;\s*$)|(\b(return|if|foreach|while|function|public|private|protected|"
    r"\$[a-zA-Z_])\b)|(->)|(=>)|(::)"
)


def iter_files(root):
    for dirpath, dirnames, filenames in os.walk(root):
        dirnames[:] = [d for d in dirnames if d not in EXCLUDE_DIRS]
        for f in filenames:
            yield os.path.join(dirpath, f)


def analyze_blade(text):
    blade = len(re.findall(r"\{\{--.*?--\}\}", text, re.DOTALL))
    html = len(re.findall(r"<!--.*?-->", text, re.DOTALL))
    return {"blade": blade, "html": html}


def analyze_php(text):
    # Strip strings crudely so // inside strings/URLs isn't miscounted.
    # Good enough for an inventory (not a parser).
    no_str = re.sub(r"'(?:\\.|[^'\\])*'", "''", text)
    no_str = re.sub(r'"(?:\\.|[^"\\])*"', '""', no_str)

    line_slash = re.findall(r"(?m)//.*$", no_str)
    line_hash = re.findall(r"(?m)(?<!\$)#(?!\[).*$", no_str)  # skip #[Attr]
    block = re.findall(r"/\*.*?\*/", no_str, re.DOTALL)
    docblock = [b for b in block if b.startswith("/**")]
    plain_block = [b for b in block if not b.startswith("/**")]

    commented_code = 0
    for c in line_slash:
        body = c.lstrip("/").strip()
        if body and CODE_LIKE.search(body):
            commented_code += 1

    return {
        "//": len(line_slash),
        "#": len(line_hash),
        "/* */": len(plain_block),
        "/** */": len(docblock),
        "commented_code": commented_code,
    }


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("root")
    ap.add_argument("--top", type=int, default=40, help="show N noisiest files")
    ap.add_argument("--json", action="store_true")
    args = ap.parse_args()

    if not os.path.isdir(args.root):
        sys.exit(f"Not a directory: {args.root}")

    rows = []
    totals = {"blade": 0, "html": 0, "//": 0, "#": 0,
              "/* */": 0, "/** */": 0, "commented_code": 0}
    n_blade = n_php = 0

    for path in iter_files(args.root):
        try:
            with open(path, encoding="utf-8", errors="replace") as fh:
                text = fh.read()
        except OSError:
            continue

        rel = os.path.relpath(path, args.root)
        if path.endswith(".blade.php"):
            n_blade += 1
            d = analyze_blade(text)
            count = d["blade"] + d["html"]
            for k in ("blade", "html"):
                totals[k] += d[k]
            if count:
                rows.append((count, rel, "blade", d))
        elif path.endswith(".php"):
            n_php += 1
            d = analyze_php(text)
            count = d["//"] + d["#"] + d["/* */"] + d["/** */"]
            for k in ("//", "#", "/* */", "/** */", "commented_code"):
                totals[k] += d[k]
            if count:
                rows.append((count, rel, "php", d))

    rows.sort(reverse=True)

    if args.json:
        import json
        print(json.dumps({
            "totals": totals,
            "files_scanned": {"blade": n_blade, "php": n_php},
            "files": [
                {"path": r[1], "type": r[2], "count": r[0], "detail": r[3]}
                for r in rows
            ],
        }, indent=2))
        return

    print(f"Scanned {n_blade} Blade files, {n_php} PHP files\n")
    print("TOTALS")
    print(f"  Blade {{-- --}} comments : {totals['blade']}")
    print(f"  HTML <!-- --> comments  : {totals['html']}")
    print(f"  PHP // comments         : {totals['//']}")
    print(f"  PHP # comments          : {totals['#']}")
    print(f"  PHP /* */ blocks        : {totals['/* */']}")
    print(f"  PHP /** */ docblocks    : {totals['/** */']}")
    print(f"  Likely commented-out code lines: {totals['commented_code']}")
    print(f"\nNoisiest files (top {args.top}):")
    for count, rel, kind, d in rows[: args.top]:
        extra = ""
        if kind == "php" and d["commented_code"]:
            extra = f"  [~{d['commented_code']} commented-out code]"
        print(f"  {count:5d}  {rel}{extra}")


if __name__ == "__main__":
    main()
