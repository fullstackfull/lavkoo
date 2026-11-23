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

## Measured, lane by lane

The owner asked that the target not be called met until it is measured over several new
tasks. This table is that measurement, and it is filled in as lanes finish. Wall-clock and
agent minutes come from the lanes' own transcripts; tokens and tool calls from the run
record.

| Lane | Shape | Wall | Tokens | Tool calls | Note |
|---|---|---|---|---|---|
| marketing consent | old, 4 agents | 297 min | 1.62 M | 652 | broad scope |
| order notifications | old, 4 agents | 295 min | 1.37 M | 455 | broad scope |
| GCC-21 stages A+B | old, 4 agents | 330 min | — | — | broad scope |
| D2-V1 refusal | old, 4 agents | **130 min** | 1.54 M | 544 | **narrow scope** |
| J-11 push per storefront | **new, 2 agents** | **106 min** | **0.67 M** | **236** | narrow scope |
| seller K axis on one tree | **new, 2 agents** | **78 min** | **0.69 M** | **271** | narrow scope, 9 criteria |
| E-11 till server total | old, 4 agents | **385 min** | **1.86 M** | **694** | money lane, in flight before the change |

**The fifth old-shape lane is the most expensive of the six**, at 385 minutes and 1.86 M tokens for one
criterion — and it did not overclaim: its implementer proposed MET, both reviewers ruled CODED, and its fix
round lowered its own branch to CODED. The cost bought a real correction and two defects outside the criterion,
one of them platform-wide. It is the clearest single contrast in the table: a four-agent money lane on a narrow
scope still costs three to five times a two-agent lane on nine criteria.

**Two separate effects, and they must not be confused.** D2-V1 ran the OLD four-agent shape
and still took 130 minutes, because its scope was one mechanism and three callers. So most
of the fall from ~300 to ~130 is **scope, not shape**. What the new shape adds on top is
visible in the last row: J-11 came in at 106 minutes for **less than half the tokens and
half the tool calls** of a comparable old-shape lane. Cost is where the two-agent shape pays;
wall-clock is mostly bought by keeping the batch narrow.

The third new-shape lane came in at **78 minutes** for nine criteria — below the 120–150 target rather
than inside it, and the cheapest of the five by tokens per criterion. Three lanes is still not a
measurement to retire the question on, but the shape is holding: **both new-shape lanes are under half the
tokens and under half the tool calls of a comparable old-shape lane**, and neither skipped a money or
security test to get there.

What the third lane also shows is that the saving is not coming out of rigour. It ran 19 mutations, all 19
caught, attacked eight of its own MET claims with sixteen mutations chosen to kill them, corrected the
record's own pessimism upward in two places, and closed a gap its build half had admitted in writing it
had not tested.

## A second rule, from the third lane: a CI change is reviewed as a gate

The third lane was told to move named CI steps ahead of the whole-suite step, because a criterion asks for
named steps *before* the full run. It did that, and verified the move was pure — every step's name, condition,
command, action and inputs byte-identical, nothing dropped.

**The move was pure and the result was still wrong.** The whole-suite step carried no condition, so the
runner's default `success()` applied. With the named steps now ahead of it, one red named step would have
*skipped the whole run* — and with it the only figures that say how many tests fail and which. A green-looking
job, a hidden failure, and nothing in the diff to see it by.

So: **a change to how CI decides what runs is a change to what a failure can hide, and it is reviewed as a
gate rather than as a refactor.** Purity of the move is not the question. The questions are: after this change,
what can be skipped that could not be skipped before; what figures stop being printed; and is there a test
that reads the shipped workflow and fails if the shape regresses. The third lane ended with all three answered
— the step now carries `always()` like the 29 named steps already did, and a test parses the shipped file and
pins the shape.

This generalises past CI. The same question belongs on any guard that decides whether a check runs at all:
a skip condition, an environment gate, a `markTestSkipped`, a feature flag around an assertion.

## One rule the first new-shape lane changed

The protocol above says mutations go only on lines that move money or enforce a security
boundary. **That is not sufficient, and J-11 proved it.** Its review pass ran eight
mutations and three of the first five survived. The worst was a one-word change to the
object the app actually builds, which survived 73 tests while silently stopping every
marketing push on every existing install — because the flag-off subscription set had been
proved only on an object a test wired by hand, never on the one the shipped app constructs.

So the rule is now: mutations go on money, on security boundaries, **and on the dormancy or
flag-off guard of anything that ships switched off** — and they are run against the object
the application itself builds, not a hand-wired stand-in. A dormancy guard is exactly where
a silent break reaches every existing install, which is the same reason money and security
are on the list.
