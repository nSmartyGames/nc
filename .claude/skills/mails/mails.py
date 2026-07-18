#!/usr/bin/env python3
"""General inbox triage helper for the Yoga Subscriber system (contact@nicolaecatrina.com).

Talks to the production proxy (airtable-proxy.php action=email_scan), which already:
  - scans UNSEEN + FLAGGED mail (union)
  - auto-answers + auto-writes two specific categories (sends the confirmation email itself):
      * Rudra-Șiva initiation signup with a detected location  -> ks26.csv (rudra column)
      * AMRITA SHAKTIPATA / "impulsionare" signup, if eligible  -> mm_initiation.csv (part=Bucuresti)
  - for "didn't receive course X" questions (cat q), attaches a payment-status lookup
  - everything else comes back as a draft (to/subject/draft) for a human to edit and send

This script never sends anything itself — it's read-only visibility into what email_scan did/found.
Use `send` only to fire a single hand-edited reply via send_admin.

Subcommands:
  scan [--mark-read]     Run the scan, print a summary table + full JSON.
  send <to> <subj> <msg> Send one reply via send_admin (also archives to INBOX.Sent).
"""
import sys, json, urllib.request

PROXY = "https://nicolaecatrina.com/app/airtable-proxy.php"

def call(action, payload):
    data = json.dumps(payload).encode()
    req = urllib.request.Request(PROXY + "?action=" + action, data=data,
                                  headers={"Content-Type": "application/json"})
    return json.loads(urllib.request.urlopen(req, timeout=60).read())

def scan(mark_read=False):
    r = call("email_scan", {"mark_read": mark_read})
    if r.get("error"):
        print("ERROR:", r["error"]); sys.exit(1)
    cats = r.get("cats", {})
    print(f"# {r.get('total', 0)} unread/flagged message(s) scanned\n")

    auto = cats.get("auto", [])
    if auto:
        print(f"## Auto-handled ({len(auto)}) — already sent + written, no action needed")
        for e in auto:
            a = e.get("auto", {})
            print(f"  [{a.get('type')}] {e['from_name']} <{e['from_email']}> "
                  f"orig_cat={a.get('orig_cat')} csv_action={a.get('csv_action')} "
                  f"email_sent={a.get('email_sent')} location={a.get('location', '')}")
        print()

    for cat in ["ks26", "monthly", "initiation", "q", "other"]:
        rows = cats.get(cat, [])
        if not rows:
            continue
        print(f"## {cat} ({len(rows)}) — needs a human-reviewed draft")
        for e in rows:
            p = e.get("parsed", {})
            extra = ""
            if cat == "q" and p.get("payment_check"):
                extra = f"  payment_check={p['payment_check']}"
            if cat == "initiation":
                extra = f"  sub={p.get('sub')}"
                if p.get("sub") == "rudra":
                    extra += f" location={p.get('rudra_location') or 'NOT DETECTED'}"
                elif p.get("sub") == "amrita":
                    extra += f" eligible={p.get('eligibility', {}).get('eligible')} reason={p.get('eligibility', {}).get('reason')}"
            print(f"  #{e['num']} {e['from_name']} <{e['from_email']}> — {e['subject'][:60]}{extra}")
        print()

    print("--- JSON ---")
    print(json.dumps(cats, ensure_ascii=False, indent=2))

def send(to, subj, msg):
    r = call("send_admin", {"to": to, "subject": subj, "message": msg})
    print(json.dumps(r, ensure_ascii=False))

if __name__ == "__main__":
    a = sys.argv[1:]
    if not a:
        print(__doc__); sys.exit(0)
    cmd = a[0]
    if cmd == "scan":
        scan(mark_read=("--mark-read" in a[1:]))
    elif cmd == "send":
        if len(a) < 4:
            print("usage: mails.py send <to> <subject> <message>"); sys.exit(1)
        send(a[1], a[2], a[3])
    else:
        print("unknown command:", cmd); print(__doc__); sys.exit(1)
