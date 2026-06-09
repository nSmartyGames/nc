# Yoga Subscriber Management System

## Project Overview
A web-based subscriber management system for yoga and related courses, hosted on nicolaecatrina.com WordPress server, with Airtable as the backend database.

## Architecture
- **Frontend**: Static HTML files hosted on nicolaecatrina.com
- **Backend**: PHP proxy (airtable-proxy.php) on same server, relays requests to Airtable API
- **Database**: Airtable base "NC" (appSIg5wDCS1LQ52p)

## Local Project Files
All files live in the project root:
- `yoga.html` — Admin subscriber table
- `student.html` — Student portal with login
- `airtable-proxy.php` — PHP proxy to Airtable
- `update.sh` — FTP deploy script (port 21, curl): `bash update.sh <filename>`
- `watch-deploy.sh` — Auto-deploy watcher (monitors yoga.html, student.html, airtable-proxy.php)
- `disable.sh` — Maintenance mode ON: FTP uploads maintenance.html over all app files + disabled proxy
- `restore.sh` — Maintenance mode OFF: re-deploys real student.html + airtable-proxy.php
- `maintenance.html` — Self-contained maintenance page (no external resources); deployed by disable.sh
- `_disabled-proxy.php` — Returns 503 for all requests; deployed as airtable-proxy.php by disable.sh
- `.deploy.prod.env` — SSH credentials (never commit)
- `NicolaeCatrina.code-workspace` — VS Code workspace file

## Deploy
```bash
bash update.sh yoga.html
bash update.sh airtable-proxy.php
bash update.sh student.html
```
Always deploy `yoga.html` after any modification to it.
Uploads via FTP (port 21, curl) to `public_html/app/` on nicolaecatrina.com and auto-refreshes matching Chrome tabs (Mac only). SSH port 65222 is blocked — use FTP only.

## Server Path
Files live at: `nicolaecatrina.com/public_html/app/`
- Admin page URL: `https://nicolaecatrina.com/app/yoga.html`
- Student portal URL: `https://nicolaecatrina.com/app/student.html`
- Proxy URL: `https://nicolaecatrina.com/app/airtable-proxy.php`

## Airtable Structure

### Base: NC (appSIg5wDCS1LQ52p)

### Table: Courses (tblnVo1XVBMhUmA4h)
| Field | ID | Type |
|-------|------|------|
| id (primary) | fldQ7kSY1KZ2bTska | singleLineText |
| label | fldqdzbMbgMf4fVU4 | singleLineText |
| groups | fld4OYOz8TD70oFjP | singleLineText |

Courses: YTT-M1 (groups: All,G6,G7,G8), YTT-M2 (groups: All,G4), YTT-M3 (groups: All,G1,G2), I Ching (groups: All), AL (groups: All,G4)

### Table: Students (tblrZi26rAsAHDI4z)
| Field | ID | Type |
|-------|------|------|
| email (primary) | fldTKQVFs3vrMrwWA | email |
| name | fld4Lx0LL4sfYOyy7 | singleLineText |
| note | fld03gdtFjOIkn7wB | multilineText |
| subscriptions | fldUvZSA5jTlsLQFH | multilineText (JSON) |
| login_code | fldl4zBz2wxOtsBkR | singleLineText |
| sessions | fldzXA61F6oOzodW6 | multilineText |

#### subscriptions field format (JSON string):
```json
{"YTT-M1": {"next": "P", "curr": "F", "G": "6"}}
```
Values: "" (empty/unpaid), "P" (paid), "F" (full/prepaid), "G" (free/gratis), "S" (stop)
`G` key inside each course entry = group number string, e.g. "6" for G6.

Numeric suffix on status = prepaid session count, e.g. `P3` = paid for 3 months.

#### Month transition logic (doTransition in yoga.html):
For each student, each course: `curr ← next`, then new `next` determined by old `curr`:
- `S` → `next = 'S'` (stay stopped)
- `F` with no counter → `next = 'F'` (forever subscription, never expires)
- counter > 1 → `next = letter + (counter - 1)` (decrement)
- counter = 1 → `next = ''`, clear student note (last prepaid used)
- anything else → `next = ''`

#### sessions field:
Comma-separated session IDs, e.g. "YTT-M1-G6,YTT-M1-G7"
Acts as foreign key to Sessions table id field.

### Table: Sessions (tblCkiOttKop525yY)
| Field | ID | Type |
|-------|------|------|
| id (primary) | fldfJXk0duawFD6mR | singleLineText |
| session | fldgtWrqar4zhAZYJ | singleLineText |
| link | fldp8IWdYZVQFQlWN | url |

Session id format: "YTT-M1-G6", "YTT-M2-G4", etc. Format: courseId-groupId.

### Old tables (can be deleted):
- Courses_old (tblJuOWIKnuAfQqGy)
- Students_old (tbluzsgo3RhqSpAbR)

## Proxy Endpoints (airtable-proxy.php)
- `GET ?action=courses` — list all courses
- `GET ?action=students` — list all students (all fields, paginated)
- `POST ?action=update&id=recXXX` — update student record (body: field values)
- `POST ?action=create` — create new student (body: field values)
- `GET ?action=sessions` — list all sessions
- `POST ?action=login` — login with 5-digit code (body: {"code":"12345"})
- `POST ?action=sendcode` — send login code to email (body: {"email":"..."})
- `GET ?action=vimeo&course=YTT-M1&from=50&to=53` — fetch Vimeo video links from folder matching course name
- `POST ?action=vimeo_play` — set fresh password on Vimeo video, return embed code (body: {"url":"..."})
- `GET ?action=sessions_clear&id=YTT-M1-G6` — delete all sessions for a group
- `POST ?action=sessions_upsert` — upsert session records (body: {"sessions":[{id,session,link}]})

## Admin Table (yoga.html) Features
- Dark theme, gold text, system-ui font
- Course badges header: one row per course, each with All + group buttons (G6, G7, etc.)
- Active course/group highlighted; clicking badge filters table
- 4 columns: Name (with search input + count), Email (with search input + Copy button), Next Month, Current Month
- Name column search and Email column search inputs built into the header row
- Copy button: copies semicolons-separated emails of paid (P/F/G) students for current course/group to clipboard (ready for BCC)
- Month columns show current and next month names, sortable
- Subscription cell dropdown: Empty/P/F/G/S with color-coded badges
- Name click: shows note input (Enter to save), green name = has note, hover = tooltip
- Add subscriber form: open by default, fields for Name/Email/Group#
- Session links panel shown per group (below bar)
- Vimeo import bar: import class video links by range (e.g. 50-53)

## Student Portal (student.html) Features
- Login with 5-digit code (individual digit inputs with auto-advance)
- "Don't have a code?" link sends code to student email
- Dashboard shows enrolled courses as collapsible cards
- Each card: course badge, label, current status, month info
- Expanded card shows session links filtered by student's sessions field
- Session stays in sessionStorage

## Security (airtable-proxy.php)
- CORS restricted to `https://nicolaecatrina.com` only (not `*`)
- Requests with foreign `HTTP_ORIGIN` get 403; empty origin (same-server/curl) allowed
- `sendemail` rate-limited: max 10 requests/hour per IP (file-based, stored in sys_get_temp_dir())

## Important Notes
- Airtable REST API returns field NAMES as keys in `fields`, not field IDs
- Use `fv(f, fieldId, fieldName)` helper in JS to check both formats safely
- PHP 7.4.33 on server, Apache
- NEVER hardcode or expose the Airtable token in client-side code
- AT_TOKEN is hardcoded only in airtable-proxy.php on the server side
- Group filter uses `subscriptions[courseId].G` field (string, e.g. "6" for G6)
- `activeCourse` default must match a real course ID (e.g. "YTT-M1"), not "YM1"

## Setup on a New Machine
1. Copy all project files to a local folder
2. Open `NicolaeCatrina.code-workspace` in VS Code
3. Ensure `curl` installed
4. `.deploy.prod.env` must be present with FTP credentials (FTP_HOST, FTP_USER, FTP_PASS)
5. `bash update.sh student.html` (or `airtable-proxy.php`) to deploy — do NOT deploy `yoga.html`
6. `bash watch-deploy.sh` to auto-deploy on file save
7. Note: Chrome auto-refresh in update.sh requires macOS (osascript) — skipped silently on other OS
