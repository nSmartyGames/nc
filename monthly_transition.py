#!/usr/bin/env python3
"""Monthly Aug->Sep-style subscription transition (curr<-next, next decremented).
Run at month-end via launchd. Backs up full student state, applies transition,
writes changes back to Airtable via airtable-proxy.php, logs a summary.
"""
import json
import re
import subprocess
import sys
from datetime import datetime

PROXY = "https://nicolaecatrina.com/app/airtable-proxy.php"
BACKUP_DIR = "/Users/lucianvirtic/Documents/claude/nc/backups"


def status_letter(v):
    return re.sub(r'\d+$', '', v or '') or ''


def status_num(v):
    m = re.search(r'\d+$', v or '')
    return int(m.group(0)) if m else None


def decrement(status):
    """Given the NEW curr, return (new_next, is_last_month)."""
    letter = status_letter(status)
    num = status_num(status)
    if letter == 'S':
        return 'S', False
    if letter == 'F' and num is None:
        return 'F', False
    if num is not None:
        if num <= 1:
            return '', True
        return letter + str(num - 1), False
    return '', False


def fetch_students():
    r = subprocess.run(
        ["curl", "-s", f"{PROXY}?action=students"],
        capture_output=True, text=True, timeout=60
    )
    return json.loads(r.stdout)


def post_update(rec_id, subs_json, note):
    body = json.dumps({"subscriptions": subs_json, "note": note}, ensure_ascii=False)
    r = subprocess.run(
        ["curl", "-s", "-o", "/dev/null", "-w", "%{http_code}",
         "-X", "POST", f"{PROXY}?action=update&id={rec_id}",
         "-H", "Content-Type: application/json",
         "--data-binary", body],
        capture_output=True, text=True, timeout=30
    )
    return r.stdout.strip()


def main():
    now = datetime.now()
    stamp = now.strftime("%Y-%m-%d_%H%M%S")

    data = fetch_students()
    recs = data.get("records", [])
    if not recs:
        print(f"[{stamp}] ERROR: no records fetched, aborting.")
        sys.exit(1)

    backup_path = f"{BACKUP_DIR}/students_backup_{stamp}_pre-transition.json"
    with open(backup_path, "w") as f:
        json.dump(data, f, ensure_ascii=False)
    print(f"[{stamp}] Backup saved: {backup_path} ({len(recs)} students)")

    updates = []
    for r in recs:
        f = r["fields"]
        try:
            subs = json.loads(f.get("subscriptions", "") or "{}")
        except Exception:
            subs = {}
        note = f.get("note", "") or ""
        clear_note = False
        dirty = False

        for cid, s in subs.items():
            new_curr = s.get("next", "") or ""
            new_next, is_last = decrement(new_curr)
            if s.get("curr", "") != new_curr or s.get("next", "") != new_next:
                dirty = True
            s["curr"] = new_curr
            s["next"] = new_next
            if is_last:
                clear_note = True

        if clear_note and note != "":
            dirty = True
        if clear_note:
            note = ""

        if dirty:
            updates.append((r["id"], f.get("name", ""), json.dumps(subs, ensure_ascii=False), note))

    print(f"[{stamp}] Students with changes: {len(updates)}/{len(recs)}")

    ok = 0
    fail = []
    for rec_id, name, subs_json, note in updates:
        code = post_update(rec_id, subs_json, note)
        if code.startswith("2"):
            ok += 1
        else:
            fail.append((rec_id, name, code))

    print(f"[{stamp}] Applied: {ok}/{len(updates)} OK")
    if fail:
        print(f"[{stamp}] FAILURES:")
        for rec_id, name, code in fail:
            print(f"  {rec_id} {name} -> HTTP {code}")

    # Post-transition summary
    post_data = fetch_students()
    summary = {}
    for r in post_data.get("records", []):
        f = r["fields"]
        try:
            subs = json.loads(f.get("subscriptions", "") or "{}")
        except Exception:
            subs = {}
        for cid, s in subs.items():
            g = s.get("G", "") or "?"
            letter = status_letter(s.get("curr", "") or "")
            if letter in ("P", "F", "G"):
                key = (cid, g)
                summary[key] = summary.get(key, 0) + 1

    print(f"[{stamp}] Payment counts by course/group:")
    for (cid, g), cnt in sorted(summary.items()):
        print(f"  {cid} G{g}: {cnt}")

    log_path = f"{BACKUP_DIR}/transition_log_{stamp}.txt"
    with open(log_path, "w") as f:
        f.write(f"Transition run {stamp}\n")
        f.write(f"Students: {len(recs)}, changed: {len(updates)}, applied OK: {ok}/{len(updates)}\n")
        if fail:
            f.write("FAILURES:\n")
            for rec_id, name, code in fail:
                f.write(f"  {rec_id} {name} -> HTTP {code}\n")
        f.write("Payment counts by course/group:\n")
        for (cid, g), cnt in sorted(summary.items()):
            f.write(f"  {cid} G{g}: {cnt}\n")
    print(f"[{stamp}] Log written: {log_path}")


if __name__ == "__main__":
    main()
