# The tracker generator

`MASTER_IMPLEMENTATION_TRACKER.md`, `tracker/*.md` and `PR_LEDGER.md` are **generated**; do not edit them by hand. An edit is lost at the next run. This directory is the generator, so that any session can regenerate them. Until 2026-11-18 it lived only in the scratch space of one session; running it on the data below reproduces the published files byte for byte.

## What is here

| File | Role |
|---|---|
| `data.tar.xz` | The inputs: `data/journals/wf_*/journal.jsonl` (the assessor and verifier results of the master-tracker workflows), `data/context.json` (the numbers and sentences of sections 9 and 10), `data/lavkoo-pulls.json` (the pull request index the ledger reads) and the pull request lists of the two apps. |
| `gen_tracker.py` | Loads the journals, applies the verifiers' corrections, checks every cited blob with `git show`. |
| `gen_main.py` | **The state of the work.** `CRITERIA`, `UPDATES`, `NEXT_CURRENT`, `EXTRA_LINKS` and `OVERRIDES` say, per requirement and criterion, what the draft pull requests did, its state (MET, CODED, MISSING) and the CI evidence. Everything that moved after the assessors read the trees is here. Writes the tracker. |
| `gen_pr_ledger.py` | Writes `PR_LEDGER.md` from `data/lavkoo-pulls.json` and the requirements that name each pull request. |
| `batch_measure.py` | Compares the criteria of the working copy with a published ref: `python3 batch_measure.py <repo-root> [BASE_REF]` prints the moves (MISSING to CODED, CODED to MET, ...) and the totals. |

## Run it

```bash
cd docs/gcc/tools/tracker-generator
tar -xJf data.tar.xz            # creates data/ (do not commit it)
export GCC_REPOS_ROOT=/home/user  # the directory that holds the clones lavkoo, lavkooapp, lavkooappseller (for the evidence check)
python3 gen_main.py ../.. 2026-11-18          # the tracker, as of that date
python3 gen_pr_ledger.py ../.. 2026-11-18     # the ledger
python3 ../check_pr_ledger.py                 # must exit 0 before a push
python3 batch_measure.py ../../../.. origin/claude/exciting-shannon-4mh2ok   # what moved
```

`GCC_TRACKER_DATA` overrides the place of `data/`. The evidence check reads blobs of the three clones at the refs the criteria name, so those refs must be fetched; a ref that is not there is reported as an evidence failure, not hidden.

## Rules for editing `gen_main.py`

- **A criterion is MET only with a successful proof on the correct head**: a named test or job, run on that head, read job by job. A pull request body, a local run or a job that does not cover the behaviour is CODED at best.
- **The index of a criterion is its position in the requirement's list in the tracker text**; check it against the generated card before writing it (an index was wrong more than once).
- Add a block before `def apply_override`. Use `CRITERIA.setdefault(id, {}).update({...})` or `CRITERIA[id][index] = (...)`; never reassign `CRITERIA`. `_NOT_READ` removes the words "CI not read" from a text when evidence is added.
- When pull requests are added, add them to `context.json` (`lavkoo_prs`) and to `data/lavkoo-pulls.json`, then repack `data.tar.xz`.
- Never record an approval by Claude or by CI in the review state; a review is recorded only for a named person who wrote it.
