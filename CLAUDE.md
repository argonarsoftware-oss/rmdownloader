# CLAUDE.md — rmdownloader

Remote file manager: a browser UI on a VPS that manages the drives of one or more Windows PCs.
The agent **reverse-connects out** to the site (outbound HTTPS long-poll), so no inbound port
or tunnel is needed — you just run the agent.

## Layout
- `agent/` — native Windows companion (`Agent.exe`), an outbound HTTP polling client.
  - `Agent.cs` — single-file C# source (in-box .NET Framework 4 compiler; uses
    `System.Web.Extensions` JavaScriptSerializer for JSON). Polls `agent.php`, runs commands,
    posts results.
  - `build.bat` — compiles with `csc.exe` (`pushd %~dp0`, `/r:System.Web.Extensions.dll`).
  - `agent.conf.sample` — per-machine config: `server`, `token`, `root`. Copied to `agent.conf`.
  - `install-startup.ps1` / `uninstall-startup.ps1` — Task Scheduler boot auto-start (SYSTEM).
- `website/` — PHP app (XAMPP locally / Apache on the VPS).
  - `index.php`, `login.php`, `logout.php` — pages.
  - `agent.php` — agent-facing endpoint: `?action=poll` (long-poll, returns queued commands)
    and `?action=result`. Auth by per-PC token (`X-Agent-Token`); no session.
  - `api.php` — browser/automation-facing: enqueues a command for the selected PC and waits for
    the result. Auth via session login OR `API_KEY` (`?key=` / `X-Api-Key`).
  - `lib.php` — session/auth (`api_authorized`), agent lookup, and the file-based queue
    (`enqueue_command`, `claim_commands`, `store_result`, `fetch_result`, `is_online`).
  - `config.sample.php` — copied to `config.php`; holds `rm_agents()`, `WEB_PASSWORD`, `API_KEY`,
    `DATA_DIR`.
  - `data/` — runtime command queue (`<agentId>/cmd|res/*.json`, `online`). `.htaccess` denies web access.
  - `assets/` — `app.js`, `style.css`.
- `deploy/apache-vhost.conf` — VPS vhost (`dos.argonar.co`).
- `vps-setup-guide.html` — standalone illustrated setup guide (local file).

## Queue protocol
Browser/API → `enqueue_command` writes `data/<id>/cmd/<cmdId>.json` → agent long-poll claims it →
agent runs it → posts result → `store_result` writes `data/<id>/res/<cmdId>.json` → `fetch_result`
(api.php) returns it. Files are written via temp+rename. Download/upload move bytes as base64.

## Conventions
- Secrets live only in `config.php` / `agent.conf` (git-ignored). Commit `*.sample` instead.
- The agent makes outbound calls only; reachability needs no inbound config.
- Multiple PCs: one `name`+`token` entry per machine in `rm_agents()`; the UI shows a picker.
- C# targets the in-box compiler — no C# 6+ syntax (no string interpolation / `?.` / `nameof`).

## Build / run
- Agent: `cd agent && build.bat`, set `agent.conf`, run `Agent.exe` (auto-start via the PS1).
- Web (VPS): `git clone` into `/var/www/`, `cp config.sample.php config.php`, edit, point Apache
  at `website/`, make `website/data` writable, add HTTPS with certbot.
