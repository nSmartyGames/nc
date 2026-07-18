---
name: mails
description: General inbox triage for contact@nicolaecatrina.com — flagged/unread mail across every topic (events, impulsionare/AMRITA, Rudra initiation, KS26, monthly courses, "didn't receive" questions, anything else). Use when the user says "check mail", "process mail", "/mails", or wants the inbox swept. Draft-first by default: only Rudra-Șiva (location detected) and AMRITA/impulsionare (eligible) signups are auto-answered + auto-filed; everything else — including questions you're not sure how to answer — gets a draft for the admin to edit and send. Payment questions get the student's actual payment status looked up and inserted into the draft. For WooCommerce order intake specifically, use the daily-orders skill instead.
---

# Mails — general inbox triage

Sweeps `contact@nicolaecatrina.com` (unread **and** flagged) via `airtable-proxy.php?action=email_scan`
and sorts every message into one of two buckets:

1. **Auto-answered** (narrow, high-confidence cases only) — confirmation email sent + record written,
   no draft, nothing left to do.
2. **Draft** (everything else, including anything ambiguous) — a to/subject/body draft is prepared,
   but **nothing is sent automatically**. The admin edits and sends it themselves (in `emails.html`,
   or by asking here to send a specific one via `send_admin`).

This is the standing rule: **when in doubt, draft — never guess-send.**

## The two auto-answered cases
Both require the sender's email to be present and valid; if not, they fall through to a draft instead.

- **Rudra-Șiva initiation signup**, KS26 retreat's initiation add-on (subject/body contains "rudra")
  — if a location (**Cluj** / **Bucuresti** / **Costinesti**) is detected in the text: writes the
  `rudra` column in `ks26.csv` via `ks26_upsert`, sends a terse confirmation ("Confirmăm înscrierea la
  inițierea Rudra-Șiva, locația X."). No location detected → draft instead, don't guess.
- **AMRITA SHAKTIPATA** ("impulsionare", the Bucharest ceremony) — eligibility is computed server-side
  exactly like `mm_initiation.html`'s `checkEligibility()`: needs a prior `Part 1` **or** `Part 2`
  (aprofundare workshop) row for that email/name in `mm_initiation.csv`, and not already having used up
  the one Cluj/Iasi initiation slot for that part. If eligible **and** not already queued (`part=Bucuresti`
  row already present) → appends a `part=Bucuresti` row via `csv_append`, sends "Confirmăm participarea
  dumneavoastră." Ineligible, already-queued, or ambiguous → draft (the draft already explains the
  eligibility criteria to the sender, admin decides whether to send as-is or personalize).
- Anything else that smells like an initiation/ceremony request (Mahamrityunjaya mantra rounds, other
  cities, etc.) is **not** auto-handled — different event, different CSV (`mahamrityunjaya.csv` via
  `initiations_append`), needs a human read.
- **KS26 live/replay confirmation** (category `ks26`, mentions reluare/replay + confirmare, or the
  100 RON / ~19 EUR combo amount) — looks up the sender's existing `ks26.csv` row by email:
  already `live` → upgraded **in place** to `both` (L+R) so it doesn't create a duplicate row;
  already `replay`/`both` → no CSV change, just re-confirms; no row at all → added fresh as
  `both` (L+R) if the message names the 100 RON/19 EUR combo amount, else `replay`-only (tax
  defaults to the detected amount or 100). Confirmation email's language (RO/EN) is decided from
  the words in the incoming message itself, not the sender's name.

## Payment questions ("I didn't receive course X")
Category `q` (a question mark, or phrasing like "not received" / "haven't received" / "no access").
The scan detects the course being asked about and looks up the sender's actual status — Airtable
`subscriptions[course]` (`curr`/`next`/group) for monthly courses, or `ks26.csv` for KS26 — and drops
the real answer into the draft in place of the placeholder bracket text. The admin still reviews and
sends by hand; this just means they don't have to look the payment up themselves.

## Running it
```
python3 .claude/skills/mails/mails.py scan
```
Prints: auto-handled log (nothing to do), then each pending category with enough detail to see at a
glance what's needed (course/group for monthly, eligibility/location for initiation, payment_check for
q), then the full JSON. Never marks anything read on its own (`mark_read` defaults off) — pass
`--mark-read` only if you want the scan itself to also clear read state on messages it *doesn't*
auto/act on (rarely what you want; the in-app UI's own read/flag controls are usually better for that).

To send one hand-edited reply from here directly:
```
python3 .claude/skills/mails/mails.py send "person@example.com" "Re: subject" "message body"
```

## In-app version
`emails.html` runs the identical `email_scan` flow with a UI: tabs for KS26 / Monthly / Initiation /
Q / Other (each a draft queue — To/Subject/Body editable, Send / Skip / Add buttons) plus a dedicated
**✓ Auto** tab that's just a log of what got auto-answered (from_name, subject, what happened) — nothing
to click there, it's already done. Card bodies for `q` and `initiation` show the payment-check /
eligibility / detected-location info inline so the admin doesn't have to open the raw body text to see
why something was or wasn't auto-handled.

## Notes
- Union of `UNSEEN` + `FLAGGED` (deduped by UID) — same pattern as `daily-orders`' `flagged_scan`, so
  a message the admin flagged for "look at this later" always resurfaces on the next scan.
- Auto-handled messages get `\Seen` set and `\Flagged` cleared immediately (nothing pending). Draft
  messages are left untouched until the admin sends/skips them from `emails.html` — flags/read-state
  are only changed on `mark_read`/explicit send there.
- WooCommerce order emails ("Comandă nouă"/"New order") are a separate, more structured flow with
  dup-checking against Airtable — use the **daily-orders** skill for those, not this one.
- `mails.py` is read/send-only from the CLI side; the bulk "insert into Airtable/CSV by hand" actions
  (for the draft categories that don't auto-file) go through the same endpoints daily-orders already
  documents (`upsert_sub`, `ks26_upsert`/`ks26_append`, `csv_append`, `initiations_append`).
