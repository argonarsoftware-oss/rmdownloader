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

## Build / run
- Agent: `cd agent && build.bat`, set `agent.conf`, run `Agent.exe` (auto-start via the PS1).
- Web (VPS): `git clone` into `/var/www/`, `cp config.sample.php config.php`, edit, point Apache
  at `website/`, make `website/data` writable, add HTTPS with certbot.
