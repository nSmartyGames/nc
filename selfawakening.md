# selfawakening.net — Malware Incident & 500 Error

**Investigated**: 2026-05-21 (updated)
**Status**: Site compromised. HTTP 500. index.php is malware.

---

## Current Site Status
- HTTP 500 — malware index.php causes PHP fatal errors
- index.php: 31252 bytes, infected May 5 2025 (clean WP should be 405 bytes)
- wp-confih.php: 35375 bytes, backdoor installed May 4 2025

---

## index.php Malware — Full Decode

**Obfuscation technique**: Unicode Variation Selector encoding  
- Chars 0–15 encoded as U+FE00–U+FE0F  
- Chars 16–255 encoded as U+E0100–U+E01EF  
- Prefix `$link = 'y` then encoded payload (~28KB of invisible unicode)  
- Decoder uses `preg_split('//u')` + byte arithmetic to extract ASCII  
- Temp file written to `/tmp/temp_*.temp` then `include`d via PHP shutdown  

**Payload behavior** (SEO cloaking):
1. Detects search engine bots: `googlebot|bing|yahoo|google` in User-Agent
2. Contacts one of 4 C2 servers (ROT13 + URL-percent-encoded):
   - `2608-top102.expansor.xyz`
   - `2608-top102.ordinep.xyz`
   - `2608-top102.clarivum.top`
   - `2608-top102.vestigial.top`
3. Sends visitor data: host, URI, referer, language, server type
4. Returns cloaked content to bots (SEO spam), normal WP to humans
5. Modifies `robots.txt` to point to malware-generated `sitemap23.xml`
6. Campaign ID: `2608-top102`

**Malware-generated files to delete**:
- `selfawakening.net/sitemap23.xml` (203KB spam sitemap, created by malware)
- `selfawakening.net/robots.txt` needs reset to clean version

---

## wp-confih.php Backdoor — Full Analysis

**Auth**: SHA256 key gate — `?k=<key>` or `POST k=<key>`  
**Hash**: `8e0901e9a1279ace81230f549311de6490e5997604091f9ba48ca6722e18bebb`  
**Bot cloaking**: Returns fake WP page to scanners without key  

**API endpoints** (`?api&action=`):
- `scan_and_create`: Recursively scans entire server for WP installations, auto-creates admin users in ALL found sites — crosses site boundaries (hits nicolaecatrina.com too)
- Creates admin accounts via WP-CLI
- Returns login URL, username, password for each compromised site

**Scope**: This backdoor can compromise EVERY WordPress site on the shared cPanel account.

---

## All Malware Files

| File | Status | Threat |
|------|--------|--------|
| `index.php` | Active infection | SEO cloaking, C2 phone-home, temp file exec |
| `wp-confih.php` | Active backdoor | Cross-site WP admin creation |
| `wp-content/mu-plugins/site-compat-layer.php` | Active | Creates admin `bennett` / `HNvo9m25okwZ` |
| `wp-content/mu-plugins/solid-tools-lite.php` | chmod 444 | Obfuscated malware, FTP-undeletable |
| `sitemap23.xml` | Malware artifact | Spam SEO sitemap |
| `robots.txt` | Modified by malware | Points to spam sitemap |

Infection started ~May 4, 2026. Error log: 376KB (growing).

---

## Fix Checklist

**Must use cPanel File Manager** (FTP cannot delete 444 files):

- [ ] Put site in maintenance mode (rename index.php to index.php.bak via cPanel)
- [ ] **Change all passwords**: cPanel, WordPress admin, FTP, MySQL DB
- [ ] Delete via cPanel:
  - `selfawakening.net/index.php` → replace with clean: `<?php require __DIR__ . '/wp-blog-header.php';`
  - `selfawakening.net/wp-confih.php` → delete
  - `selfawakening.net/wp-content/mu-plugins/site-compat-layer.php` → delete
  - `selfawakening.net/wp-content/mu-plugins/solid-tools-lite.php` → chmod 644, then delete
  - `selfawakening.net/sitemap23.xml` → delete
  - `selfawakening.net/robots.txt` → reset to clean version
- [ ] Check WP admin users — delete `bennett` and any other unknown admins
- [ ] Check nicolaecatrina.com WP admin users too (backdoor scanned whole server)
- [ ] Run Imunify360 or Wordfence full scan on both sites
- [ ] Fix theme bug: update/reinstall `aasana` theme OR patch `content-blog.php:67`
  - Bug: `Aasana_Funcs_default::cws_get_date_parts()` doesn't exist → 500 on blog/archive pages
- [ ] Block C2 domains in firewall/hosts (optional but recommended):
  - `2608-top102.expansor.xyz`, `2608-top102.ordinep.xyz`
  - `2608-top102.clarivum.top`, `2608-top102.vestigial.top`

---

## nicolaecatrina.com Status
- public_html/index.php: 405 bytes, Feb 6 2020 — appears clean (standard WP index.php)
- No wp-confih.php found in public_html
- mu-plugins: not a WP site, no mu-plugins directory
- Still verify WP admin users — backdoor may have created accounts before being stopped
