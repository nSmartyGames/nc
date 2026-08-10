#!/usr/bin/env python3
"""Manage guest-access.json: the workshops/modules registry, guest preview
slugs (?guest=<slug> on student.html), and their history.

Usage:
  python3 guest_access.py list-modules
  python3 guest_access.py list-workshops [--status active|disabled]
  python3 guest_access.py verify <slug>
  python3 guest_access.py add-module <id> <label>
  python3 guest_access.py add-workshop <slug> <module-id> <label> [--note NOTE]
  python3 guest_access.py enable <slug> [--note NOTE]
  python3 guest_access.py disable <slug> [--note NOTE]
  python3 guest_access.py history [<slug>]
"""
import json
import sys
import argparse
import datetime
import pathlib

DB_PATH = pathlib.Path(__file__).resolve().parents[3] / "guest-access.json"


def load_db():
    if not DB_PATH.exists():
        return {"modules": [], "workshops": [], "history": []}
    with open(DB_PATH, "r", encoding="utf-8") as f:
        return json.load(f)


def save_db(db):
    with open(DB_PATH, "w", encoding="utf-8") as f:
        json.dump(db, f, indent=2, ensure_ascii=False)
        f.write("\n")


def now_iso():
    return datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def find_workshop(db, slug):
    for w in db["workshops"]:
        if w["slug"] == slug:
            return w
    return None


def find_module(db, module_id):
    for m in db["modules"]:
        if m["id"] == module_id:
            return m
    return None


def log_history(db, action, slug, note):
    db["history"].append({
        "ts": now_iso(),
        "action": action,
        "slug": slug,
        "note": note or "",
    })


def cmd_list_modules(args, db):
    for m in db["modules"]:
        print("%-10s %s" % (m["id"], m["label"]))


def cmd_list_workshops(args, db):
    for w in db["workshops"]:
        if args.status and w["status"] != args.status:
            continue
        print("%-20s %-10s %-9s %s" % (w["slug"], w["moduleId"], w["status"], w["label"]))


def cmd_verify(args, db):
    """Check a slug against the database before creating a new guest link."""
    w = find_workshop(db, args.slug)
    if w is None:
        print("NOT FOUND — slug '%s' is free to use." % args.slug)
        return
    print("EXISTS — slug '%s' already registered: status=%s module=%s label=%r" %
          (w["slug"], w["status"], w["moduleId"], w["label"]))
    print("Refusing to silently duplicate. Use 'enable'/'disable' to change its status instead.")


def cmd_add_module(args, db):
    if find_module(db, args.id):
        print("Module '%s' already exists — not adding a duplicate." % args.id)
        return
    db["modules"].append({"id": args.id, "label": args.label, "source": "manual"})
    save_db(db)
    print("Added module '%s'." % args.id)


def cmd_add_workshop(args, db):
    existing = find_workshop(db, args.slug)
    if existing:
        print("Slug '%s' already exists (status=%s). Refusing to create a duplicate." %
              (args.slug, existing["status"]))
        print("Use 'enable %s' or 'disable %s' instead." % (args.slug, args.slug))
        sys.exit(1)
    if not find_module(db, args.module_id):
        print("Warning: module '%s' is not in the registry yet (add it with add-module first "
              "if it should track a real Airtable course)." % args.module_id)
    ts = now_iso()
    db["workshops"].append({
        "slug": args.slug,
        "label": args.label,
        "moduleId": args.module_id,
        "status": "active",
        "createdAt": ts,
        "updatedAt": ts,
    })
    log_history(db, "created", args.slug, args.note)
    save_db(db)
    print("Added workshop '%s' (module=%s, status=active)." % (args.slug, args.module_id))


def cmd_set_status(args, status):
    db = load_db()
    w = find_workshop(db, args.slug)
    if not w:
        print("Slug '%s' not found in guest-access.json." % args.slug)
        sys.exit(1)
    if w["status"] == status:
        print("Slug '%s' is already %s." % (args.slug, status))
        return
    w["status"] = status
    w["updatedAt"] = now_iso()
    log_history(db, status, args.slug, args.note)
    save_db(db)
    print("Slug '%s' set to %s." % (args.slug, status))
    print("Remember to deploy: bash update.sh guest-access.json")


def cmd_history(args, db):
    rows = db["history"]
    if args.slug:
        rows = [r for r in rows if r["slug"] == args.slug]
    for r in rows:
        print("%s  %-10s %-20s %s" % (r["ts"], r["action"], r["slug"], r.get("note", "")))


def main():
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = p.add_subparsers(dest="cmd", required=True)

    sub.add_parser("list-modules")

    sp = sub.add_parser("list-workshops")
    sp.add_argument("--status", choices=["active", "disabled"])

    sp = sub.add_parser("verify")
    sp.add_argument("slug")

    sp = sub.add_parser("add-module")
    sp.add_argument("id")
    sp.add_argument("label")

    sp = sub.add_parser("add-workshop")
    sp.add_argument("slug")
    sp.add_argument("module_id")
    sp.add_argument("label")
    sp.add_argument("--note", default="")

    sp = sub.add_parser("enable")
    sp.add_argument("slug")
    sp.add_argument("--note", default="")

    sp = sub.add_parser("disable")
    sp.add_argument("slug")
    sp.add_argument("--note", default="")

    sp = sub.add_parser("history")
    sp.add_argument("slug", nargs="?")

    args = p.parse_args()

    if args.cmd == "enable":
        cmd_set_status(args, "active")
        return
    if args.cmd == "disable":
        cmd_set_status(args, "disabled")
        return

    db = load_db()
    {
        "list-modules": cmd_list_modules,
        "list-workshops": cmd_list_workshops,
        "verify": cmd_verify,
        "add-module": cmd_add_module,
        "add-workshop": cmd_add_workshop,
        "history": cmd_history,
    }[args.cmd](args, db)


if __name__ == "__main__":
    main()
