# I2-01 and I2-03: how the legal text of a storefront is written

Status: coded and tested on SQLite and on MySQL 8.0.46 (branch `gcc/p1-legal-text-publisher`, stacked on the legal review of PR 105; no MariaDB run). It needs **no migration** (it writes the table `storefront_legal_documents`, whose migrations are already in the stack) and **no switch**: nothing reads a text of a storefront other than the Saudi one until storefronts are on, and the service is called by a command only. Nothing is MET until CI has run on a pushed head.

## What was missing

The readiness of a storefront (`StorefrontLegalReadiness`) reads the newest effective published row of each required document type and locale, and counts it only when someone other than its author approved it, it is not revoked, not blank and not an unedited template. **Nothing wrote those rows**: the table was filled by hand, so no new country could ever have the texts its readiness asks for through the platform, and an approval, a publication or a revocation left no trace.

## The publisher

`App\Services\Storefront\StorefrontLegalPublisher` is the only writer of the table. Every step is written to the audit log in the same transaction (a step whose trace cannot be written is not taken), with who took it (the id of the admin), the storefront, the type, the locale and the version, the state before and after, and the **hash** of the words (never the words).

| Step | What it does | What it refuses |
|---|---|---|
| `draftFromTemplate` | a draft that is a copy of a template; its `template_hash` is the hash of the template, so that the template unedited can be told from a text | a blank template, a country that is not a storefront, the default storefront, a type or locale that is not shaped like one, a second unpublished text of the same type and locale |
| `draftNewVersionOf` | the next version of a published text, as a draft that starts as a copy of it; the published text stays in force | a text that is not published; a second unpublished text |
| `edit` | changes a draft; an **approved** text goes back to draft and its approval is void; the editor is its author now | a published, revoked or discarded text (a published text is never edited in place); a blank text |
| `approve` | draft to approved | the **author** (the last person who changed it), a text that is still its template, a blank text, a text that is not a draft |
| `publish` | approved to published, from now or a later day | a day in the past (five minutes of tolerance), a day before another text of the same type and locale that is already scheduled (the newest by effective day wins, so a newer version must not lose to an older one scheduled for later), a text that is not approved |
| `revoke` | marks a published text revoked; **the row stays**, and while it is the text in force the storefront does not sell; a text that has **not taken effect yet** is archived instead (revoked before its day), because a revoked text that stayed published would be the newest text of its type and locale on its day and would close the storefront in the text it was meant to replace | a blank reason; a text that is not published or is already revoked |
| `discard` | puts a draft or approved text away (archived), kept as history | a published text (it is revoked) |

Nothing is deleted. The default storefront keeps the documents it has always had (GCC-19) and has no text here.

## The command

`php artisan gcc:storefront:legal-text {list|draft|edit|approve|publish|revoke|discard} --admin=ID ...` is the entry point of the operator; the admin screen with its permissions is `docs/gcc/I2-STOREFRONT-ADMIN-SCREENS.md`. It reads a template or a text from a file (at most 1 MB), refuses an admin id that is not in the table of admins, reads the day as `YYYY-MM-DD HH:MM` in UTC, says after each step what it did and whether the storefront's texts are complete now, and exits 1 with the reason in words when the service refuses. `list` shows the texts without their words. It works while the switches are off.

## What it does not do

- **The command has no permission gate.** The person is the admin id typed by whoever runs the command (an id that exists, not a name). The screen has its permissions and takes the person from the session (`docs/gcc/I2-STOREFRONT-ADMIN-SCREENS.md`); a step-up or a dual approval for publishing and revoking (I2-03 criterion 5, GCC-20 level 3) is still not built.
- **No template source.** A template is a file the operator names. The Saudi business pages are not read as templates yet.
- **No retention rule** for texts and acceptance rows (I2-01 criterion 2): nothing here deletes a row, and no rule says how long they are kept.
- **No Saudi baseline** (I2-01 criterion 1): the default storefront keeps its pages as they are.
- A new version does not withdraw the independent legal review of PR 105; whether it needs another one is the owner's decision.

## Tests

`StorefrontLegalPublisherTest` (36): the copy of a template, the second open text, the template unedited, the author as approver, the last editor as author, an edit voiding an approval, publish now and later (a moment given in UTC takes effect at that instant in an application set east of UTC, west of it and in UTC), never in the past nor before a scheduled text, a published text never edited, a new version, revoke and discard, the readiness reading exactly what the service wrote (a missing text keeps a storefront closed, a revoked one closes it until a replacement is published), refused input writing nothing, and the audit lines (who, what, the hash and not the words, nothing written when the trace cannot be). `StorefrontLegalTextCommandTest` (11): the whole path through the command, the author refused, an admin that does not exist, a file that is not there, a day that is not a day, revoke and a new version, discard, the list. Eleven mutations of the service (the author may approve, a template approved as it is, a published text edited in place, a text in the past, a text before a scheduled one, a second open text, the editor not the author, the approval surviving an edit, the copy forgetting its template, the words in the audit line, revoke archiving the row) each fail the class.

## Decisions that remain with the owner

1. Who may take each step: the permission, and whether publishing needs two people (I2-03 criterion 2).
2. Where templates come from (the Saudi page of the same type, a file kept by legal counsel, or a library).
3. Whether a new version of a text needs a new independent review.
4. How long texts and acceptance rows are kept (I2-01 criterion 2).
