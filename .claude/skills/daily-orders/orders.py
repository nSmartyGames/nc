#!/usr/bin/env python3
"""Daily orders helper for the Yoga Subscriber system.

Talks to the production proxy (airtable-proxy.php). Three subcommands:

  scan   [days]                 Read-only scan of unread WooCommerce orders + Airtable dup-check.
                                Prints a table and the raw JSON. NEVER marks emails read.
  insert <rows.json | ->        Bulk-apply next-month payments (sets next='P') via upsert_sub.
                                rows = [{"name","email","course","group"}]. '-' reads stdin.
  mark   <names.json | -> [-f]  Mark order emails + matching NETOPIA confirmations read.
                                names = ["Buyer Name", ...]. -f also marks failed orders.

Course ids: YTT-M1 YTT-M2 YTT-M3 "I Ching" AL.  KS26 / seminars are NOT monthly subs — handle by hand.
"""
import sys, json, urllib.request

PROXY = "https://nicolaecatrina.com/app/airtable-proxy.php"

def call(action, payload, method="POST"):
    data = json.dumps(payload).encode() if payload is not None else None
    req = urllib.request.Request(PROXY + "?action=" + action, data=data,
                                 headers={"Content-Type": "application/json"})
    return json.loads(urllib.request.urlopen(req, timeout=60).read())

def _load(arg):
    return json.load(sys.stdin) if arg == "-" else json.load(open(arg))

def scan(days=2):
    r = call("orders_scan", {"days": int(days)})
    if r.get("error"):
        print("ERROR:", r["error"]); sys.exit(1)
    orders = r.get("orders", [])
    print(f"# {r['count']} unread order(s) since {r['since']}\n")
    hdr = f"{'#':>6} {'Date':16} {'Name':24} {'Email':32} {'Course/Grp':13} {'Sum':>8}  Check"
    print(hdr); print("-" * len(hdr))
    for o in orders:
        cg = o["course"] + (" G" + o["group"] if o["group"] else "")
        c = o.get("check", {})
        if not c.get("student_exists"): flag = "NEW student"
        elif not c.get("has_course"):   flag = f"no {o['course']} sub (curr courses differ)"
        elif c.get("already_paid"):     flag = f"ALREADY PAID next={c.get('next')!r}"
        else:                           flag = f"ok curr={c.get('curr')!r} G={c.get('group')!r}"
        print(f"{o['onum']:>6} {o['date']:16} {o['name'][:24]:24} {o['email'][:32]:32} {cg:13} {o['total']:>8}  {flag}")
    print("\n--- JSON ---")
    print(json.dumps(orders, ensure_ascii=False, indent=2))

def insert(arg):
    rows = _load(arg)
    for row in rows:
        try:
            r = call("upsert_sub", {"name": row.get("name", ""), "email": row["email"],
                                    "course": row["course"], "group": str(row.get("group", "")),
                                    "status": row.get("status", "P")})
            print(f"{r.get('action','?'):8} {row['email']:34} {row['course']:8} "
                  f"G{row.get('group') or '-'}  {r.get('name','')}")
        except Exception as e:
            print(f"ERROR    {row.get('email','?')}  {row.get('course','?')}  -> {e}")

def mark(arg, failed=False):
    names = _load(arg)
    r = call("orders_mark", {"names": names, "mark_failed": failed})
    print(json.dumps(r, ensure_ascii=False))

if __name__ == "__main__":
    a = sys.argv[1:]
    if not a:
        print(__doc__); sys.exit(0)
    cmd = a[0]
    if cmd == "scan":     scan(a[1] if len(a) > 1 else 2)
    elif cmd == "insert": insert(a[1])
    elif cmd == "mark":   mark(a[1], failed=("-f" in a[2:] or "--failed" in a[2:]))
    else:
        print("unknown command:", cmd); print(__doc__); sys.exit(1)
