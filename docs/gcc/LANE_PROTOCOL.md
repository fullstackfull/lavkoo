# Lane protocol — the fast shape, from 2026-11-22

The owner asked for faster execution without giving up the money and security tests. This
file is the protocol every lane follows from now on. It replaces the four-agent
Implement → Review → Review → Fix shape.

## What the old shape actually cost — measured, not estimated

Per-agent start and end times, read from the four completed lanes' own transcripts.

| Lane | Implement | Reviews (wall-clock) | Fix | Wall total |
|---|---|---|---|---|
| marketing consent | 141 min | 29 min | 127 min | **297 min** |
| order notifications | 136 min | 23 min | 136 min | **295 min** |
| GCC-21 stages A+B | 130 min | 27 min | 173 min | **330 min** |
| tills + restock (two features) | 126 / 139 min | 44–61 min | 193 / 69 min | **401 min** |

**The two reviews are 8–10% of the elapsed time, because they run in parallel.** Dropping
one of them saves almost no time at all — it saves roughly a quarter of the tokens, which is
worth having, but it is not where the hours are.

**The hours are in Implement (~45%) and Fix (~43–52%).** The Fix round costs as much as
building the feature did, because it rebuilds its own worktree and re-measures everything the
implementer already measured.

Directory-level test-group runs per lane, counted from the transcripts (upper bound: a
command appears both where it is issued and where its output is echoed): **108, 25 and 67**.
That is the repeated-large-group cost, confirmed.

## The shape

**Two agents. A third only for a named reason.**

### 1. `build`
Implements the change. Also:
- **self-review before pushing** — read the diff as if it were someone else's. Every lane
  that did this found a real defect before any reviewer saw it;
- **targeted tests while developing**: a single file, or `--filter`, on SQLite. Not a
  directory group;
- **mutations only on the lines that move money or enforce a security boundary**, named one
  by one. Not a sweep of everything the branch touched;
- **the right directory group exactly once, on the final head of the batch**, on SQLite and
  on the real engine if money or schema is involved;
- leaves its worktree in place and **names the path in its return**, so the next agent does
  not build one.

### 2. `review-and-fix`
One focused review, which **fixes what it finds in the same pass** — the owner's rule: a
defect found is a defect fixed and tested, not the start of another cycle. It:
- **reuses the build agent's worktree.** It builds a second worktree at the base *only* when
  a specific before/after figure is actually in question, not as a matter of course;
- re-runs **only the tests touching what it changed**, plus the directory group **once** at
  the end;
- reads CI **once** on the final head. There is one CI read per head, not one per agent.

### 3. A second reviewer — only when one of these is true
- money moves on the path, or the change touches a settlement, refund, wallet or ledger;
- a security or authorisation boundary changes;
- a specific technical reason, stated in the brief.

GCC-21 Stage C, D2-V1 and E-11 all qualify under the first two, which is why they were given
two reviewers and are being allowed to finish that way.

## The three repeated steps that are now dropped

They were dropped because each one re-proved something already proved, not because the
evidence was unnecessary:

1. **The reviewer's own worktree at the base branch.** Needed only to settle a disputed
   before/after number. Otherwise the implementer's measured figure plus a spot-check of the
   code is the same evidence for a fraction of the time.
2. **Re-running the whole group after every finding in the fix round.** Affected tests per
   finding; the group once at the end.
3. **The second reviewer re-deriving the same CI read.** One read per head, shared.

## What is NOT dropped, at any speed

- The **real-engine run** on anything that touches money, a decimal column, a lock or a
  unique index. SQLite does not answer those questions.
- **Mutation checks on money and security lines.** A guard no mutation can kill is not
  proved, and that is exactly where it matters.
- **Reading CI on the final head before claiming a criterion**, and never calling a red
  green.
- **The "not proved" sentence** on every criterion ruling. It costs nothing and it is the
  record's only defence against drift.
- Never skipping, deleting or weakening a test to make a batch green.

## Expected effect

On the measured figures: ~300 minutes per lane down to roughly 120–150, chiefly by removing
the separate fix round's rebuild-and-re-measure and by cutting directory-group runs from
dozens to a handful.
