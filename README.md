# rmdownloader — Remote File Manager (agent + web UI)

Manage a Windows machine's drives/folders (C:\ etc.) from a browser.

```
Browser ──HTTPS──> PHP site (VPS / XAMPP, Apache)  ──HTTP+token──>  Agent.exe ──> filesystem
          login              proxy (api.php)            X-Agent-Token        on the Windows host
```

* **Agent.exe** — a tiny (~15 KB) self-contained C# program. Exposes a token-protected
  HTTP file API (list / read / write / upload / download / mkdir / rename / delete).
  Runs on the Windows machine you want to manage.
* **website/** — PHP app served by Apache (XAMPP locally, or a Linux VPS). It is the UI
  **and** a server-side proxy that injects the secret token, so the browser never sees it,
  and enforces a login.

The browser only ever talks to the PHP site (same origin). The PHP site talks to the agent.

---

## 1. Build the agent (Windows)

No SDK needed — uses the in-box .NET Framework compiler.

```bat
cd agent
build.bat
```

Produces `agent\Agent.exe`.

## 2. Configure the agent

```
copy agent.conf.sample agent.conf
```

Edit `agent.conf`:

```ini
token=<a long random secret>     # must match the website
host=127.0.0.1                   # see "Connecting the VPS to the agent" below
port=8765
root=                            # optional sandbox, e.g. C:\shared (empty = whole machine)
```

Run it once to test:  `Agent.exe`

## 3. Auto-start at boot (Task Scheduler)

In an **elevated** PowerShell:

```powershell
cd agent
powershell -ExecutionPolicy Bypass -File install-startup.ps1
```

This registers a task `rmdownloaderAgent` that launches `Agent.exe` at every boot as
SYSTEM (no login needed) and restarts it if it ever exits. Remove with
`uninstall-startup.ps1`.

---

## 4. Deploy the website

### Local (XAMPP)
Copy `website\` into `C:\xampp\htdocs\filemanager`, then
`copy config.sample.php config.php`, edit it, and open `http://localhost/filemanager/`.

### VPS (172.104.186.245, Apache, git clone)

```bash
cd /var/www
git clone https://github.com/argonarsoftware-oss/rmdownloader.git
cd rmdownloader/website
cp config.sample.php config.php
nano config.php            # set AGENT_URL, AGENT_TOKEN, WEB_PASSWORD
```

Point Apache at `…/rmdownloader/website` (see `deploy/apache-vhost.conf`) and reload:

```bash
sudo cp /var/www/rmdownloader/deploy/apache-vhost.conf /etc/apache2/sites-available/rmdownloader.conf
sudo a2ensite rmdownloader && sudo a2enmod rewrite headers
sudo systemctl reload apache2
sudo chown -R www-data:www-data /var/www/rmdownloader/website
```

Update later with:  `cd /var/www/rmdownloader && git pull`  (your `config.php` is git-ignored,
so it survives pulls).

---

## Connecting the VPS to the agent

The VPS must be able to reach the agent. Pick one:

### A. Reverse SSH tunnel (recommended — works behind home NAT, encrypted)
On the Windows machine, keep a tunnel open to the VPS:

```bash
ssh -N -R 8765:127.0.0.1:8765 youruser@172.104.186.245
```

Then on the agent use `host=127.0.0.1`, and on the VPS set
`AGENT_URL = http://127.0.0.1:8765`. Nothing is exposed to the public internet.
(Use `autossh` or a scheduled task to keep the tunnel alive.)

### B. Direct / port-forwarded
Set agent `host=0.0.0.0`, forward TCP 8765 to the Windows machine, and set
`AGENT_URL = http://YOUR.PUBLIC.IP:8765` on the VPS. **Restrict the firewall so only
172.104.186.245 can reach port 8765** (the token is the only auth and the hop is plain HTTP).

---

## Security notes
* Change `token` and `WEB_PASSWORD` to strong values before exposing anything.
* Put the VPS site behind HTTPS (Let's Encrypt / `certbot`).
* `root=` in `agent.conf` sandboxes the agent to one folder if you don't need full-disk access.
* The agent grants whoever holds the token full read/write to the configured scope — treat it
  like an SSH key. Only run this on machines and accounts you own.
