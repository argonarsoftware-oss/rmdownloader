# CLAUDE.md — rmdownloader

Remote file manager: a browser UI on a VPS that manages the drives of one or more Windows PCs.
The agent **reverse-connects out** to the site (outbound HTTPS long-poll), so no inbound port
or tunnel is needed — you just run the agent.

## Layout
- `agent/` — native Windows companion, an outbound HTTP polling client. Two exes:
  - `Agent.cs` → `Agent.exe` — the **worker**. Single-file C# (in-box .NET Framework 4 compiler;
    `System.Web.Extensions` JavaScriptSerializer for JSON). Polls `agent.php`, runs commands, posts
    results. Writes `worker.hb` each cycle (heartbeat for the supervisor). The `update` op stages
    `Agent.new.exe` + `update.flag` then exits for the supervisor to swap.
  - `Supervisor.cs` → `agentsvc.exe` — the **supervisor** (the boot task runs THIS). Keeps the worker
    alive, applies updates (swap → probation via `worker.hb` → auto-rollback to `Agent.prev.exe` if the
    new build doesn't check in), and keepalive-`ping`s the server during a swap so the PC stays online.
    Tiny/stable; rarely needs updating itself.
  - `build.bat` — compiles BOTH with `csc.exe` (`Agent.exe` needs `/r:System.Web.Extensions.dll`).
    Optionally bakes `server` + enroll key into `Embedded.cs` for a zero-config single exe:
    `build.bat <enroll-key> [server]`.
  - `agent.conf.sample` — per-machine config: `server`, `token`, `root`. Copied to `agent.conf`.
  - `install-startup.ps1` / `uninstall-startup.ps1` — Task Scheduler boot auto-start (SYSTEM).
  - `rollout-update.ps1` — canary-then-fleet remote worker update driver (calls `api.php?action=update`
    with the new `Agent.exe` base64; canary first, confirm reconnect, then the rest).
  - `update-guide.html` — standalone illustrated worker-update walkthrough (paste-safe one-line commands).
- `website/` — PHP app (XAMPP locally / Apache on the VPS).
  - `index.php`, `login.php`, `logout.php` — pages. `index.php` is the file manager (breadcrumb, drives,
    terminal + editor modals, upload).
  - `dns.php` + `assets/dns.js` — the **DNS Manager** page (see DNS subsystem below). Drives the agent
    through the `exec` op via `api.php`; query-log history is read from `dns-log.php` (MySQL) with a live
    file-tail fallback.
  - `cdp.php` + `assets/cdp.js` — the **CDP / Chrome Navigation** page (see CDP subsystem below). Same
    no-PHP-backend design as DNS: it manages the `chrome-nav` exes on a PC purely through the agent's
    `exec` op and tails their log output.
  - `dns-log.php` — reads DNS query history from MySQL (filter/page/clear); returns `db:false` when no DB.
  - `dns-text.php` — automation/Claude-Code view: `?key=<API_KEY>` (or login) returns a plain-text DNS
    report (service status, IPs, top sites, recent queries, blocklist+records); `&format=json` for JSON.
  - `dns-sync.php` + `dns-sync-core.php` — the **agent bridge**: ingest new `queries.log` lines into MySQL
    via a shared read (never stops `dnl.exe`). `cron/dns-sync.php` is the all-agents CLI for cron.
  - `dns-schema.sql` — MySQL schema (`dns_queries`, `dns_ingest_state`) + least-priv app user.
  - `agent.php` — agent-facing endpoint: `?action=poll` (long-poll, returns queued commands),
    `?action=result`, and `?action=ping` (keepalive: marks online without claiming commands, used by
    the supervisor during a swap). Auth by per-PC token OR shared `ENROLL_KEY` (`X-Agent-Token`); no session.
  - `api.php` — browser/automation-facing: enqueues a command for the selected PC and waits for
    the result. Auth via session login OR `API_KEY` (`?key=` / `X-Api-Key`). Actions: `agents`,
    `removeagent`, `info`, `list`, `read`, `mkdir`, `delete`, `rename`, `save`, `exec`, `update`,
    `upload`, `download`.
  - `lib.php` — session/auth (`api_authorized`), agent lookup, the file-based queue
    (`enqueue_command`, `claim_commands`, `store_result`, `fetch_result`, `is_online`), and the
    auto-enroll registry (`load_registry`, `register_agent`, `unregister_agent` → `data/agents.json`).
  - `config.sample.php` — copied to `config.php`; holds `ENROLL_KEY`, `rm_agents()`, `DATA_DIR`,
    `WEB_PASSWORD`, `SESSION_TIMEOUT`, `API_KEY`, `ALLOW_EXEC`, `DNS_DIR`, `DNS_TASK`, `WEBHOOK_SECRET`,
    and `DB_*` (MySQL for DNS query-log history).
  - `webhook-deploy.php` — GitHub push webhook for auto-deploy on the VPS (see Auto-deploy below).
  - `data/` — runtime command queue (`<agentId>/cmd|res/*.json`, `online`, `version`) + `agents.json`
    (auto-enroll registry) + `deploy.log` (webhook log). `.htaccess` denies web access.
  - `assets/` — `app.js` (file manager), `dns.js` (DNS manager), `style.css`.
- `deploy/apache-vhost.conf` — VPS vhost (`dos.argonar.co`).
- `vps-setup-guide.html` — standalone illustrated setup guide (local file).
- `dns/` — the **TinyDNS** server managed by the DNS page (see DNS subsystem below). Standalone Python,
  shipped/run on the DNS machine as `dnl.exe`; not part of the agent build.
- `chrome-nav/` — the **Chrome navigation monitor** subproject (`chrome_nav_monitor.py` + `build.bat` +
  `README.md`). Standalone CDP tool; not part of the agent build (see below).

## chrome-nav/ — Chrome navigation monitor (standalone, NOT part of the agent)
A self-contained Python utility in `chrome-nav/` that prints Chrome's navigation in real time via
the Chrome DevTools Protocol. **Strictly standalone** — it shares no code with `Agent.cs`/`agentsvc.exe`,
is not in the agent `build.bat`, and never touches the queue/`data/`. Don't merge it into `Agent.exe`.
- **What it does:** detects the OS + finds Chrome, kills any running Chrome and waits for full exit,
  relaunches with `--remote-debugging-port` + a dedicated `--user-data-dir`, polls
  `http://127.0.0.1:<port>/json/version` until the port is up, then attaches to the page websocket and
  enables the `Page` + `Network` domains. Prints `NAV` (`Page.frameNavigated`, main frame),
  `SPA` (`Page.navigatedWithinDocument`), and with `--requests` also `DOC`/`req`
  (`Network.requestWillBeSent`) — each timestamped. Clean Ctrl+C shutdown.
- **Gotcha (load-bearing):** Chrome 111+ rejects the DevTools websocket with **HTTP 403** unless
  `--remote-allow-origins` is passed; the launcher sets `--remote-allow-origins=*` (we connect from
  127.0.0.1). Without it the port opens fine but the websocket handshake fails — verified on Chrome 149.
- **Already-in-debug-mode detection:** probes `/json/version` first; if Chrome is already debugging on
  the port it attaches instead of restarting (avoids restart churn). `--force-restart` overrides;
  `--no-launch` attaches only and errors if nothing is listening.
- **Deps:** `requests` + `websocket-client` only (no Selenium). CLI: `--port` (9222),
  `--user-data-dir` (`<tmp>/chrome-cdp-monitor`), `--requests`, `--no-launch`, `--force-restart`.
- **Run:** `cd chrome-nav && py chrome_nav_monitor.py [--requests]`.
- **Single-file exe (like dnl.exe):** `cd chrome-nav && build.bat` (PyInstaller) →
  `chrome-nav/dist/chnav.exe` (runs with no Python installed). The `.py` is committed; the
  exe and PyInstaller `dist/`,`build/` output are git-ignored (`chrome-nav/.gitignore`) — build locally.
  (The monitor also does content regulation now — see the CDP subsystem section below.)
  Full details in `chrome-nav/README.md`.
- **TODO / continuation (multi-tab auto-follow):** today it attaches to a single page target (the tab
  picked from `/json`), so new tabs/windows aren't tracked. To follow the whole browser: connect to the
  **browser** websocket (`/json/version` → `webSocketDebuggerUrl`) instead of one page, send
  `Target.setDiscoverTargets {discover:true}`, and on each `Target.targetCreated` for a `page` use
  `Target.attachToTarget {flat:true}` to get a `sessionId`. Then enable `Page`/`Network` *per session*
  and route incoming events by their `sessionId` (CDP tags every event from an attached target with one).
  Handle `Target.targetDestroyed` to drop closed tabs. Keep the current single-tab path as the default
  and gate the new behavior behind a flag (e.g. `--all-tabs`) so old usage is unchanged.

## CDP subsystem (Chrome Navigation + content regulation) — `chrome-nav/chnav.exe` + `website/cdp.php` + `assets/cdp.js`
A third feature reusing the same agent: monitor every Chrome tab on a chosen PC AND enforce per-domain
**site rules** (block / warn / replace), managed from the browser. **Same design point as the DNS Manager —
no PHP backend of its own:** `cdp.php` is pure HTML that injects `CDP_DIR`/`CDP_PORT` as JS globals; `cdp.js`
does everything by sending PowerShell through the agent's `exec` op (`api.php?action=exec`).
- **`chnav.exe`** (built from `chrome_nav_monitor.py`; process name `chnav`). **Start** launches it *detached*
  via `Start-Process`, stdout → `nav.log` (survives the one-shot `exec` round-trip), passing `--block <dir>\blt.txt`;
  `--requests` checkbox adds request logging. The page **tails `nav.log`** into a live feed, parsing
  `[HH:MM:SS] NAV|SPA|DOC|req|BLOCK|WARN|REPLACE <url>` lines (other lines render as dim `info`); auto-refresh 4s.
- **Startup decision** (`main`): VERIFY the debug port first — if Chrome is already debugging on `--port`, attach
  (don't disturb it); otherwise kill any plain Chrome and relaunch with `--remote-debugging-port` (you can't add
  the port to a running Chrome). Launch flags include `--remote-allow-origins=*` (Chrome 111+ 403 gotcha) and
  `--disk-cache-size=1` (so a cached page still triggers the network request that interception needs).
- **Regulation is browser-level** (`run_browser`): connects to the *browser* websocket, `Target.setAutoAttach`
  (flat, `waitForDebuggerOnStart`) so every tab/window is covered. Rules come from **`blt.txt`** (`RuleSet`,
  hot-reloaded ~2s; read `utf-8-sig` so a BOM can't break the first rule). Format:
  `<domain> [block | warn <msg> | replace <url> | redirect <url>]`, bare domain = block, `*.x` wildcard,
  a bare domain also matches subdomains.
- **Enforcement mechanics (load-bearing):** the main-frame *document* request of a freshly-opened tab races Fetch
  setup and can't be paused reliably (only subresources pause). So:
  - **block/warn** — enforced on `Page.frameNavigated` via `Page.navigate` to a `data:` URL warning page
    (`{{ICON}}/{{TITLE}}/{{DOMAIN}}/{{MESSAGE}}` template, or `--block-page`); a real `Page.navigate` STOPS the
    in-flight load/redirect (so e.g. neverssl's 302 can't win), unlike `document.write` which races it.
  - **replace** — keeps the original URL: on `frameNavigated` to a replace host, **re-navigate the tab on the same
    session** so the now-active Fetch catches the reload and `Fetch.fulfillRequest`s the fetched replacement
    (cached ~60s). A per-session `replaced` guard prevents a reload loop. `inject_base()` inserts
    `<base href="<target>">` after `<head>` so the target's relative AND root-relative assets (e.g. `/assets/x.jpg`)
    resolve against the **target** origin, not the spoofed one — images/CSS/JS render correctly while the address bar
    keeps the original URL. (Same-origin XHR/`fetch` to the target's own APIs can still hit CORS — fine for content
    pages, not full dynamic apps.)
  - **redirect** — the URL actually **changes** to the target (`Page.navigate` to it), fully same-origin (no caveats);
    use it when you don't need to keep the original address. Both are valid for e.g. sending gambling domains to an
    approved site — `replace` to keep the address bar, `redirect` for a clean real navigation.
- **Always-on enforcement (`--persist`, the `🔒 enforce` checkbox):** `run_persistent` keeps Chrome under
  regulation — it **re-seizes** (relaunches the regulated instance) whenever Chrome is closed, and while running,
  `run_browser` periodically calls `kill_foreign_chrome()` to **kill any Chrome that isn't the regulated instance**
  (identified by the process that owns the debug port, so it never kills its own renderers). Closes the "close the
  debug Chrome / open a normal one / open a second window" bypasses. Regulates **Chrome only** — other browsers are
  OS-policy territory.
- **UI = a rules table** (`cdp.js`): rows of `domain | action | target/message`, parsed from / serialized to `blt.txt`
  (saved via the agent `save` op; hot-reloads live). Plus open-tabs chips, Chrome/debug-port status, a `🚫`/data-URL
  warning feed. One batched `buildLoadScript` round-trip returns folder, exe presence, running state, Chrome version,
  open targets, `blt.txt`, and the `nav.log` tail as one JSON blob. Folder auto-detects: running `chnav` path →
  next to the agent's own exe → `CDP_DIR` fallback (cached per-PC in `localStorage`).
- **Config:** `CDP_DIR` (folder holding `chnav.exe` + `blt.txt`) and `CDP_PORT` (default 9222) in `config.php`,
  overridable per-PC. `chnav.exe` is built with `cd chrome-nav && build.bat` (PyInstaller) → `dist/chnav.exe`
  and is **committed to git** (rebuild + commit when the `.py` changes; see Conventions), then deployed to
  `CDP_DIR`. `nav.log`/`blt.txt` are runtime/state on the PC, not committed.
  **Regulation runs at the agent's privilege (SYSTEM if elevated); for machines you administer.**
- `chrome-nav/cdp-guide.html` — standalone illustrated guide (build → deploy → site rules → caveats).

### Cross-origin `replace` — making the spoof functional (DESIGN NOTES / TODO — not built yet)
**Goal:** when a PC hits a blocked domain (e.g. a rotating gambling domain like `em777w9.cc`), serve our own site
**phkarera.com** (repo: `argonarsoftware-oss/lswin`) under the *original* URL via a `replace` rule, and have it actually
WORK (login / captcha / play), not just render.

**Where it stands today (verified):**
- `replace` + `inject_base()` already renders phkarera fully under the gambling URL (images/CSS/JS load — screenshot-confirmed).
- BUT phkarera's own API calls (`/api/auth/captcha`, `/api/auth/login`) run from origin `www.em777w9.cc` → `phkarera.com`
  and are **blocked by CORS** (no `Access-Control-Allow-Origin`); the session cookie would also be a **third-party cookie**.
  So the page shows but login/play don't function under the spoof.
- **`redirect`** (URL actually changes to `phkarera.com`) is the only option that fully works **today** — same-origin, no
  CORS/cookie issues. Trade-off: the address bar shows `phkarera.com`, not the gambling domain. This is the safe fallback.

**phkarera / lswin facts (for whoever implements this):** PHP app — `bootstrap.php` (session config), `routes.php` (router),
`src/` (`Lswin\` classes). Session cookie is currently **`SameSite=Lax`**, httponly, secure-on-HTTPS. Auth routes:
`GET /api/auth/captcha`, `POST /api/auth/{register,login,logout}` → `AuthController`.

**Methods considered (decide tomorrow):**
1. **Redirect** — set the rule to `redirect https://phkarera.com/`. Zero work, fully functional, URL changes. (Safe default.)
2. **Chrome launch flag (via chnav)** — chnav controls the launch, so add `--disable-features=TrackingProtection3pcd`
   (current 3p-cookie-blocking feature name; drifts per Chrome version — must test) to `launch_chrome()`, so the third-party
   session cookie is allowed. Still needs phkarera to send CORS + `SameSite=None; Secure`.
3. **CDP Fetch shim in chnav (PREFERRED — "all via chnav", no phkarera/OS changes):** for the replace target's requests,
   intercept on the debug port and (a) add `Access-Control-Allow-Origin: <gambling origin>` + `Access-Control-Allow-Credentials:
   true` to responses, answer `OPTIONS` preflight with 204; (b) keep a cookie jar — read phkarera's `Set-Cookie` from the login
   response and inject the `Cookie` header on later requests via `Fetch.continueRequest`, so the browser never has to send a
   3p cookie. Self-contained; doesn't touch the lswin repo. Needs Fetch at the **Response** stage for the target host.
4. **CORS in phkarera (lswin)** — add a CORS middleware (echo gambling origin + `Allow-Credentials` + preflight) in
   `bootstrap.php`/`routes.php`, and switch the session cookie to `SameSite=None; Secure`. Caveat: cookie still third-party →
   may still need the launch flag/policy from #2 on the managed PCs.
5. **Full reverse proxy in chnav** — don't inject `<base>`; instead proxy EVERY `em777w9.cc/*` request → `phkarera.com/*`
   (rewrite `Set-Cookie`/`Location` domains). Genuinely same-origin → no CORS, first-party cookies. Heaviest build + per-request latency.

**Caveat for ALL spoof methods (redirect excepted):** CDP Fetch / CORS shims cover **HTTP only** — if a target used
**websockets** for live games, those wouldn't be shimmable. **UPDATE (verified for phkarera):** lswin has **NO websockets**
(no `WebSocket`/`wss`/`socket.io` anywhere) — `games-bridge.js` talks to the server via `fetch('/api/game/…')`, i.e. plain
**HTTP with relative URLs**, and slots are server-authoritative over HTTP. So the websocket killer **does not apply to
phkarera**, and because everything is relative (`/api/…`, `/assets/…`), a same-origin proxy catches all of it. The spoof is
therefore genuinely viable here.

**Verdict (revised after the no-websockets finding):** the original "redirect, because websockets sink every spoof"
no longer holds — **phkarera has no websockets and uses relative URLs**, so a keep-URL spoof can fully work.
- **If keep-URL is required → Method 5 (full proxy) is now the best.** Serve every `em777w9.cc/*` request by proxying
  `phkarera.com/*` (NO `<base>` injection): phkarera's relative `/api/…` + `/assets/…` then resolve to the gambling origin,
  get proxied, and the whole thing is **genuinely same-origin** — no CORS at all, and cookies are **first-party** (chnav just
  rewrites `Set-Cookie` `Domain` → the gambling host). Login + games (HTTP) work. This is cleaner than Method 3's manual
  cookie jar + CORS shim. Cost: chnav must proxy every request (needs threading + sane handling of bodies/redirects/large
  assets) — a real build, but no longer doomed by websockets.
- **Method 3 (Fetch shim) is the lighter alternative** — keep `<base>` injection, let assets/API hit `phkarera.com` directly,
  and have chnav add CORS headers + inject the cookie. Less proxying, but the hand-rolled cookie jar is the fragile part.
- **Method 1 (redirect) is still the simplest and zero-maintenance** — pick it if keeping the gambling URL isn't actually
  worth a multi-day proxy (ask: what does the spoofed address bar buy, when phkarera is itself the destination?).
**Bottom line:** keep-URL is now achievable → if it's a hard requirement, build **Method 5 (full proxy)**; otherwise `redirect`
remains the pragmatic default. Start small: prove the proxy on the document + `/api/auth/login` round-trip (cookie rewrite)
before proxying the whole asset set.

**Operational notes:** rule domains are **bare** — the leading number in the DNS "top sites" stats is a *visit count*, not part
of the domain (e.g. the real domain is `em777w9.cc`, NOT `10em777w9.cc` which doesn't resolve; a bare `em777w9.cc` rule also
covers `www.em777w9.cc`). Deploy target on the test PC: `G:\Game Menu\chnav.exe` (set the CDP page's folder field to
`G:\Game Menu`, or `CDP_DIR`).

## DNS subsystem (TinyDNS) — `dns/` + `website/dns.php` + `assets/dns.js`
A second feature reusing the same agent: a network-wide DNS server on a chosen PC, managed from the
browser. Three parts:
- **`dns/dns_server.py` → `dnl.exe`** — a tiny, **stdlib-only** DNS server (no deps). Serves a
  hosts-style `records.txt`, blocks `blocklist.txt` domains (answered `0.0.0.0`), forwards everything
  else to upstream resolvers (default CleanBrowsing `185.228.168.10/11`, failover order), and logs every
  query to `queries.log`. All three files **hot-reload** (mtime poll, ~2s) — edit + save, no restart.
  - **records.txt** targets may be an **IP** (classic hosts mapping) or **another domain** (redirect: the
    server resolves the target via upstream and returns *its* IP for the queried name). `*.` wildcards on
    the left. For any name with a record, `AAAA` is answered NODATA so the IPv4 mapping/redirect wins in
    browsers (avoids leaking the real IPv6). A forward-result cache (TTL-respecting, capped) sits in front
    of upstream.
  - Built with `dns/build.bat` (PyInstaller, windowless single file). On first run **as admin** it
    self-registers a hidden SYSTEM boot task **`TinyDNS`** pointing at itself; `dns_server.py --uninstall`
    removes it. CLI: `--host/--port/--records/--blocklist/--log/--no-log/--no-install/--uninstall/--upstream`.
  - **Provenance:** the original source was lost (never pushed). `dns_server.py` was **reconstructed from
    the PyInstaller bytecode inside `dnl.exe`** and is faithful to the file formats `dns.php` depends on
    (TSV query log; `BLOCKED`/`LOCAL`/`FWD`/`NXDOMAIN` dispositions; the `TinyDNS` task + boot XML). Raw
    recovered bytecode kept locally as `dns_server.recovered.pyc` (git-ignored). A few log strings/comments
    may differ cosmetically from the original.
  - `dns/recover-config.html` / `recover-config.ps1`, `check-selfinstall.html` — standalone helper docs/scripts.
- **`website/dns.php` + `assets/dns.js`** — the DNS Manager UI. **Key design point:** it has no PHP
  backend of its own — it manages everything by sending **PowerShell/cmd through the agent's `exec` op**
  (`api.php?action=exec`). One batched PowerShell round-trip (`buildLoadScript`) returns the DNS folder,
  the machine's IPs, the `TinyDNS` task status, `blocklist.txt`/`records.txt` contents, and the
  `queries.log` tail as a single compact JSON blob. Start/Stop/Restart drive `schtasks /run|/end` on the
  `TinyDNS` task; Save writes the files (hot-reloaded live); Test runs `nslookup … 127.0.0.1`. The DNS
  folder auto-detects from the task's exe path if not overridden (cached per-PC in `localStorage`).
- **Config:** `DNS_DIR` (default folder holding `records.txt`/`blocklist.txt`/`dnl.exe`) and `DNS_TASK`
  (`TinyDNS`) in `config.php`; both overridable per-PC in the UI. (Note: the `config.sample.php` comment
  says `dnsserver.exe`, but the built exe is actually `dnl.exe`.)
- **Query log on the box is a flat TSV file.** `dnl.exe` *appends* one tab-separated line per query
  to `queries.log` (`time, client IP, domain, type, disposition`). It self-rotates: once the file passes
  5 MB it's renamed to `queries.log.1` (one generation kept) and a fresh file started — so old entries
  age out by rotation, never "replaced by newest" in place.
- **Optional MySQL history (the agent bridge) — does NOT touch `dnl.exe`.** To keep durable, queryable,
  cross-PC history without ever stopping the DNS server: the VPS reads only the **new bytes** of
  `queries.log` through the agent's `exec` op (a **shared, delete-tolerant** read — `FileShare
  'ReadWrite, Delete'` — so `dnl.exe` keeps appending and can still rotate) and inserts them into MySQL.
  No `dns_server.py` change, no exe rebuild, no TinyDNS task restart, no DNS outage.
  - `dns-sync-core.php` (`dns_sync_agent`) holds the logic: read the per-agent byte offset from
    `dns_ingest_state`, ask the agent for new complete lines past it (capped to ~100 KB/call to stay under
    the agent's 200 KB `exec` output cap; rotation to `.1` handled by reading its tail then the new file),
    insert into `dns_queries`, advance the offset. `dns-sync.php` is the browser trigger (login/API-key);
    `cron/dns-sync.php` is a CLI that syncs all online agents (run every minute so history accrues even
    with no browser open). Schema in `dns-schema.sql`; PDO via `db()` in `lib.php` (MySQL config `DB_*`).
  - **Graceful fallback:** if `DB_*` isn't configured, `dns-log.php`/`dns-sync.php` report `db:false` and
    `dns.js` reverts to tailing `queries.log` live via the agent (the original behavior) — so the feature
    is purely additive and the page works with or without MySQL.
  - **UI:** `dns.js` reads history from `dns-log.php` (server-side filter over ALL history, keyset
    "Load older" paging); "Clear" deletes the DB rows for that PC (the live file is untouched, so new
    queries re-accumulate). `db`-mode vs `file`-mode is auto-detected on first load.
  - **Rollup + retention (permanent "Top sites", bounded raw):** raw `dns_queries` rows are aggregated
    into `dns_stats_daily` (`agent_id, day, domain → hits`) by `dns_rollup()` — keyed by a per-agent id
    watermark (`dns_rollup_state`) so each row counts once — then raw rows older than
    `DNS_RAW_RETENTION_DAYS` (default 7) that are already rolled up get pruned by `dns_prune_old()` (run
    from the cron). So the row-by-row detail ages out while the network-wide visit counts live forever and
    stay tiny. `dns-log.php?action=stats&days=N` powers the **Top sites** card (`SUM(hits)` per domain over
    a date range). Rollup is best-effort inside `dns_sync_agent` — a not-yet-migrated stats table can't
    break ingest. **All rollup/prune/stats is pure VPS MySQL — zero extra interaction with `dnl.exe`.**
  - **Top-sites refinements:** `?action=stats&group=base` folds subdomains into the registrable domain
    (eTLD+1 via `registrable_domain()` in `lib.php`; `group=full` keeps full hostnames). Each entry carries
    a heuristic `gambling` flag (`is_gambling_domain()` — keyword/known-brand substring match incl. PH/Asia
    betting brands) and the response includes a `gambling` summary (`count`/`visits`/`sites`); the UI shows a
    warning **alert banner** + per-row badge. Same grouping + flag in `dns-text.php`.

## Queue protocol
Browser/API → `enqueue_command` writes `data/<id>/cmd/<cmdId>.json` → agent long-poll claims it →
agent runs it → posts result → `store_result` writes `data/<id>/res/<cmdId>.json` → `fetch_result`
(api.php) returns it. Files are written via temp+rename. Download/upload move bytes as base64.

## Auto-enroll (zero-touch onboarding)
Two ways a PC becomes known to the site:
- **Static** — a `name`+`token` entry per machine in `rm_agents()` (`config.php`). Pinned, can't be
  removed from the UI.
- **Auto-enroll** — set one shared `ENROLL_KEY` in `config.php` and the same value as the agent's
  `token`. Any agent presenting that key registers itself by **machine id** (MachineName + MachineGuid,
  sent as `X-Agent-Id`/`X-Agent-Name`) into the `data/agents.json` registry and appears in the picker —
  no per-PC config and no editing `config.php` again. `all_agents()` merges both sources. Only
  auto-enrolled PCs can be removed via `api.php?action=removeagent` (which also deletes their queue dir).
  Security: only a holder of `ENROLL_KEY` can enroll, and an agent can only expose its OWN machine.

## Conventions
- Secrets live only in `config.php` / `agent.conf` (git-ignored). Commit `*.sample` instead.
- The agent makes outbound calls only; reachability needs no inbound config.
- Multiple PCs: static `rm_agents()` entries and/or auto-enrolled agents (see above); the UI shows a picker.
- C# targets the in-box compiler — no C# 6+ syntax (no string interpolation / `?.` / `nameof`).
- **Compiled tool exes ARE committed to git.** The PyInstaller build artifacts `chrome-nav/dist/chnav.exe`
  and `dns/dist/dnl.exe` are tracked and pushed, so the latest binary ships with the repo (a `git pull`
  brings it; deploy it to the target PCs). Rebuild + commit them whenever their `.py` changes. The
  `.gitignore`s use `dist/*` + `!dist/<exe>` to keep the exe while ignoring other build output.
  **Exception — the C# agent exes stay git-ignored** (`agent/Agent.exe`/`agentsvc.exe`): `build.bat <enroll-key>`
  can bake the enroll secret into them via `Embedded.cs`, so committing one would leak the key.

## Agent ↔ web compatibility (keep old & new versions interoperable)
The agent reports `AGENT_VERSION` + a `CAPS` list (`Agent.cs`); it's sent on every request
via `X-Agent-Version` (server records it per-agent → shown in `?action=agents`) and returned by
the `info` op (`version`, `caps`). To keep a fleet of mixed agent versions working without
redeploying all of them, the protocol is **additive**:
- **Never remove or rename** an op or a `CAPS` entry; only append. Known ops never change meaning.
- **New command args are optional** — old agents ignore args they don't read.
- **New result fields are optional** — old web ignores fields it doesn't use (e.g. drive `label`).
- A newer web app calling an op an old agent lacks gets `{ok:false, unknown_op:true}` — **feature-detect**
  (check `caps`/`version`) and fall back instead of erroring.
- Bump `AGENT_VERSION` when behavior changes; only rebuild/redeploy agents for features that need
  new data *from* the agent (e.g. drive labels). Web-only changes need no agent rebuild.
- **One agent per machine.** AgentId is the MachineGuid, so two processes would share one queue
  and race for commands. Named mutexes enforce single-instance (`Global\rmdownloader-agent-<id>` for
  the worker, `…-agentsvc-<id>` for the supervisor): a second copy prints a message and exits.

## Updating agents (supervisor model)
The boot task runs `agentsvc.exe`, which child-manages `Agent.exe`. To update the worker remotely:
send the `update` op with the new `Agent.exe` as base64 (`exe` arg). The worker stages it and exits;
the supervisor backs up the old exe, swaps in the new one, and starts it on **probation** — the new
worker must refresh `worker.hb` within ~90s or it's **auto-rolled-back** to `Agent.prev.exe`. The
worker (not the supervisor) is the boot exe-free, swappable part, so this avoids the file-lock /
self-kill problem. During the swap the supervisor `ping`s `agent.php?action=ping` (marks online
without claiming commands) so the connection isn't lost. Roll out to a **canary PC first**, confirm
it reconnects with the new version, then the fleet — a bad worker fleet-wide would otherwise need the
manual recipe below. The supervisor itself changes rarely; updating it uses the one-time migration:
- **Migration / supervisor update (one-time, via the old agent's `exec`):** upload `agentsvc.exe`
  (+ new `Agent.exe`) next to the current exe, then from a *detached* one-shot scheduled task:
  `schtasks /end /tn rmdownloaderAgent` → re-create the task to run `agentsvc.exe` → `schtasks /run`.
  (Detached so ending the agent doesn't kill the updater.)

## Auto-deploy (GitHub webhook) — `website/webhook-deploy.php`
A push webhook that auto-updates the VPS, modeled on the Argonar Construction one. GitHub POSTs every
push to `https://dos.argonar.co/webhook-deploy.php`; the handler **only deploys when a commit message
contains `[deploy]`** (and the push is to `main`, and the `X-Hub-Signature-256` HMAC verifies against
`WEBHOOK_SECRET`). On a match it runs `git fetch origin main` + `git reset --hard origin/main`, then
restores `www-data` ownership and re-`chmod`s `website/data`. Plain pushes (no `[deploy]`) are logged and
skipped, so not every commit redeploys.
- **Layout note (differs from Argonar):** Apache's docroot is `website/`, but the **git repo root is its
  parent** `/var/www/rmdownloader`. So the PHP file lives in `website/` (to be served) while git targets
  `dirname(__DIR__)`. Override with `DEPLOY_WEB_ROOT` in `config.php` if the repo lives elsewhere.
- **Scope guard:** every op (`fetch`/`reset --hard`/`chown`/`chmod`) targets only `WEB_ROOT`. Before any of
  them it aborts unless `WEB_ROOT` resolves to a real git repo whose `origin` URL contains
  `DEPLOY_EXPECT_REMOTE` (default `rmdownloader`) — so a mistyped `DEPLOY_WEB_ROOT` can never reset/chown a
  sibling project under `/var/www` (argonar, adarna.cc, …).
- **Secret** is `WEBHOOK_SECRET` in `config.php` (git-ignored, survives the hard reset). Empty ⇒ webhook
  disabled (all requests rejected). `config.php`/`agent.conf` are git-ignored so `reset --hard` never
  clobbers local secrets. Log: `website/data/deploy.log` (denied by `data/.htaccess`, git-ignored as `*.log`).
- **One-time server prep:** `chown -R www-data:www-data /var/www/rmdownloader` and
  `git config --system --add safe.directory /var/www/rmdownloader` (as root) so Apache's user can run git.
  Use `--system`, not `sudo -u www-data … --global` — the latter fails trying to write www-data's home
  gitconfig (`/var/www/.gitconfig: Permission denied`). The handler also passes `-c safe.directory` inline,
  so this is belt-and-suspenders. Then add the webhook in GitHub (push event, `application/json`, same secret).

## Build / run
- Agent: `cd agent && build.bat`, set `agent.conf`, run `Agent.exe` (auto-start via the PS1).
- Web (VPS): `git clone` into `/var/www/`, `cp config.sample.php config.php`, edit, point Apache
  at `website/`, make `website/data` writable, add HTTPS with certbot.
