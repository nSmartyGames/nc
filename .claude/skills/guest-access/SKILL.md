---
name: guest-access
description: Manage guest-preview links (?guest=<slug> on student.html) for workshops/modules — a local database (guest-access.json) tracking every slug, its module, status, and full history, verified against before any new guest link is created or an existing one is enabled/disabled. Use when the user asks to "disable/revoke guest access", "give guest access to a workshop", "create a preview link", or mentions a `?guest=` URL.
---

# Guest Access

Guest-preview links let someone open `student.html?guest=<slug>` and see a lightweight,
no-login preview of a single workshop/module (title + enroll link) instead of the normal
student dashboard. There is no per-guest tracking beyond the slug itself — it's a shared,
revocable link, not an account.

## Source of truth

`guest-access.json` (repo root, deployed to `public_html/app/guest-access.json`) holds:

```json
{
  "modules": [ {"id": "AL", "label": "...", "source": "airtable:Courses"} ],
  "workshops": [ {"slug": "taoist-alchemy", "label": "Taoist Alchemy", "moduleId": "AL",
                   "status": "active|disabled", "createdAt": "...", "updatedAt": "..."} ],
  "history": [ {"ts": "...", "action": "created|enabled|disabled", "slug": "...", "note": "..."} ]
}
```

`modules` mirrors the Airtable `Courses` table (`?action=courses` on `airtable-proxy.php` — see
`claude.md` for the current list: YTT-M1, YTT-M2, YTT-M3, I Ching, AL). Keep it in sync manually;
there's no live join to Airtable from the static JSON file.

`student.html` fetches this file client-side (`fetch(BASE + 'guest-access.json')`) whenever the
URL has a `?guest=` param, looks up the slug, and renders:
- **not found** → "invalid link" message
- **status `disabled`** → "link no longer available" message (this is how a slug is revoked)
- **status `active`** → workshop title + an enroll/learn-more link to the module's pay URL

Normal login (`sessionStorage`-based) is untouched — guest handling only kicks in when `?guest=`
is present in the URL, and returns early before the login check.

## Always verify before creating

Before adding a new guest slug, check it isn't already registered — this is the whole point of
keeping the database instead of just editing the HTML ad hoc:

```bash
python3 .claude/skills/guest-access/guest_access.py verify <slug>
```

If it says `EXISTS`, don't create a duplicate — use `enable`/`disable` on the existing one instead.

## Commands

Helper script: `python3 .claude/skills/guest-access/guest_access.py <cmd>` (edits
`guest-access.json` directly, no server calls).

| Command | Effect |
|---|---|
| `list-modules` | List known modules/courses |
| `list-workshops [--status active\|disabled]` | List registered guest slugs |
| `verify <slug>` | Check whether a slug is already taken before creating a new one |
| `add-module <id> <label>` | Register a module not yet in the list (skips if it exists) |
| `add-workshop <slug> <module-id> <label> [--note N]` | Create a new guest slug, status `active` (refuses if the slug already exists) |
| `enable <slug> [--note N]` | Restore access for a disabled slug |
| `disable <slug> [--note N]` | Revoke access for a slug — this is what "disable guest access" means |
| `history [<slug>]` | Print the audit log (optionally filtered to one slug) |

Every create/enable/disable call appends a timestamped entry to `history` — nothing is ever
deleted from the log, so past grants/revocations stay visible.

## Deploying a change

Editing `guest-access.json` locally doesn't affect the live site until deployed:

```bash
bash update.sh guest-access.json
```

If `student.html` itself changed (the guest-rendering logic, not just the data), also run:

```bash
bash update.sh student.html
```

Both require `.deploy.prod.env` (FTP credentials) to be present locally — see `claude.md`.

## Example: disabling a workshop's guest access

```bash
python3 .claude/skills/guest-access/guest_access.py disable taoist-alchemy --note "revoked per request"
bash update.sh guest-access.json
```

## Example: granting a new guest preview

```bash
python3 .claude/skills/guest-access/guest_access.py verify autumn-retreat-preview
# NOT FOUND -> safe to create
python3 .claude/skills/guest-access/guest_access.py add-workshop autumn-retreat-preview YTT-M1 "Autumn Retreat Preview"
bash update.sh guest-access.json
```
