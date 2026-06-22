# chrome-nav — Chrome navigation monitor

A self-contained Python utility that prints Chrome's navigation in **real time** via the
Chrome DevTools Protocol (CDP). **Strictly standalone** — it shares no code with the
`rmdownloader` agent (`Agent.cs`/`agentsvc.exe`), isn't part of that build, and never touches
the command queue / `website/data`. It's kept here as its own subproject, like `dns/`.

## What it does
Detects the OS + finds Chrome, kills any running Chrome and waits for full exit, relaunches it
with `--remote-debugging-port` + a dedicated `--user-data-dir`, polls
`http://127.0.0.1:<port>/json/version` until the port is up, then attaches to the page websocket
and enables the `Page` + `Network` domains. Prints, each timestamped:
- `NAV` — `Page.frameNavigated` (main frame)
- `SPA` — `Page.navigatedWithinDocument` (history.pushState etc.)
- `DOC` / `req` — `Network.requestWillBeSent` (only with `--requests`)

Clean Ctrl+C shutdown.

## Gotcha (load-bearing)
Chrome 111+ rejects the DevTools websocket with **HTTP 403** unless `--remote-allow-origins` is
passed; the launcher sets `--remote-allow-origins=*` (we connect from 127.0.0.1). Without it the
port opens fine but the websocket handshake fails — verified up to Chrome 149.

**Already-in-debug-mode detection:** it probes `/json/version` first; if Chrome is already
debugging on the port it attaches instead of restarting. `--force-restart` overrides;
`--no-launch` attaches only and errors if nothing is listening.

## Deps
`requests` + `websocket-client` only (no Selenium):
```
pip install requests websocket-client
```

## Run
```
py chrome_nav_monitor.py [--requests]
```
CLI: `--port` (9222), `--user-data-dir` (`<tmp>/chrome-cdp-monitor`), `--requests`,
`--no-launch`, `--force-restart`.

## Build a single-file exe (like dnl.exe)
```
build.bat          # pip install pyinstaller first
```
Produces `dist\chrome_nav_monitor.exe` (runs with no Python installed). The `.py` is committed;
the exe and PyInstaller `dist/`,`build/` output are git-ignored — build locally.

## TODO / continuation (multi-tab auto-follow)
Today it attaches to a single page target (the tab picked from `/json`), so new tabs/windows
aren't tracked. To follow the whole browser: connect to the **browser** websocket
(`/json/version` → `webSocketDebuggerUrl`) instead of one page, send
`Target.setDiscoverTargets {discover:true}`, and on each `Target.targetCreated` for a `page` use
`Target.attachToTarget {flat:true}` to get a `sessionId`. Then enable `Page`/`Network` *per
session* and route events by their `sessionId`. Handle `Target.targetDestroyed` to drop closed
tabs. Keep the single-tab path as default; gate the new behavior behind `--all-tabs`.
