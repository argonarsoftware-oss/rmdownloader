# CLAUDE.md — rmdownloader

Remote file manager: a browser UI on a VPS that manages the drives of one or more
Windows PCs through a small native agent.

## Layout
- `agent/` — native Windows companion (`Agent.exe`), a token-protected HTTP file API.
  - `Agent.cs` — single-file C# source (targets the in-box .NET Framework 4 compiler).
  - `build.bat` — compiles with `csc.exe`; no SDK/installs required.
  - `agent.conf.sample` — per-machine config (token/host/port/root). Copied to `agent.conf`.
  - `install-startup.ps1` / `uninstall-startup.ps1` — Task Scheduler boot auto-start (SYSTEM).
- `website/` — PHP app (XAMPP locally / Apache on the VPS).
  - `index.php`, `login.php`, `logout.php` — pages.
  - `api.php` — browser-facing proxy; injects the agent token, enforces login, routes to
    the selected PC via `?agent=<id>`.
  - `lib.php` — session/auth + cURL agent client (`current_agent()`, `agent_call`, `agent_json`).
  - `config.sample.php` — copied to `config.php`; holds `rm_agents()` + `WEB_PASSWORD`.
  - `assets/` — `app.js`, `style.css`.
  - `.htaccess` — blocks direct access to includes/configs.
- `deploy/apache-vhost.conf` — VPS vhost (`dos.argonar.co`).

## Conventions
- Secrets live only in `config.php` / `agent.conf` (git-ignored). Commit `*.sample` instead.
- The browser only ever calls `api.php` (same origin); it never sees agent URLs or tokens.
- Multiple PCs: one entry per machine in `rm_agents()`; the UI shows a picker.
- Agent reachability from the VPS is via reverse SSH tunnel (preferred) or firewalled port-forward.

## Build / run
- Agent: `cd agent && build.bat`, set `agent.conf`, run `Agent.exe` (auto-start via the PS1).
- Web (VPS): `git clone` into `/var/www/`, `cp config.sample.php config.php`, edit, point Apache
  at `website/`, add HTTPS with certbot.
