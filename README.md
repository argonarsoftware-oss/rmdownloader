# rmdownloader — Remote File Manager (agent + web UI)

Manage a Windows machine's drives/folders (C:\ etc.) from a browser — even when the PC is
behind home NAT. The agent **reverse-connects out** to your web server, so there's no inbound
port, no tunnel, no port-forwarding. You just run the agent.

```
Browser ──HTTPS──> PHP site (VPS / XAMPP)  <══poll/result══  Agent.exe ──> filesystem
          login          file queue (data/)   outbound HTTPS   on each Windows PC
```

* **Agent.exe** — a tiny (~13 KB) self-contained C# program. It long-polls the site for
  commands, runs them locally (list / read / write / upload / download / mkdir / rename /
  delete), and posts results back. Outbound HTTPS only.
* **website/** — PHP app (XAMPP locally, or Apache on a VPS). The UI plus a file-based command
  queue. The browser talks only to the site; the site never connects to the PC — the agent
  pulls work from it.

---

## 1. Build the agent (Windows)

No SDK needed — uses the in-box .NET Framework compiler.

```bat
cd agent
build.bat
```

Produces `agent\Agent.exe`.

## 2. Run the agent

No config file needed — the server defaults to `https://dos.argonar.co`, so just pass the
PC's token (must match that PC's token in the website config):

```
Agent.exe <token>
```

The agent is **windowless** (runs silently in the background — no console) and the PC shows
🟢 online in the UI. Override the server with `Agent.exe <token> --server https://your.host`,
or sandbox with `--root C:\shared`.

> Optional: `copy agent.conf.sample agent.conf` and set `server`/`token`/`root` there instead
> of passing arguments. Command-line values override the file.

## 3. Auto-start at boot — automatic

On first run the agent **self-installs a hidden Task Scheduler entry** (`rmdownloaderAgent`)
pointing at itself, so it relaunches on every boot. Run **elevated** and it registers as
SYSTEM/boot (up before login); run normally and it registers as current-user/logon.

* Skip self-install: `Agent.exe <token> --no-autostart`
* Remove the task: `Agent.exe --uninstall`
* Alternative explicit installer: `install-startup.ps1 -Token <token>`

Registers a task `rmdownloaderAgent` that launches `Agent.exe` at every boot as SYSTEM
(no login needed) and restarts it if it ever exits. Remove with `uninstall-startup.ps1`.

---

## 4. Deploy the website

### Local (XAMPP)
Copy `website\` into `C:\xampp\htdocs\filemanager`, then `copy config.sample.php config.php`,
edit it, and open `http://localhost/filemanager/`.

### VPS (Apache, git clone)

Public URL: **https://dos.argonar.co** (add an A record `dos.argonar.co → 172.104.186.245`).

```bash
cd /var/www
git clone https://github.com/argonarsoftware-oss/rmdownloader.git
cd rmdownloader/website
cp config.sample.php config.php
nano config.php            # set the rm_agents() list + WEB_PASSWORD
```

Point Apache at `…/rmdownloader/website` (see `deploy/apache-vhost.conf`), make the queue
writable, and reload:

```bash
sudo cp /var/www/rmdownloader/deploy/apache-vhost.conf /etc/apache2/sites-available/rmdownloader.conf
sudo a2ensite rmdownloader && sudo a2enmod rewrite headers
sudo chown -R www-data:www-data /var/www/rmdownloader/website
sudo chmod -R 775 /var/www/rmdownloader/website/data
sudo systemctl reload apache2
sudo certbot --apache -d dos.argonar.co     # HTTPS
```

Update later with:  `cd /var/www/rmdownloader && git pull`  (your `config.php` is git-ignored,
so it survives pulls).

> There is a step-by-step illustrated version in **`vps-setup-guide.html`** (open it in a browser).

### Auto-deploy on push (GitHub webhook)

`website/webhook-deploy.php` auto-updates the VPS when you push — but **only for commits whose message
contains `[deploy]`** (other pushes are skipped), so you control exactly when the live site changes.

```bash
# one-time server prep: let Apache's user run git in the repo
sudo chown -R www-data:www-data /var/www/rmdownloader
sudo -u www-data git config --global --add safe.directory /var/www/rmdownloader
```

Set a secret in `config.php`:

```php
define('WEBHOOK_SECRET', 'a-long-random-value');   // '' disables the webhook
```

Then in **GitHub → repo → Settings → Webhooks → Add webhook**:
* **Payload URL:** `https://dos.argonar.co/webhook-deploy.php`
* **Content type:** `application/json`
* **Secret:** the same value as `WEBHOOK_SECRET`
* **Events:** Just the `push` event

Now `git commit -m "fix: thing [deploy]" && git push` runs `git fetch + reset --hard origin/main` on the
VPS. `config.php` is git-ignored so the hard reset never touches your secrets. Activity is logged to
`website/data/deploy.log`.

---

## Multiple client PCs

The site manages many machines. List them in `rm_agents()` in `config.php` — each entry is just
a **name + token** (no URL/port, because the agent dials out). The web UI shows a **PC picker**
with 🟢/⚪ online status. To add a PC: build/run `Agent.exe` on it with `server=` your site and a
unique `token`, then add the matching entry to `rm_agents()`.

```php
function rm_agents() {
    return array(
        'pc1' => array('name' => 'Main PC',   'token' => 'secret-1'),
        'pc2' => array('name' => 'Office PC', 'token' => 'secret-2'),
    );
}
```

---

## Programmatic / API access (URL parameter)

Set `API_KEY` in `config.php` to drive the API without a browser login — handy for scripts,
`curl`, or Claude Code. Pass it as `?key=<API_KEY>` (or header `X-Api-Key`). The same
`?agent=<id>` selects the target PC. Examples:

```bash
# list a directory as JSON
curl "https://dos.argonar.co/api.php?action=list&agent=pc1&path=C:\\&key=$API_KEY"
# which PCs are online
curl "https://dos.argonar.co/api.php?action=agents&key=$API_KEY"
# download a file
curl -OJ "https://dos.argonar.co/api.php?action=download&agent=pc1&path=C:\\notes.txt&key=$API_KEY"
```

Actions: `agents`, `info`, `list`, `read`, `download` (GET); `mkdir`, `delete`, `rename`,
`save`, `upload` (POST). Leave `API_KEY=''` to disable key access entirely.

---

## Security notes
* Change every agent `token`, `WEB_PASSWORD`, and `API_KEY` to strong random values before exposing anything.
* Put the VPS site behind HTTPS (Let's Encrypt / `certbot`). The API key and tokens ride on it.
* `root=` in `agent.conf` sandboxes an agent to one folder if you don't need full-disk access.
* The token grants whoever holds it full read/write to the configured scope — treat it like an
  SSH key. Only run this on machines and accounts you own.
