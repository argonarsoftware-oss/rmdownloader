# chrome-nav — Chrome navigation monitor + content regulation

A self-contained Python tool (`chrome_nav_monitor.py` → `chnav.exe`) that watches Chrome's
navigation in **real time** via the Chrome DevTools Protocol (CDP) **and** enforces per-domain
**site rules** (block / warn / replace) across every tab. Managed from the dashboard by
`website/cdp.php`, but **standalone** — it shares no code with the `rmdownloader` agent
(`Agent.cs`/`agentsvc.exe`), isn't part of that build, and never touches the command queue.

## What it does
Detects the OS + finds Chrome. **Verifies the debug port first:** if Chrome is already debugging
on `--port` it attaches; otherwise it kills the running Chrome and relaunches with
`--remote-debugging-port` + a dedicated `--user-data-dir` (you can't add the port to a live Chrome).
Then it attaches at the **browser** level and auto-attaches to **every tab/window**, printing each
timestamped event:
- `NAV` — `Page.frameNavigated` (main frame)
- `SPA` — `Page.navigatedWithinDocument` (history.pushState etc.)
- `DOC` / `req` — `Network.requestWillBeSent` (only with `--requests`)
- `BLOCK` / `WARN` / `REPLACE` — a site rule fired

Clean Ctrl+C shutdown.

## Regulation — `--block <file>`
Pass a rules file (the dashboard saves it as `blt.txt` next to the exe). One rule per line,
**hot-reloaded** on edit (~2s), read `utf-8-sig` so a BOM can't break the first rule:
```
<domain>            # bare domain = block; also matches subdomains
*.<domain>          # wildcard
<domain> block
<domain> warn  <message...>
<domain> replace <url>
```
Example:
```
facebook.com   replace https://www.youtube.com/
*.tiktok.com   block
news.example   warn  Back to work please
```
- **block / warn** are enforced on `Page.frameNavigated` by navigating the tab to a `data:` warning
  page — a real navigation that **stops the in-flight load/redirect** (so a 302 can't win).
- **replace** keeps the original URL: the tab is re-navigated so the now-active `Fetch` domain catches
  the reload and serves the replacement response. *(Live script-driven sites only half-render when
  replaced — cross-origin/CSP; a static page is faithful.)*
- `--block-page <file>` overrides the warning HTML (`{{ICON}}/{{TITLE}}/{{DOMAIN}}/{{MESSAGE}}` tokens).

## Gotchas (load-bearing)
- Chrome 111+ rejects the DevTools websocket with **HTTP 403** unless `--remote-allow-origins` is
  passed; the launcher sets `--remote-allow-origins=*` (we connect from 127.0.0.1). Verified to Chrome 149.
- Launches with `--disk-cache-size=1` so a previously-cached page still makes the network request that
  interception needs (else a cached ruled site could bypass the rules).
- The main-frame **document** request of a freshly-opened tab races Fetch setup and can't be paused
  reliably — hence the `frameNavigated` enforcement above rather than relying on Fetch for the document.

## Deps
`requests` + `websocket-client` only (no Selenium):
```
pip install requests websocket-client
```

## Run
```
py chrome_nav_monitor.py [--requests]                 # monitor only
py chrome_nav_monitor.py --block blt.txt              # monitor + regulate
```
CLI: `--port` (9222), `--user-data-dir` (`<tmp>/chrome-cdp-monitor`), `--requests`, `--block FILE`,
`--block-page FILE`, `--all-tabs` (browser-level even without rules), `--no-launch`, `--force-restart`.
With `--block` (or `--all-tabs`) it runs browser-level (all tabs); without, it's a single-tab passive monitor.

## Build a single-file exe (like dnl.exe)
```
build.bat          # pip install pyinstaller first
```
Produces `dist\chnav.exe` (runs with no Python installed). The `.py` is committed; the exe and
PyInstaller `dist/`,`build/` output are git-ignored — build locally, then deploy to `CDP_DIR` on the PC.

## Scope
This seizes control of Chrome on the target PC and runs at the agent's privilege (SYSTEM if elevated).
Use it to regulate machines you own/administer. Serving fake content under a real URL to deceive other
people is phishing/spoofing — don't.
