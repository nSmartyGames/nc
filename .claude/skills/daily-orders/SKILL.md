---
name: daily-orders
description: Daily intake of new WooCommerce yoga orders, plus sweeping flagged/unread inquiry emails (ceremony/initiation registrations, etc.). Use when the user asks to "process orders", "fetch today's orders/payments", "add the iulie/monthly payments", "check flagged mail", or do the daily order-to-Airtable run. Scans unread AND flagged order emails (read-only), checks Airtable/CSV for duplicates, bulk-inserts next-month payments or initiation registrations, sends confirmations, and marks the emails read (clearing any flag).
---

# Daily Orders

Processes new WooCommerce orders from the `contact@nicolaecatrina.com` inbox into the Airtable
Students table, then clears the related emails. All IMAP/Airtable access goes through the
production proxy — no credentials are handled locally.

Helper script: `python3 .claude/skills/daily-orders/orders.py` (subcommands: `scan`, `insert`, `mark`).
Proxy endpoints used: `orders_scan` (read-only), `upsert_sub`, `orders_mark` (in `airtable-proxy.php`).

## Workflow — follow in order

### 1. Scan (read-only — never marks read)
```
python3 .claude/skills/daily-orders/orders.py scan 2
```
`2` = look back 2 days (today + yesterday). Returns **unread orders since that date, plus any
order emails manually `\Flagged`** in the mailbox regardless of read state (`flagged: true` on
those rows, shown as `[FLAGGED]` in the table) — flagging is how the admin marks an order for a
later look, so it's always worth a pass. Returns a table + JSON with, per order:
`onum, date, name, email, course, group, total, nota, month, flagged` and a `check` block
(`student_exists`, `has_course`, `curr`, `next`, `already_paid`).

### 2. Present the list to the user
Show: **Name, Email, Course+Group (e.g. `YTT-M1 G8`), Sum, Months (from nota), Already-paid?**
The `already_paid=true` flag means `next` is already set for that course → likely a **duplicate
payment**; call it out, don't silently re-insert.

### 3. Decide what to insert — these are NOT auto-monthly, handle by hand:
- **KS26 / "Kashmir Shaivism"** — separate retreat flow, goes to `ks26.csv` (not the Students
  table). `insert` auto-routes rows with `course:"KS26"` to `ks26_upsert` instead of `upsert_sub`
  — pass `{name, email, course:"KS26", tax, session}` (`tax` = amount paid, `session` = `live` /
  `replay` / `both`). The combo product (titled **"KS26 live and replay"**, formerly "Replay
  session for live subscription" / "Sesiune de revizionare pentru cei care au participat live")
  always means `session:"both"` — stored/shown as **L+R** in `ks26.html`. `check.already_paid` for
  KS26 rows means that email+session pair is already in `ks26.csv`, not an Airtable dup.
- **AL "Seminar N" / Alchimie seminars** — one-off seminars, not the AL monthly course. Exclude.
- **Course key typos in Airtable** (e.g. `TTY-M3` instead of `YTT-M3`): `upsert_sub` would create a
  *second* key. Fix the existing record directly via `?action=update&id=recXXX` instead.
- **No group in the order**: keep the student's existing group (upsert without `group` preserves it).
  Only set a group when the order/nota specifies one.
- **Wrong/!= billing email** (someone paying for another person, gifts): confirm the target email
  with the user before inserting. Route the payment to the actual student's record/email.
Always confirm the final insert list with the user before writing (Airtable writes are live).

### 4. Bulk insert (sets `next='P'` for next month)
Build a JSON array of the confirmed rows and pipe it in:
```
echo '[{"name":"X","email":"x@y.com","course":"YTT-M3","group":"1"}]' | \
  python3 .claude/skills/daily-orders/orders.py insert -
```
Each row: `{name, email, course, group}` (`group` optional; `status` defaults to `P`).
`upsert_sub` updates the existing student or **creates** one if the email is new.

### 5. Verify
Re-fetch and confirm `next=='P'` for each course written:
```
curl -s "https://nicolaecatrina.com/app/airtable-proxy.php?action=students" | \
  python3 -c "import json,sys; ..."   # check subs[course]['next']
```

### 6. Mark emails read (only after the user is satisfied)
Pass the buyer names of the orders just processed; this marks the order emails **and** the matching
NETOPIA `Plată înregistrată - Self Awakening` confirmations read, and **clears `\Flagged` on any of
them that were flagged**. Add `-f` to also clear `Comandă eșuată` / `Failed order` emails.
```
echo '["Buyer One","Buyer Two"]' | python3 .claude/skills/daily-orders/orders.py mark - -f
```

## Beyond WooCommerce orders: flagged/unread inquiry emails
`orders_scan`/`orders_mark` only match the WooCommerce "Comandă nouă"/"New order" subject
pattern. Registration *requests* for ceremonies/initiations (Rudra-Șiva, AMRITA SHAKTIPATA,
Mahamrityunjaya mantra rounds, etc.) usually arrive as free-form emails, not orders, and often
sit `\Flagged` in the inbox for a later manual look. To sweep those:

1. **`flagged_scan`** (read-only) — every `UNSEEN` or `FLAGGED` message since a date, any subject:
   `{"since":"20-May-2026","max":300}` → `{uid, from_name, from_email, subject, date, body, unread,
   flagged}`. Use this first to see everything currently open, then triage by topic/keyword.
2. **`email_search`** (read-only) — keyword + date search across *all* mail (not just unread), now
   also returns `seen`/`flagged` per hit: `{"keyword":"AMRITA","since":"25-Jun-2026","max":30}`.
   Useful for finding older threads on the same topic (e.g. checking whether someone already
   emailed twice, or already got a personal reply).
3. **`sent_scan`** (read-only) — search the `INBOX.Sent` folder the same way, to check whether a
   personal confirmation was **already sent** before sending another one. This site's actual
   confirmation style is terse — e.g. `"Confirmam."` or `"Confirmam participarea dumneavoastra."`
   — match that tone, don't write a paragraph.
4. Cross-check candidates against the *target* CSV/table before adding (`mm_initiation.csv`,
   `ks26.csv`, Airtable Students) — many flagged inquiries turn out to already be recorded from a
   prior pass; those just need marking read, no new write or email.
5. **`email_flag`** now accepts `{"uid":N,"flag":false,"mark_seen":true}` (in addition to the
   original `{"num":N,"flag":bool}` form) to clear `\Flagged` **and** set `\Seen` in one call for a
   specific message found via `flagged_scan`/`email_search`.

Routing by event (as of 2026-07):
- **AMRITA SHAKTIPATA** (Bucharest ceremony) → `mm_initiation.csv` via `csv_append`, `part:"new"`.
  Eligibility (`mm_initiation.html`'s `checkEligibility`) requires a prior `Part 1`/`Part 2`
  (aprofundare workshop) row for that email/name.
- **Inițiere Rudra-Șiva** (KS26 retreat's initiation add-on, *not* the retreat itself) →
  `ks26.csv`'s `rudra` column (`Cluj`/`Bucuresti`/`Costinesti`) via `ks26_upsert`. Selecting a
  location in the app *is* the confirmation, per the admin's own past reply: "în aplicație vă
  înscrieți pentru inițiere în momentul în care alegeți locația la care participați." If a second
  person shares one email (e.g. a couple registering together) and only one already has a
  `ks26.csv` row, `ks26_upsert`'s dedupe key is `email+session` — give the second person a
  different `session` value (there's no real distinguishing session, so this is just a key trick)
  so their row doesn't overwrite the first person's.
- Confirmation emails go out via `send_admin` (`{to, subject, message}`) — this also archives a
  copy to `INBOX.Sent`, matching how the admin's manual replies show up.
- Anything outside these two specific events found during a sweep (other mantra rounds, other
  cities, unrelated questions) — leave untouched; those need a human answer, not a mechanical add.

## In-app version
The same flow is available to the admin directly in `yoga.html` — a command console fixed at the
bottom of the page. Typing `/daily-orders [days]` runs scan → review (with dup flags) → insert
selected → mark read, all via the same proxy endpoints. This skill is the CLI/automation equivalent.

## Notes
- `orders_scan` opens the mailbox `OP_READONLY` + `FT_PEEK`, so scanning/listing never changes
  read or flag state. Only step 6 (`orders_mark`) sets `\Seen` / clears `\Flagged`.
- Scan matches `UNSEEN SINCE <date>` **or** `FLAGGED SINCE <date>` (union, deduped by UID) — a
  flagged order older than the lookback window won't surface; widen `days` if chasing one down.
- Status values: `P` paid, `F` forever, `G` gratis, `S` stop. Daily orders are normal `P`.
- Month transition (June→July etc.) is run separately in `yoga.html`; this skill only sets `next`.
- If `scan` returns `count: 0`, there are no new unread/flagged orders — nothing to do.
