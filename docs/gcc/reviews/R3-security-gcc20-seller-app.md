# R3: Security, GCC-20 Super Admin (seller app)

As of 2026-11-18. **Not requested. No reviewer is named. No outcome is recorded.** The register is [REVIEW_GATES.md](../REVIEW_GATES.md).

## What the reviewer decides

Whether the app side of the platform-admin interface (the shell, the second factor, capabilities, sessions) keeps the secret where the platform keeps it and shows nothing a role may not see.

## Pull requests (all drafts, repository `lavkooappseller`)

| PR | Head | What it is | CI of that head |
|---|---|---|---|
| [lavkooappseller 4](https://github.com/fullstackfull/lavkooappseller/pull/4) | `716f84d9` | a read-only platform Super Admin shell | **red** (`analyze-and-test` failed when it was read on 2026-11-18; the cause is not fixed here) |
| [lavkooappseller 6](https://github.com/fullstackfull/lavkooappseller/pull/6) | `e4d7d029` | step 2, stacked on #4: second factor, capabilities and sessions | no run |

The server side is [R2](R2-security-gcc20-server.md): this review reads the app against that API.

## Read in this order

The pull request bodies, then the files they add under `lib/features/platform_admin`. Check where the session token is stored (master tracker K-12 asks for the platform secure store and not plain `SharedPreferences`), what the app does on sign-out, and what a role without a capability sees.

## Not claimed

The app was not built or run on a device by this work, and there is no Flutter toolchain in the container that wrote it: the only evidence is the app repository's own CI, which is red on PR 4 and absent on PR 6.

## Outcome (written by the reviewer, in the pull request and here)

| Reviewer | Date | Head read | Outcome | Link |
|---|---|---|---|---|
| | | | | |
