---
name: comment-cleanup
description: >
  Load and run ONLY when the task directly requires this capability; if the work
  doesn't need it, do not load this skill.
  Make a Laravel/PHP codebase self-documenting by removing noise comments while
  preserving the few that carry why/business/architectural context. Strips ALL
  Blade comments and low-value PHP comments. Trigger when the user asks to clean
  up / remove / reduce / declutter comments, make code self-documenting, strip
  Blade comments, or do a "comment pass" / "comment audit" across files or a repo.
allowed-tools: Bash, Read, Edit, Grep, Glob
---

# Comment Cleanup

Make a codebase self-documenting by removing noise comments while preserving the
few that carry information the code itself can't. Default to deletion; keep a
comment only when it justifies its existence.

## Core principle

Code says **what** and **how**. A good comment says **why** — or warns about
something the reader can't see. If a comment merely restates what the next line
plainly does, it's noise. Delete it.

## Decision rules

Apply these per comment:

**Blade files (`.blade.php`):** Remove every comment — both Blade comments
`{{-- ... --}}` and HTML comments `<!-- ... -->` — unless the HTML comment is
functional (e.g. IE conditional comments `<!--[if IE]>`, or a comment a build
tool/parser depends on). When unsure whether an HTML comment is decorative or
functional, keep it and note it.

**PHP files** — delete the comment if it is any of:
- Restates the code: `// loop through users`, `// return the result`,
  `// set name`, `// constructor`.
- Redundant with a clear name: `// get user by id` above `getUserById()`.
- Outdated / stale: references removed code, old ticket numbers long closed,
  "TODO" that's already done, commented-out code.
- Section banners with no content: `//////// METHODS ////////`, `// ---`.
- Auto-generated boilerplate: default IDE/framework stubs like
  `// Define the model's default values for attributes.` that add nothing.
- Obvious type/param echoes: `@param int $id The id` (no new info).

**Keep** (these earn their place):
- **Why** a non-obvious choice was made: `// Stripe rounds half-up, so we match
  it to avoid reconciliation drift`.
- **Business rules / domain constraints**: `// FMCG tenants bill on net-30; do
  not change without finance sign-off`.
- **Architectural reasoning**: `// Runs in the tenant connection, not central —
  see TenancyServiceProvider`.
- **Warnings / gotchas**: `// Order matters: must dispatch after commit or the
  job sees stale data`.
- **Non-obvious implementation notes**: workarounds for upstream bugs, perf
  reasons, security caveats, regex intent.
- **Legally/structurally required**: license headers, `@deprecated` with
  migration path, meaningful `@throws`, IDE-significant annotations
  (`@mixin`, `@property`, `@template`, `@var` that types something untyped),
  PHPStan/Psalm directives (`@phpstan-ignore`, `// @codeCoverageIgnore`).

**Condense, don't delete** — when a kept comment is a 2–3+ line block, reduce it
to the essential 1–2 lines (strongly prefer one). Keep the load-bearing
sentence; drop the throat-clearing.

**Refactor-instead** — if the only reason a comment exists is to explain a bad
name or murky structure, prefer making the code self-explain (rename variable,
extract method, introduce a well-named constant) and remove the comment. Only do
this when the rename is local and unambiguous; if it would ripple across the
codebase or change a public API, leave a 1-line comment instead and flag it.

## What never to touch

- **Code itself** — only comments. The sole exception is the rename/extract
  refactor above, and only when it's safe and local.
- **Docblocks that tools rely on**: annotations consumed by the IDE, static
  analysers, or the framework (see "Keep" list). When in doubt, keep the
  annotation lines and trim only the prose.
- **Strings and content that look like comments but aren't** — `#` inside a
  string, `//` in a URL or regex, `--` inside SQL string literals, Blade
  `@verbatim` blocks, comments inside `<script>`/`<style>` that are real CSS/JS
  hacks.
- **Doc files, stubs, vendor/**, generated migrations you don't own.

## Workflow

1. **Scope it.** Confirm the target (whole repo vs. a directory) and the file
   types. Default Laravel scope: `app/`, `resources/views/`, plus `routes/`,
   `database/`, `tests/` if asked. **Always exclude** `vendor/`, `node_modules/`,
   `storage/`, `bootstrap/cache/`, and build output.

2. **Snapshot / safety.** Make sure the work is under version control or take a
   copy first, so the change is reviewable as a diff. Never run a destructive
   pass on uncommitted, unbacked code.

3. **Inventory.** Run the helper to see where comments live and roughly how many,
   so you can sanity-check the scale before changing anything:
   ```bash
   python3 .claude/skills/comment-cleanup/scripts/comment_report.py <project_root>
   ```
   This lists files with comment counts, separating Blade comments, PHP `//`,
   `#`, `/* */`, and docblocks, and flags commented-out code. Flags:
   `--top N` (show N noisiest files, default 40), `--json` (machine-readable).
   Use the ranking to prioritise the noisiest files in step 5.

4. **Blade pass (mechanical).** Blade comment removal is deterministic, so it can
   be scripted. Use the helper for the bulk `{{-- --}}` removal, then review:
   ```bash
   python3 .claude/skills/comment-cleanup/scripts/strip_blade_comments.py <views_dir> --write
   ```
   Run without `--write` first to preview. It removes `{{-- --}}` blocks
   (including multi-line) and is conservative about HTML comments — by default it
   leaves `<!-- -->` alone and only reports them, so you decide per case. It skips
   `@verbatim` regions and won't touch `{{-- --}}`-looking text inside strings.
   Add `--html` to also strip non-conditional `<!-- -->` comments (IE
   conditionals are always preserved) once you've reviewed the reported list.

5. **PHP pass (judgment).** Do NOT blanket-script PHP comment removal — the
   keep/delete decision needs reading. Go file by file (or directory by
   directory), read each comment, apply the decision rules, and edit. Batch
   similar files for consistency (all Controllers, then all Services, …). Use the
   inventory from step 3 to prioritise the noisiest files. For obvious noise at
   scale (e.g. a repeated generated stub line across dozens of files), a targeted
   `grep` + scripted removal of that exact line is fine — but verify the matches
   first.

6. **Verify nothing broke.**
   - Blade: views still render / no stray `--}}`. Run the app's view cache clear
     or a quick `php artisan view:clear` if available.
   - PHP: lint the changed files — `php -l <file>` per file, or
     `find app -name '*.php' -print0 | xargs -0 -n1 php -l`. If the project has
     Pint/PHPStan, run them. Comments-only edits should not change behaviour or
     break parsing.

7. **Report.** Summarise: files touched, comments removed vs. kept (and why a
   few notable ones were kept), any comments you condensed, and any
   rename/refactor suggestions you did NOT apply because they'd ripple — list
   those for the user to decide.

## Edge cases & judgment calls

- **Commented-out code**: delete it. Version control is the history. The only
  exception is a clearly-labelled "intentionally disabled, see #1234" with a
  reason — keep that as a 1-line note if the reason is live.
- **Bilingual / non-English comments**: same rules; don't delete a comment just
  because it's not in English — judge it on information content.
- **`@param`/`@return` docblocks**: in typed PHP (PHP 8+ with native types),
  redundant `@param`/`@return` that only echo the signature can go; keep them
  when they add description, a typed array shape (`@param array<int,User>`), or
  the IDE/static analyser needs them.
- **TODO/FIXME**: keep if still actionable and informative; delete if stale or
  vague (`// TODO: fix later`). When keeping, leave it as-is.
- **Big banner/license headers at file top**: keep license headers; remove
  decorative ASCII banners.

## Anti-patterns to avoid

- Don't strip comments so aggressively that genuine business logic becomes a
  mystery. The goal is *self-documenting*, not *undocumented*.
- Don't "fix" by deleting a comment AND leaving a confusing name — either the
  code now reads clearly, or a short comment stays.
- Don't touch behaviour. If you catch yourself editing logic, stop — that's out
  of scope unless it's a safe local rename you've reasoned through.
- Don't script the PHP judgment pass blindly. Reading is the point.
