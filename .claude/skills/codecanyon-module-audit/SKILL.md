---
name: codecanyon-module-audit
description: >
  Load and run ONLY when the task directly requires this capability; if the work
  doesn't need it, do not load this skill.
  Audit a Laravel module (or any subtree) for CodeCanyon / Envato Market
  compliance before submission, and produce a detailed per-issue report with
  file path, line numbers, severity, category, rejection rationale, and a
  recommended fix. Trigger when the user asks to review/audit a module, check
  CodeCanyon compliance, find rejection risks, or prepare a Laravel project for
  Envato submission. Works on a single module path (e.g. Modules/Auction) or the
  whole app. Does NOT modify code — it only reports — unless the user explicitly
  asks for fixes afterward.
---

# CodeCanyon Module Audit (Laravel)

Review a Laravel module for the issues that cause CodeCanyon hard/soft
rejections and security risk, then emit a complete report. **Read every file in
the target path. Do not sample.** The auditor script catches pattern-matchable
issues; you must additionally read controllers, services, models, and form
requests to catch logic-level problems a regex cannot see (authorization,
race conditions, unsafe uploads, business logic).

## How to run

The user gives a target path (default `Modules/Auction`). Steps:

1. **Inventory the module.** List every file so the final report can confirm
   full coverage:
   ```bash
   find <TARGET_PATH> -type f \
     -not -path '*/vendor/*' -not -path '*/node_modules/*' \
     -not -path '*/.git/*' | sort
   ```
   Group by type: PHP (Controllers/Models/Services/Helpers/Middleware),
   Blade, JS/Vue, routes, migrations, seeders, config, assets.

2. **Run the mechanical scan** (the script is embedded below — write it to a
   temp file first, then run it):
   ```bash
   python3 /tmp/cc_audit.py <TARGET_PATH> --json
   ```
   This returns regex findings with rule IDs, file, line, and message. Treat
   these as *candidates* — confirm each by reading the line in context before
   reporting (kill false positives, e.g. a `{!! !!}` rendering a trusted icon
   constant, or `DB::raw` with no interpolated variable).

3. **Read for logic-level issues** the scan can't catch. For the Auction
   module specifically, open every controller/service/model/request and check
   the items in "Manual review checklist" below.

4. **Write the report** in the exact format under "Report format". Every issue
   gets file + line(s) + severity + category + description + rejection
   rationale + recommended fix. End with a coverage list (every file reviewed)
   and a severity summary count.

5. **Do not edit code** during the audit. After delivering the report, offer to
   implement fixes if the user wants.

## The auditor script

Write this to `/tmp/cc_audit.py`, then run it. It is heuristic; every hit must
be human-confirmed in context.

```python
#!/usr/bin/env python3
"""CodeCanyon/Laravel compliance scanner. Usage: cc_audit.py <path> [--json]"""
import os, re, sys, json, argparse
SKIP = {".git","node_modules","vendor","dist","build",".idea","__pycache__",".vscode","coverage"}
SCAN_EXT = {".php",".js",".jsx",".ts",".vue",".css",".scss",".html",".htm"}
def ext_of(p): return ".php" if p.endswith(".blade.php") else os.path.splitext(p)[1].lower()
RULES = [
 ("SEC-SQL","Critical","Security",{".php"},
  re.compile(r"""(?:DB::(?:select|statement|update|insert|delete|raw)|->whereRaw|->havingRaw|->orderByRaw|->selectRaw|->fromRaw|->groupByRaw)\s*\(\s*["'][^"']*\$"""),
  "Raw SQL containing an interpolated PHP variable (SQL injection risk).",
  "Use parameter bindings (second arg array with ? placeholders) or Eloquent query builder."),
 ("SEC-XSS","High","Security",{".php"},
  re.compile(r"\{!!\s*\$"),
  "Unescaped Blade output ({!! $var !!}) on a variable — XSS if the value is user-influenced.",
  "Use {{ $var }} for auto-escaping; reserve {!! !!} for values you sanitize/trust (e.g. via Purifier)."),
 ("SEC-CSRF","High","Security",{".php"},
  re.compile(r"VerifyCsrfToken|withoutMiddleware\(\s*['\"].*Csrf|->withoutMiddleware\(\s*\\App"),
  "CSRF protection possibly disabled/bypassed.",
  "Keep web CSRF middleware enabled; use @csrf in forms and X-CSRF-TOKEN for AJAX."),
 ("SEC-EVAL","Critical","Security",{".php"},
  re.compile(r"\b(?:eval|exec|shell_exec|system|passthru|popen|proc_open)\s*\("),
  "Dangerous exec/eval call — reviewers treat this as malware.",
  "Remove. If shell work is unavoidable, use a vetted package and escapeshellarg(); usually it is avoidable."),
 ("SEC-EVAL","Critical","Security",{".php"},
  re.compile(r"\b(?:base64_decode|gzinflate|str_rot13|gzuncompress)\s*\(\s*['\"][A-Za-z0-9+/=]{40,}"),
  "Obfuscated/encoded payload — flagged as malicious code, instant hard-reject.",
  "Remove the obfuscated block entirely; ship readable source."),
 ("SEC-MASS","High","Security",{".php"},
  re.compile(r"protected\s+\$guarded\s*=\s*\[\s*\]\s*;"),
  "$guarded = [] disables mass-assignment protection.",
  "Define an explicit $fillable whitelist on the model."),
 ("SEC-REQALL","Medium","Security",{".php"},
  re.compile(r"->(?:create|update|fill)\(\s*\$request->all\(\)\s*\)"),
  "Mass-assigning $request->all() — couples with weak $fillable to allow field injection.",
  "Pass $request->validated() from a FormRequest, or an explicit field array."),
 ("SEC-UPLOAD","High","Security",{".php"},
  re.compile(r"->(?:move|storeAs|store)\([^)]*->getClientOriginalName\(\)"),
  "File stored using the client-supplied original name (path traversal / overwrite / bad extension).",
  "Generate a random name (Str::uuid()), validate mime/extension/size, store outside webroot or on a disk."),
 ("SEC-UPLOAD-VAL","Medium","Security",{".php"},
  re.compile(r"\bvalidate\([^)]*'[^']*'\s*=>\s*'[^']*\bfile\b(?![^']*\bmimes\b)"),
  "File validated as 'file' without a mimes/extension/size constraint.",
  "Add mimes:jpg,png,... and max:NNNN to the validation rule."),
 ("CRED-HARDCODE","Critical","Security",{".php",".js",".ts",".vue"},
  re.compile(r"""(?:api[_-]?key|secret|password|passwd|token|access[_-]?key)\s*[:=]?>?\s*['"][^'"]{8,}['"]""", re.I),
  "Possible hardcoded credential / API key / secret in source.",
  "Move to config + .env; reference via config('services.x.key'); never commit the real value."),
 ("CRED-PATH","Low","Code Quality",{".php",".js"},
  re.compile(r"""['"](?:/(?:home|Users|var/www|mnt|c:\\)[^'"]+)['"]""", re.I),
  "Absolute local filesystem path hardcoded (environment-specific).",
  "Use storage_path()/base_path()/config; never ship machine-specific paths."),
 ("CRED-URL","Medium","CodeCanyon Compliance",{".php",".js",".vue",".blade.php"},
  re.compile(r"""['"]https?://(?:localhost|127\.0\.0\.1|[^'"/]*\.test|[^'"/]*\.local)[^'"]*['"]"""),
  "Hardcoded localhost/dev URL.",
  "Use config('app.url') / route() / url() helpers."),
 ("ASSET-CDN","High","CodeCanyon Compliance",None,
  re.compile(r"""(?:href|src)\s*=\s*['"]https?://(?:cdn\.|cdnjs|unpkg|jsdelivr|ajax\.googleapis|stackpath|maxcdn|bootstrapcdn|fonts\.googleapis|code\.jquery)""", re.I),
  "CDN-hosted asset — CodeCanyon requires assets bundled locally.",
  "Download the library into resources/ and reference via Vite/asset(); host fonts locally."),
 ("ASSET-INLINE-CSS","Low","CodeCanyon Compliance",{".php",".html",".htm",".vue"},
  re.compile(r"""<[a-zA-Z][^>]*\sstyle\s*=\s*['"][^'"]+['"]"""),
  "Inline style attribute.",
  "Move styling to a compiled stylesheet/utility classes."),
 ("HTML-NEST","Low","Code Quality",{".php",".html",".htm",".vue"},
  re.compile(r"<(?:span|em|a|strong|b|i|label)\b[^>]*>\s*<(?:div|h[1-6]|p|ul|ol|section|article)\b", re.I),
  "Block-level element nested inside an inline element (invalid HTML).",
  "Restructure markup so block elements are not children of inline elements."),
 ("DBG-PHP","High","Code Quality",{".php"},
  re.compile(r"(?<![\w>])(?:dd|dump|var_dump|ray|vd|vdd)\s*\("),
  "Debug dump left in code.",
  "Remove before submission."),
 ("DBG-LOG","Low","Code Quality",{".php"},
  re.compile(r"\b(?:Log::debug|logger\(\)->debug|error_log)\s*\("),
  "Debug logging statement — review whether it belongs in shipped code.",
  "Remove ad-hoc debug logging; keep only intentional, meaningful logging."),
 ("DBG-JS","Medium","Code Quality",{".js",".jsx",".ts",".vue"},
  re.compile(r"console\.(?:log|debug|warn|info)\s*\("),
  "console.* debug statement.",
  "Remove before submission."),
 ("DBG-INFO","Critical","Security",{".php"},
  re.compile(r"\bphpinfo\s*\(\)"),
  "phpinfo() exposes server internals.",
  "Remove entirely."),
 ("QUAL-TODO","Low","Code Quality",None,
  re.compile(r"//\s*(?:TODO|FIXME|HACK|XXX)\b|#\s*(?:TODO|FIXME)\b"),
  "TODO/FIXME marker — signals unfinished work to reviewers.",
  "Resolve or remove before submission."),
 ("QUAL-DEADROUTE","Medium","Code Quality",{".php"},
  re.compile(r"Route::\w+\(\s*['\"]/?(?:test|demo|debug|tmp|temp)['\"]"),
  "Test/debug route present.",
  "Remove temporary routes."),
 ("QUAL-DIE","High","Code Quality",{".php"},
  re.compile(r"(?<![\w>])(?:die|exit)\s*\(\s*['\"]"),
  "die()/exit() with a message — debugging artifact or poor error handling.",
  "Use proper exceptions / responses."),
]
INLINE_STYLE_BLOCK = re.compile(r"<style\b", re.I)
def walk(root):
    for dp,dn,fn in os.walk(root):
        dn[:] = [d for d in dn if d not in SKIP]
        for f in fn:
            e = ext_of(f)
            if e in SCAN_EXT or f.endswith(".blade.php"):
                yield os.path.join(dp,f), e
def scan(root):
    out=[]; n=0
    for path,e in walk(root):
        n+=1
        try: lines=open(path,encoding="utf-8",errors="ignore").readlines()
        except OSError: continue
        rel=os.path.relpath(path,root)
        for i,line in enumerate(lines,1):
            for rid,sev,cat,exts,rx,desc,fix in RULES:
                if exts is not None and e not in exts: continue
                if rx.search(line):
                    out.append({"rule":rid,"severity":sev,"category":cat,
                                "file":rel,"line":i,"description":desc,
                                "fix":fix,"snippet":line.strip()[:200]})
    return out,n
def main():
    ap=argparse.ArgumentParser(); ap.add_argument("root"); ap.add_argument("--json",action="store_true")
    a=ap.parse_args()
    if not os.path.isdir(a.root): print("Not a dir: "+a.root,file=sys.stderr); sys.exit(2)
    f,n=scan(a.root)
    order={"Critical":0,"High":1,"Medium":2,"Low":3}
    f.sort(key=lambda x:(order.get(x["severity"],9),x["file"],x["line"]))
    if a.json: print(json.dumps({"scanned":n,"findings":f},indent=2)); return
    print(f"Scanned {n} files, {len(f)} candidate findings.\n")
    for x in f:
        print(f"[{x['severity']}/{x['category']}] {x['file']}:{x['line']} {x['rule']}")
        print(f"  {x['description']}")
        print(f"  > {x['snippet']}")
        print(f"  FIX: {x['fix']}\n")
if __name__=="__main__": main()
```

## Manual review checklist (read the code — regex can't catch these)

Authorization & permissions
- Every state-changing action (place bid, edit/cancel/close auction, declare
  winner, refund) checks ownership/role via Policy, Gate, or middleware — not
  just `auth()->check()`.
- IDs from the request are authorized, not just trusted (IDOR: can user A act
  on user B's auction/bid by changing an id?).
- Admin-only endpoints are behind admin middleware, not just hidden in the UI.

Auction business logic
- Bid amount validated: numeric, > current highest, ≥ min increment, within
  any max; rejects negative/zero/NaN/huge values.
- Concurrency: two simultaneous bids can't both win — use DB transaction +
  row lock (`lockForUpdate`) or atomic update, not read-then-write.
- Time logic: server-side enforcement of start/end; can't bid after close;
  timezone handled consistently.
- Money handled as integers/decimals (never floats); currency consistent.
- Winner selection and any auto-bid/proxy logic can't be gamed.

File uploads (auction images/attachments)
- Random server-side filename; mime + extension + size validated; not executed
  from webroot; SVG sanitized or disallowed.

Data exposure & queries
- API/JSON responses use Resources/explicit fields, not raw models leaking
  hidden columns (password, tokens, internal flags).
- Eager-load relations to avoid N+1 inside loops/Blade.
- No `select *` returning sensitive columns to the client.

Migrations & seeders
- No real/sensitive seed data (real emails, live keys); demo data only.
- Foreign keys, indexes, and money column types (decimal) are sane.
- Migrations are reversible (`down()`), no destructive raw SQL.

CodeCanyon packaging (module-level)
- No CDN assets; no inline CSS; no hardcoded dev URLs/credentials.
- Third-party libraries bundled with a redistribution-compatible license;
  note each license in the report's Licensing section.
- No leftover debug/test files, `.env`, or compiled junk inside the module.

## Report format

Produce a Markdown report (offer to also save it as a file). Structure:

1. **Summary** — module path, files reviewed count, and a severity tally
   (Critical/High/Medium/Low) with a one-line verdict on submission readiness.
2. **Findings** — one numbered entry per issue, each with these fields:
   ```
   ### [N] <short title>
   - **File:** <relative/path>
   - **Line(s):** <n> (or n–m, or "module-level")
   - **Severity:** Critical | High | Medium | Low
   - **Category:** Security | CodeCanyon Compliance | Performance | Code Quality | Licensing
   - **Description:** what the issue is, with the offending snippet.
   - **Why it risks rejection / harm:** concrete reviewer/security rationale.
   - **Recommended fix:** specific, actionable; show corrected code where short.
   ```
   Order by severity (Critical first). Group regex-confirmed and logic-level
   findings together — the reader shouldn't care which detector found them.
3. **Licensing notes** — every third-party package/asset in the module with its
   license and any attribution/redistribution concern.
4. **Coverage** — the full file list from step 1, so the user can confirm
   nothing was skipped. Mark each file: issues found / clean / not applicable.
5. **Next steps** — prioritized fix order; note which items block submission.

## Rules of conduct
- Read every file in the target path; never skip for brevity.
- Confirm each regex candidate in context before reporting; drop false positives
  and say so if a whole rule produced only false positives.
- Be exact with file paths and line numbers; if a logic issue spans a method,
  cite the method's line range.
- Report only — do not edit code unless the user asks after seeing the report.
- If the target path doesn't exist, list what *does* exist under Modules/ and
  ask the user to confirm the correct path rather than guessing.
