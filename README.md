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

Public URL: **http://dos.argonar.co** (add an A record `dos.argonar.co → 172.104.186.245`).

```bash
cd /var/www
git clone https://github.com/argonarsoftware-oss/rmdownloader.git
cd rmdownloader/website
cp config.sample.php config.php
nano config.php            # set the rm_agents() list + WEB_PASSWORD
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

## Autodeploy (push → live)

Every push to `main` triggers `.github/workflows/deploy.yml`, which SSHes into the VPS and
runs `deploy/deploy.sh` (`git reset --hard origin/main` + reload Apache). `config.php` is
git-ignored, so your secrets are never overwritten.

**One-time setup:**

1. On the VPS, do the initial clone + Apache setup (see "VPS" above) and make the script
   runnable, and let the deploy user reload Apache without a password:
   ```bash
   chmod +x /var/www/rmdownloader/deploy/deploy.sh
   echo "$USER ALL=(root) NOPASSWD: /bin/systemctl reload apache2, /bin/chown -R www-data\:www-data /var/www/rmdownloader/website" | sudo tee /etc/sudoers.d/rmdownloader
   ```

2. Add an SSH key the Action will use:
   ```bash
   ssh-keygen -t ed25519 -f ~/deploy_key -N ""
   cat ~/deploy_key.pub >> ~/.ssh/authorized_keys   # on the VPS, for the deploy user
   ```

3. Register repo secrets (run locally where `gh` is logged in):
   ```bash
   gh secret set VPS_HOST   -b "172.104.186.245"      -R argonarsoftware-oss/rmdownloader
   gh secret set VPS_USER   -b "<your-vps-user>"      -R argonarsoftware-oss/rmdownloader
   gh secret set VPS_SSH_KEY < ~/deploy_key           -R argonarsoftware-oss/rmdownloader
   # optional, if SSH is not on port 22:
   gh secret set VPS_PORT   -b "22"                   -R argonarsoftware-oss/rmdownloader
   ```

Now `git push` deploys automatically. You can also run it on demand from the **Actions** tab
("Run workflow"). No-CI fallback: a cron `*/5 * * * * /var/www/rmdownloader/deploy/deploy.sh`.

---

## Multiple client PCs

The site manages many machines. List them in `rm_agents()` in `config.php`; each gets its
own `url` + `token`, and the web UI shows a **PC picker** in the top bar. To add a PC:
build/run `Agent.exe` on it, give it a unique token, make it reachable from the VPS
(below), and add an entry to `rm_agents()`.

## Connecting the VPS to each agent

The VPS must be able to reach every agent. Pick one per machine:

### A. Reverse SSH tunnel (recommended — works behind home NAT, encrypted)
On each Windows machine, keep a tunnel open to the VPS, using a **distinct VPS port per PC**:

```bash
# PC1
ssh -N -R 8765:127.0.0.1:8765 youruser@172.104.186.245
# PC2 (run from the second machine)
ssh -N -R 8766:127.0.0.1:8765 youruser@172.104.186.245
```

Each agent uses `host=127.0.0.1`; in `rm_agents()` set that PC's `url` to the matching
VPS port (`http://127.0.0.1:8765`, `http://127.0.0.1:8766`, …). Nothing is exposed to the
public internet. Use `autossh` or a scheduled task to keep the tunnels alive.

### B. Direct / port-forwarded
Set agent `host=0.0.0.0`, forward its TCP port to the Windows machine, and set that PC's
`url` to `http://YOUR.PUBLIC.IP:PORT`. **Restrict the firewall so only 172.104.186.245 can
reach the port** (the token is the only auth and the hop is plain HTTP).

---

## Security notes
* Change `token` and `WEB_PASSWORD` to strong values before exposing anything.
* Put the VPS site behind HTTPS (Let's Encrypt / `certbot`).
* `root=` in `agent.conf` sandboxes the agent to one folder if you don't need full-disk access.
* The agent grants whoever holds the token full read/write to the configured scope — treat it
  like an SSH key. Only run this on machines and accounts you own.
