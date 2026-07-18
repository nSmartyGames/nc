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
(`student_exists`, `has_course`, `curr`, `next`, `already_paid`). When `course` couldn't be parsed
from the order text, also carries `course_source: "inferred_from_student"` (single-course fallback
applied) or `course_candidates` (multiple courses on file, needs a manual pick).

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
- **Course/group not parsed from the order** (blank nota, unusual wording): `orders_scan` now
  falls back to the student's existing Airtable subscription — if they have exactly one course on
  file, it's filled in automatically (`course_source: "inferred_from_student"`, flagged
  `[course inferred from Student table]` in the scan table) and the existing group is reused too.
  If the student has **more than one** course, it's left blank with `course_candidates` listing
  them (shown as `course unclear — student has: X, Y`) — don't guess; use the paid amount (`total`)
  and the nota/month wording (which usually states the month(s) directly) to pick the right one by
  hand, same as any other ambiguous case.
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
`orders_scan`/`orders_mark` only match the WooCommerce "Comandă nouă"/"New order" subject pattern.
Everything else in the inbox — ceremony/initiation requests (Rudra-Șiva, AMRITA SHAKTIPATA,
Mahamrityunjaya mantra rounds), monthly-course questions, "didn't receive" complaints, anything —
is handled by the **`mails`** skill (`.claude/skills/mails/`), which wraps `email_scan`: it
auto-answers + auto-files only Rudra-Șiva (location detected) and AMRITA/impulsionare (eligible)
signups, and drafts everything else (including payment-status lookups for "didn't receive" questions)
for the admin to review and send by hand. Use `mails`, not this section, for that sweep.

`flagged_scan` / `email_search` / `sent_scan` (read-only, documented in the proxy) remain useful for
ad-hoc one-off lookups outside that flow — e.g. checking whether a specific person already got a
personal reply before following up manually.
- Confirmation emails go out via `send_admin` (`{to, subject, message}`) — this also archives a
  copy to `INBOX.Sent`, matching how the admin's manual replies show up.

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
