#!/usr/bin/env python3
"""Strip Blade comments from .blade.php files.

Removes {{-- ... --}} comments (including multi-line). Conservative with HTML
comments: by default it only REPORTS <!-- --> occurrences and leaves them in
place, since some are functional (IE conditionals, build markers). Pass
--html to also remove non-conditional HTML comments.

Skips @verbatim ... @endverbatim regions and won't strip {{-- --}}-looking
text that sits inside a quoted string.

Usage:
    python3 strip_blade_comments.py <dir_or_file> [--write] [--html]

Without --write it runs as a dry run and prints what it would remove.
"""
import argparse
import os
import re
import sys

VERBATIM = re.compile(r"@verbatim\b.*?@endverbatim\b", re.DOTALL)
BLADE_COMMENT = re.compile(r"\{\{--.*?--\}\}", re.DOTALL)
HTML_COMMENT = re.compile(r"<!--.*?-->", re.DOTALL)
IE_CONDITIONAL = re.compile(r"<!--\s*\[if\b|<!\[endif\]", re.IGNORECASE)


def protect_regions(text):
    """Replace @verbatim blocks and quoted strings with placeholders so we
    don't strip comment-like text inside them. Returns (masked, restorer)."""
    store = []

    def stash(m):
        store.append(m.group(0))
        return f"\x00{len(store) - 1}\x00"

    masked = VERBATIM.sub(stash, text)
    # Protect simple single/double quoted strings on a best-effort basis.
    masked = re.sub(r"'(?:\\.|[^'\\])*'", stash, masked)
    masked = re.sub(r'"(?:\\.|[^"\\])*"', stash, masked)

    def restore(s):
        def put(m):
            return store[int(m.group(1))]
        # Repeat until stable (nested placeholders).
        prev = None
        while prev != s:
            prev = s
            s = re.sub(r"\x00(\d+)\x00", put, s)
        return s

    return masked, restore


def clean_blank_lines(text):
    # Collapse 3+ blank lines left behind into a single blank line.
    return re.sub(r"\n[ \t]*\n[ \t]*\n+", "\n\n", text)


def process(text, strip_html):
    masked, restore = protect_regions(text)

    removed = {"blade": 0, "html": 0}

    def drop_blade(m):
        removed["blade"] += 1
        return ""

    masked = BLADE_COMMENT.sub(drop_blade, masked)

    if strip_html:
        def drop_html(m):
            if IE_CONDITIONAL.search(m.group(0)):
                return m.group(0)
            removed["html"] += 1
            return ""
        masked = HTML_COMMENT.sub(drop_html, masked)

    result = restore(masked)
    result = clean_blank_lines(result)
    return result, removed


def iter_targets(path):
    if os.path.isfile(path):
        if path.endswith(".blade.php"):
            yield path
        return
    for dp, dn, fn in os.walk(path):
        dn[:] = [d for d in dn if d not in {"vendor", "node_modules", ".git"}]
        for f in fn:
            if f.endswith(".blade.php"):
                yield os.path.join(dp, f)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("path")
    ap.add_argument("--write", action="store_true", help="apply changes")
    ap.add_argument("--html", action="store_true",
                    help="also remove non-conditional HTML comments")
    args = ap.parse_args()

    if not os.path.exists(args.path):
        sys.exit(f"Not found: {args.path}")

    total = {"blade": 0, "html": 0}
    changed = 0
    html_reports = []

    for fp in iter_targets(args.path):
        with open(fp, encoding="utf-8", errors="replace") as fh:
            original = fh.read()
        new, removed = process(original, args.html)

        # Report leftover HTML comments when not stripping them.
        if not args.html:
            for m in HTML_COMMENT.finditer(original):
                if not IE_CONDITIONAL.search(m.group(0)):
                    snippet = " ".join(m.group(0).split())[:70]
                    html_reports.append((fp, snippet))

        if new != original:
            total["blade"] += removed["blade"]
            total["html"] += removed["html"]
            changed += 1
            if args.write:
                with open(fp, "w", encoding="utf-8") as fh:
                    fh.write(new)
                print(f"  cleaned  {fp}  (-{removed['blade']} blade"
                      f"{', -' + str(removed['html']) + ' html' if removed['html'] else ''})")
            else:
                print(f"  would clean  {fp}  "
                      f"(-{removed['blade']} blade"
                      f"{', -' + str(removed['html']) + ' html' if removed['html'] else ''})")

    mode = "Removed" if args.write else "Would remove"
    print(f"\n{mode}: {total['blade']} Blade comments"
          + (f", {total['html']} HTML comments" if args.html else "")
          + f"  across {changed} file(s).")

    if html_reports and not args.html:
        print(f"\n{len(html_reports)} HTML comment(s) left in place "
              f"(review manually, or re-run with --html):")
        for fp, snip in html_reports[:30]:
            print(f"  {fp}: {snip}")
        if len(html_reports) > 30:
            print(f"  ... and {len(html_reports) - 30} more")

    if not args.write:
        print("\nDry run. Re-run with --write to apply.")


if __name__ == "__main__":
    main()
