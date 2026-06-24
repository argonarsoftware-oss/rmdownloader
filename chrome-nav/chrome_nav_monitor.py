#!/usr/bin/env python3
"""
chrome_nav_monitor.py — watch AND regulate Chrome's navigation in real time via
the Chrome DevTools Protocol (CDP).

What it does:
  1. Detects the OS and locates the Chrome executable.
  2. Kills any running Chrome, waits for it to fully exit, then relaunches it
     with --remote-debugging-port and a dedicated --user-data-dir so the debug
     port reliably opens.
  3. Polls http://127.0.0.1:<port>/json/version until the port is ready.
  4. Monitors navigation and prints every event as it happens:
       - Page.frameNavigated            (main-frame navigations)  -> NAV
       - Page.navigatedWithinDocument   (in-page SPA changes)     -> SPA
       - Network.requestWillBeSent      (request URLs, --requests)-> DOC/req
  5. Optional regulation (--block): enable the Fetch domain and, for any request
     to a blocked domain, serve a warning page (top navigations) or fail the
     request (subresources) -> BLOCK. The blocklist hot-reloads (mtime poll), so
     the dashboard can edit it live with no restart.
  6. With --block (or --all-tabs) it attaches at the BROWSER level and
     auto-attaches to every page target, so new tabs/windows are covered too
     (single-tab attach remains the default for plain monitoring).
  7. Clean shutdown on Ctrl+C.

Dependencies: requests, websocket-client   (no Selenium)
    pip install requests websocket-client
"""

import argparse
import base64
import json
import os
import platform
import shutil
import signal
import subprocess
import sys
import tempfile
import threading
import time
import traceback
from datetime import datetime
from urllib.parse import urlparse

import requests
from websocket import create_connection, WebSocketTimeoutException


# --------------------------------------------------------------------------- #
# Chrome discovery
# --------------------------------------------------------------------------- #
def find_chrome():
    """Return the path to a Chrome (or Chromium) executable, or None."""
    system = platform.system()

    candidates = []
    if system == "Windows":
        program_files = [
            os.environ.get("PROGRAMFILES", r"C:\Program Files"),
            os.environ.get("PROGRAMFILES(X86)", r"C:\Program Files (x86)"),
            os.environ.get("LOCALAPPDATA", ""),
        ]
        for base in program_files:
            if not base:
                continue
            candidates.append(os.path.join(base, "Google", "Chrome", "Application", "chrome.exe"))
        candidates.append(os.path.join(
            os.environ.get("LOCALAPPDATA", ""), "Chromium", "Application", "chrome.exe"))
    elif system == "Darwin":
        candidates += [
            "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome",
            "/Applications/Google Chrome Canary.app/Contents/MacOS/Google Chrome Canary",
            "/Applications/Chromium.app/Contents/MacOS/Chromium",
        ]
    else:  # Linux / other
        for name in ("google-chrome", "google-chrome-stable", "chromium",
                     "chromium-browser", "chrome"):
            found = shutil.which(name)
            if found:
                candidates.append(found)
        candidates += [
            "/usr/bin/google-chrome",
            "/usr/bin/chromium",
            "/usr/bin/chromium-browser",
            "/snap/bin/chromium",
        ]

    for path in candidates:
        if path and os.path.isfile(path):
            return path
    return None


# --------------------------------------------------------------------------- #
# Kill existing Chrome
# --------------------------------------------------------------------------- #
def kill_chrome():
    """Kill all running Chrome processes and wait for them to exit."""
    system = platform.system()
    log("Stopping any running Chrome instances...")

    if system == "Windows":
        # /T also kills child processes (renderers, GPU, etc.)
        subprocess.run(["taskkill", "/F", "/T", "/IM", "chrome.exe"],
                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                       creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0))
        proc_names = ["chrome.exe"]
        running = lambda: _windows_chrome_running(proc_names)
    else:
        names = ["Google Chrome", "chrome", "chromium", "chromium-browser"]
        for name in names:
            subprocess.run(["pkill", "-f", name],
                           stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        running = lambda: _posix_chrome_running(names)

    # Wait (up to ~10s) for processes to fully exit so the debug port is free.
    deadline = time.time() + 10
    while time.time() < deadline:
        if not running():
            break
        time.sleep(0.25)
    time.sleep(0.5)  # small grace period for file locks to release


def _windows_chrome_running(names):
    try:
        out = subprocess.run(["tasklist", "/FI", "IMAGENAME eq chrome.exe"],
                             capture_output=True, text=True,
                             creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0)).stdout.lower()
        return "chrome.exe" in out
    except Exception:
        return False


def _posix_chrome_running(names):
    for name in names:
        r = subprocess.run(["pgrep", "-f", name],
                           stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        if r.returncode == 0:
            return True
    return False


def chrome_running():
    """True if any Chrome/Chromium process is currently running."""
    if platform.system() == "Windows":
        return _windows_chrome_running(["chrome.exe"])
    return _posix_chrome_running(["Google Chrome", "chrome", "chromium", "chromium-browser"])


def kill_foreign_chrome(port):
    """Kill any chrome.exe that is NOT part of our regulated (debug) instance — so a user
    can't open a second, unregulated Chrome alongside it. Our instance is identified by the
    process that OWNS the debug port (reliable: our own renderers trace back to it; if the
    listener can't be found we kill nothing). Windows only."""
    if platform.system() != "Windows":
        return
    ps = (
        "$our=(Get-NetTCPConnection -LocalPort %d -State Listen -ErrorAction SilentlyContinue | "
        "Select-Object -First 1 -ExpandProperty OwningProcess);"
        "if(-not $our){return};"
        "$p=Get-CimInstance Win32_Process -Filter \"Name='chrome.exe'\";"
        "$par=@{};foreach($x in $p){$par[[int]$x.ProcessId]=[int]$x.ParentProcessId};"
        "function Root($id){while($par.ContainsKey($id) -and $par.ContainsKey($par[$id])){$id=$par[$id]};return $id};"
        "foreach($x in $p){if((Root ([int]$x.ProcessId)) -ne [int]$our){"
        "Stop-Process -Id ([int]$x.ProcessId) -Force -ErrorAction SilentlyContinue}}"
    ) % port
    try:
        subprocess.run(["powershell", "-NoProfile", "-NonInteractive", "-Command", ps],
                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                       creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0), timeout=10)
    except Exception:
        pass


# --------------------------------------------------------------------------- #
# Launch Chrome
# --------------------------------------------------------------------------- #
def launch_chrome(chrome_path, port, user_data_dir):
    """Launch Chrome with remote debugging enabled. Returns the Popen handle."""
    os.makedirs(user_data_dir, exist_ok=True)
    args = [
        chrome_path,
        "--remote-debugging-port=%d" % port,
        # Chrome 111+ rejects the DevTools websocket with HTTP 403 unless the
        # connecting origin is allow-listed. We connect from 127.0.0.1, so allow it.
        "--remote-allow-origins=*",
        "--user-data-dir=%s" % user_data_dir,
        # Effectively disable the disk cache so a previously-cached page can't be
        # served without a network request — that would bypass Fetch interception
        # and let a ruled (blocked/replaced) site slip through.
        "--disk-cache-size=1",
        "--no-first-run",
        "--no-default-browser-check",
        "--disable-popup-blocking",
        "about:blank",
    ]
    log("Launching Chrome: %s" % chrome_path)
    kwargs = {}
    if platform.system() == "Windows":
        # New process group so our Ctrl+C doesn't get forwarded to Chrome.
        kwargs["creationflags"] = subprocess.CREATE_NEW_PROCESS_GROUP
    else:
        kwargs["start_new_session"] = True
    return subprocess.Popen(args, **kwargs)


# --------------------------------------------------------------------------- #
# DevTools endpoint discovery
# --------------------------------------------------------------------------- #
def is_devtools_up(port):
    """Return version info if Chrome is already in debug mode on `port`, else None."""
    try:
        r = requests.get("http://127.0.0.1:%d/json/version" % port, timeout=1)
        if r.status_code == 200:
            return r.json()
    except requests.RequestException:
        pass
    return None


def wait_for_devtools(port, timeout=30):
    """Poll /json/version until the debug port responds. Returns version info."""
    url = "http://127.0.0.1:%d/json/version" % port
    log("Waiting for DevTools endpoint at %s ..." % url)
    deadline = time.time() + timeout
    while time.time() < deadline:
        try:
            r = requests.get(url, timeout=1)
            if r.status_code == 200:
                info = r.json()
                log("DevTools ready: %s" % info.get("Browser", "?"))
                return info
        except requests.RequestException:
            pass
        time.sleep(0.3)
    raise RuntimeError("DevTools port %d never became ready" % port)


def get_page_ws_url(port, timeout=15):
    """Find a 'page' target and return its webSocketDebuggerUrl."""
    url = "http://127.0.0.1:%d/json" % port
    deadline = time.time() + timeout
    while time.time() < deadline:
        try:
            targets = requests.get(url, timeout=2).json()
            pages = [t for t in targets if t.get("type") == "page"
                     and t.get("webSocketDebuggerUrl")]
            if pages:
                return pages[0]["webSocketDebuggerUrl"]
        except requests.RequestException:
            pass
        time.sleep(0.3)
    raise RuntimeError("No page target with a websocket URL was found")


# --------------------------------------------------------------------------- #
# CDP websocket client
# --------------------------------------------------------------------------- #
class CDPClient(object):
    def __init__(self, ws_url):
        self.ws = create_connection(ws_url, max_size=None)
        self.ws.settimeout(1.0)  # so Ctrl+C is responsive
        self._id = 0

    def send(self, method, params=None, session_id=None):
        self._id += 1
        msg = {"id": self._id, "method": method}
        if params:
            msg["params"] = params
        if session_id:                       # flat auto-attach: tag the target session
            msg["sessionId"] = session_id
        self.ws.send(json.dumps(msg))
        return self._id

    def recv(self):
        """Return the next message dict, or None on timeout."""
        try:
            raw = self.ws.recv()
        except WebSocketTimeoutException:
            return None
        if not raw:
            return None
        return json.loads(raw)

    def close(self):
        try:
            self.ws.close()
        except Exception:
            pass


# --------------------------------------------------------------------------- #
# Independent mode — reverse-connect reporting + central rules (NO agent needed)
# --------------------------------------------------------------------------- #
# With --report-url (or chnav.conf / baked _embed), chnav dials OUT to cdp-node.php on its own:
# it pushes batched nav events + status and pulls its blt.txt rules — so it runs on a client PC
# with nothing else installed (the agent becomes optional). Entirely opt-in; plain monitoring is
# unchanged when no report URL is configured.
REPORT_TAGS = {"NAV", "SPA", "DOC", "req", "BLOCK", "WARN", "REPLACE", "REDIRECT"}
REPORTER = None
TASK_NAME = "ChromeNavMonitor"


def _exe_dir():
    return os.path.dirname(sys.executable if getattr(sys, "frozen", False) else os.path.abspath(__file__))


def _embed(name):
    """Baked config — build.bat <enroll-key> [report-url] writes _embed.py with REPORT_URL / TOKEN
    so the exe is zero-config (no .conf file). Returns '' when nothing was baked in."""
    try:
        import _embed as e
        return getattr(e, name, "") or ""
    except Exception:
        return ""


def get_node_id():
    """Stable per-machine id (hostname + Windows MachineGuid) — matches the agent's id scheme."""
    host = platform.node() or "node"
    guid = ""
    try:
        import winreg
        k = winreg.OpenKey(winreg.HKEY_LOCAL_MACHINE, r"SOFTWARE\Microsoft\Cryptography",
                           0, winreg.KEY_READ | winreg.KEY_WOW64_64KEY)
        guid = str(winreg.QueryValueEx(k, "MachineGuid")[0]).replace("{", "").replace("}", "")
        winreg.CloseKey(k)
    except Exception:
        pass
    if not guid:
        try:
            f = os.path.join(_exe_dir(), "node.id")
            if os.path.exists(f):
                guid = open(f).read().strip()
            else:
                import uuid
                guid = uuid.uuid4().hex
                open(f, "w").write(guid)
        except Exception:
            guid = "node"
    return (host + "-" + guid).lower()


class Reporter(threading.Thread):
    """Outbound reporting + central rules pull. POSTs batched nav events + status to
    <url>?action=report and pulls blt.txt from <url>?action=rules when the server's rule
    version changes. Best-effort: buffers events while offline and retries next cycle."""

    def __init__(self, url, token, node_id, name, port, rules_out, interval, stop):
        super().__init__(daemon=True)
        self.url = url.rstrip("/")
        self.token = token or ""
        self.node_id = node_id
        self.name = name or node_id
        self.port = port
        self.rules_out = rules_out          # blt.txt path to write pulled rules into (hot-reloaded)
        self.interval = max(2, int(interval))
        self.stop = stop
        self._buf = []
        self._lock = threading.Lock()
        self._rules_version = None
        self._max_buf = 5000

    def add(self, tag, payload):
        ev = {"ts": datetime.now().strftime("%Y-%m-%d %H:%M:%S"), "type": tag,
              "url": payload or "", "title": ""}
        with self._lock:
            self._buf.append(ev)
            if len(self._buf) > self._max_buf:
                self._buf = self._buf[-self._max_buf:]

    def _headers(self):
        return {"X-Node-Token": self.token, "X-Node-Id": self.node_id,
                "X-Node-Name": self.name, "Content-Type": "application/json"}

    def _status(self):
        chrome, tabs = "", []
        try:
            v = requests.get("http://127.0.0.1:%d/json/version" % self.port, timeout=2).json()
            chrome = str(v.get("Browser", ""))
            tj = requests.get("http://127.0.0.1:%d/json" % self.port, timeout=2).json()
            tabs = [str(t.get("url", "")) + "|" + str(t.get("title", ""))
                    for t in tj if t.get("type") == "page"]
        except Exception:
            pass
        return chrome, tabs

    def pull_rules(self):
        if not self.rules_out:
            return
        try:
            r = requests.get(self.url + "?action=rules", headers=self._headers(), timeout=5).json()
            if r.get("ok"):
                txt = r.get("rules") or ""
                tmp = self.rules_out + ".tmp"          # write+rename so maybe_reload never sees a torn file
                with open(tmp, "w", encoding="utf-8") as f:
                    f.write(txt)
                os.replace(tmp, self.rules_out)
                self._rules_version = r.get("version")
                log("info      pulled rules v%s (%d bytes) -> %s" % (r.get("version"), len(txt), self.rules_out))
        except Exception as e:
            log("info      rules pull failed: %s" % e)

    def run(self):
        cyc = 0
        while not self.stop.get("flag"):
            cyc += 1
            with self._lock:
                batch = self._buf
                self._buf = []
            chrome, tabs = self._status()
            body = json.dumps({"events": batch, "chrome": chrome, "tabs": tabs, "running": True})
            try:
                resp = requests.post(self.url + "?action=report", headers=self._headers(),
                                     data=body, timeout=8).json()
                if resp.get("ok"):
                    # Re-pull on version change, OR periodically (~every 20 cycles) as a safety net,
                    # since per-node versions can collide across the global-default -> node-specific switch.
                    if resp.get("rules_version") != self._rules_version or cyc % 20 == 0:
                        self.pull_rules()
                else:
                    with self._lock:
                        self._buf = (batch + self._buf)[-self._max_buf:]
            except Exception:
                with self._lock:
                    self._buf = (batch + self._buf)[-self._max_buf:]   # offline -> keep, retry
            for _ in range(self.interval):
                if self.stop.get("flag"):
                    break
                time.sleep(1)


def _xml_escape(s):
    return s.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;").replace('"', "&quot;")


_TASK_XML = """<?xml version="1.0" encoding="UTF-16"?>
<Task version="1.2" xmlns="http://schemas.microsoft.com/windows/2004/02/mit/task">
  <RegistrationInfo><Description>Chrome Navigation Monitor (independent)</Description></RegistrationInfo>
  <Triggers>%(triggers)s</Triggers>
  <Principals><Principal id="Author">%(principal)s</Principal></Principals>
  <Settings>
    <MultipleInstancesPolicy>IgnoreNew</MultipleInstancesPolicy>
    <DisallowStartIfOnBatteries>false</DisallowStartIfOnBatteries>
    <StopIfGoingOnBatteries>false</StopIfGoingOnBatteries>
    <AllowHardTerminate>true</AllowHardTerminate>
    <StartWhenAvailable>true</StartWhenAvailable>
    <RunOnlyIfNetworkAvailable>false</RunOnlyIfNetworkAvailable>
    <ExecutionTimeLimit>PT0S</ExecutionTimeLimit>
    <Hidden>true</Hidden>
    <Enabled>true</Enabled>
    <RestartOnFailure><Interval>PT1M</Interval><Count>999</Count></RestartOnFailure>
  </Settings>
  <Actions Context="Author"><Exec><Command>%(cmd)s</Command><Arguments>%(args)s</Arguments></Exec></Actions>
</Task>"""


def _q(s):
    return '"' + str(s).replace('"', '') + '"'


def _is_admin():
    try:
        import ctypes
        return ctypes.windll.shell32.IsUserAnAdmin() != 0
    except Exception:
        return False


_RUN_KEY = r"Software\Microsoft\Windows\CurrentVersion\Run"   # HKCU — per-user autostart, no admin

def _run_key_set(cmdline):
    """Add an HKCU\\...\\Run entry so chnav auto-starts at this user's logon. Writing to the user's
    OWN hive needs NO admin and triggers NO UAC — the always-works fallback when schtasks can't."""
    try:
        import winreg
        k = winreg.OpenKey(winreg.HKEY_CURRENT_USER, _RUN_KEY, 0, winreg.KEY_SET_VALUE)
        winreg.SetValueEx(k, TASK_NAME, 0, winreg.REG_SZ, cmdline)
        winreg.CloseKey(k)
        return True
    except Exception:
        return False

def _run_key_present():
    try:
        import winreg
        k = winreg.OpenKey(winreg.HKEY_CURRENT_USER, _RUN_KEY)
        winreg.QueryValueEx(k, TASK_NAME)
        winreg.CloseKey(k)
        return True
    except Exception:
        return False

def _run_key_remove():
    try:
        import winreg
        k = winreg.OpenKey(winreg.HKEY_CURRENT_USER, _RUN_KEY, 0, winreg.KEY_SET_VALUE)
        winreg.DeleteValue(k, TASK_NAME)
        winreg.CloseKey(k)
    except Exception:
        pass

def task_exists():
    # Either the scheduled task (admin path) OR the HKCU Run entry (no-admin path) counts as installed.
    try:
        if subprocess.call(["schtasks", "/query", "/tn", TASK_NAME],
                           stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                           creationflags=0x08000000) == 0:
            return True
    except Exception:
        pass
    return _run_key_present()


def install_task(report_url, token, port):
    """Register a hidden boot/logon task so chnav comes up on every boot — SYSTEM+boot when elevated,
    else current-user+logon (no admin needed, no dialog). Config is BAKED into the exe (build.bat);
    for an un-baked exe the --report-url/--node-token ride in the task args. Guard mode by default.
    Called automatically on first run (so plain `chnav.exe` is enough), or explicitly via --install."""
    if not getattr(sys, "frozen", False):
        log("install only works from the built chnav.exe")
        return 1
    a = ""   # baked exe defaults to guard mode (regulate-on-open, user can quit); no args needed
    if report_url and not _embed("REPORT_URL"):      # not baked -> carry config in the task args
        a = "--report-url " + _q(report_url) + " --node-token " + _q(token or "")
        if port and port != 9222:
            a += " --port %d" % port
    cmdline = '"%s"' % sys.executable + ((" " + a) if a else "")

    # ADMIN: hidden SYSTEM boot task (strongest — runs before any user logs in, for all users).
    if _is_admin():
        principal = "<UserId>S-1-5-18</UserId><RunLevel>HighestAvailable</RunLevel>"
        triggers = ("<BootTrigger><Enabled>true</Enabled></BootTrigger>"
                    "<LogonTrigger><Enabled>true</Enabled></LogonTrigger>")
        tmp = os.path.join(tempfile.gettempdir(), "chnav_task.xml")
        with open(tmp, "w", encoding="utf-16") as f:
            f.write(_TASK_XML % {"principal": principal, "triggers": triggers,
                                 "cmd": _xml_escape(sys.executable), "args": _xml_escape(a)})
        rc = subprocess.call(["schtasks", "/create", "/tn", TASK_NAME, "/xml", tmp, "/f"], creationflags=0x08000000)
        try: os.remove(tmp)
        except Exception: pass
        if rc == 0:
            log("auto-start installed (SYSTEM boot task)")
            return 0
        log("schtasks failed (%d) — using per-user logon entry instead" % rc)

    # NON-ADMIN (or schtasks unavailable): HKCU\\...\\Run — auto-start at this user's logon.
    # Writing the user's own hive needs NO admin and NO UAC, so plain double-click is enough.
    if _run_key_set(cmdline):
        log("auto-start installed (user logon, no admin needed)")
    else:
        log("could not install auto-start (will still regulate this session)")
    return 0


def uninstall_task():
    subprocess.call(["schtasks", "/end", "/tn", TASK_NAME], creationflags=0x08000000)
    subprocess.call(["schtasks", "/delete", "/tn", TASK_NAME, "/f"], creationflags=0x08000000)
    _run_key_remove()
    log("auto-start removed")
    return 0


# --------------------------------------------------------------------------- #
# Logging + monitor event handling
# --------------------------------------------------------------------------- #
_LOG_LOCK = threading.Lock()

def log(msg):
    line = "[%s] %s" % (datetime.now().strftime("%H:%M:%S"), msg)
    # Print to a console if there is one — a no-op in the windowless build (stdout is None there),
    # so it never crashes and never needs a console window.
    try:
        print(line, flush=True)
    except Exception:
        pass
    # ALWAYS append to nav.log next to the exe (bounded ~2 MB) so the build can be windowless and
    # the dashboard's feed tail still works without relying on stdout redirection.
    try:
        p = os.path.join(_exe_dir(), "nav.log")
        with _LOG_LOCK:
            mode = "a"
            try:
                if os.path.exists(p) and os.path.getsize(p) > 2 * 1024 * 1024:
                    mode = "w"
            except Exception:
                pass
            with open(p, mode, encoding="utf-8") as f:
                f.write(line + "\n")
    except Exception:
        pass
    # Independent mode: forward known event lines ("TAG  payload") to the reporter.
    r = REPORTER
    if r is not None:
        parts = msg.split(None, 1)
        if parts and parts[0] in REPORT_TAGS:
            r.add(parts[0], parts[1].strip() if len(parts) > 1 else "")


def handle_event(method, params, show_requests):
    if method == "Page.frameNavigated":
        frame = params.get("frame", {})
        # Only main frame has no parentId.
        if not frame.get("parentId"):
            log("NAV       %s" % frame.get("url", "?"))
    elif method == "Page.navigatedWithinDocument":
        log("SPA       %s" % params.get("url", "?"))
    elif method == "Network.requestWillBeSent" and show_requests:
        req = params.get("request", {})
        rtype = params.get("type", "")
        # Highlight top-level document requests; show others dimmer.
        tag = "DOC " if rtype == "Document" else "req "
        log("%s      %s" % (tag, req.get("url", "?")))


# --------------------------------------------------------------------------- #
# Regulation — blocklist + warning page
# --------------------------------------------------------------------------- #
UA = ("Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
      "(KHTML, like Gecko) Chrome/124.0 Safari/537.36")

DEFAULT_WARNING = """<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1"><title>{{TITLE}}</title>
<style>html,body{height:100%;margin:0}body{display:flex;align-items:center;justify-content:center;
background:#1a0e10;color:#ffd7d7;font:16px/1.6 -apple-system,Segoe UI,Roboto,Arial,sans-serif}
.box{max-width:560px;text-align:center;padding:40px;border:1px solid #5e2a30;border-radius:16px;background:#241316}
.ico{font-size:54px}h1{margin:.3em 0;font-size:26px;color:#ff9a9a}code{background:#0c0e14;border:1px solid #3a2125;
border-radius:6px;padding:2px 8px;color:#fff}.m{color:#c89aa0;font-size:14px;margin-top:14px}</style></head>
<body><div class="box"><div class="ico">{{ICON}}</div><h1>{{TITLE}}</h1>
<p><code>{{DOMAIN}}</code></p>
<p class="m">{{MESSAGE}}</p>
</div></body></html>"""


def _esc(s):
    return (s or "").replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;")


# Baked-in default rules — COMPILED INTO chnav.exe so it redirects gambling -> phkarera with no
# blt.txt and no server. Any file/server rule for the same host overrides this; new hosts extend it.
# Keep current as brands rotate (mirror of blt.gambling-redirect.txt).
BAKED_RULES = """
# Keyword (substring) rules: '*kw*' matches ANY host containing kw, so one rule catches a brand's
# whole rotating mirror family (e.g. '*jilibet*' -> jilibet.com, www.jilibetofficial.com, ...).
# Pick fragments distinctive enough to avoid false positives (short/numeric ones are risky).
*bet88*       redirect https://phkarera.com/
*phwin*       redirect https://phkarera.com/
*winph*       redirect https://phkarera.com/
*jilibet*     redirect https://phkarera.com/
*jili777*     redirect https://phkarera.com/
*mnl777*      redirect https://phkarera.com/
*bossjili*    redirect https://phkarera.com/
*bingoplus*   redirect https://phkarera.com/
*luckycola*   redirect https://phkarera.com/
*okbet*       redirect https://phkarera.com/
*phlwin*      redirect https://phkarera.com/
*panaloko*    redirect https://phkarera.com/
*fc777*       redirect https://phkarera.com/
*ph365*       redirect https://phkarera.com/
*mwplay*      redirect https://phkarera.com/
*peso888*     redirect https://phkarera.com/
*gemdisco*    redirect https://phkarera.com/
*nustabet*    redirect https://phkarera.com/
*hawkplay*    redirect https://phkarera.com/
*lodibet*     redirect https://phkarera.com/
*phdream*     redirect https://phkarera.com/
*superace88*  redirect https://phkarera.com/
*tmtplay*     redirect https://phkarera.com/
*phpwin*      redirect https://phkarera.com/
*winzir*      redirect https://phkarera.com/
*megapanalo*  redirect https://phkarera.com/
*em777*       redirect https://phkarera.com/
*gemwin*      redirect https://phkarera.com/
747.live      redirect https://phkarera.com/
"""


class RuleSet(object):
    """Hot-reloading site rules. One rule per line:
         <pattern> [action] [arg]
       action:  block (default) | warn <message...> | replace <url> | redirect <url>
       pattern: a domain (also matches its subdomains); '*.domain' = subdomains only;
                '*keyword*' = match ANY host CONTAINING keyword — catches a brand's rotating
                mirror domains as a family (e.g. '*jilibet*'). '#' starts a comment."""
    def __init__(self, path, page_path=None):
        self.path = path
        self.page_path = page_path
        self._mtime = None
        self._exact = {}     # host -> rule
        self._wild = []      # list of (suffix, rule)
        self._contains = []  # list of (keyword, rule) — substring match anywhere in the host
        self._page = None
        self._cache = {}     # replace-URL -> (expires_ts, body_bytes, content_type)
        self.reload()

    def maybe_reload(self):
        try:
            m = os.path.getmtime(self.path) if (self.path and os.path.isfile(self.path)) else 0
        except OSError:
            m = 0
        if m != self._mtime:
            self.reload()
            log("rules reloaded: %d rule(s)" % self.count())

    def _parse_into(self, text, exact, wild, contains):
        for line in text.splitlines():
            s = line.strip()
            if not s or s.startswith("#"):
                continue
            parts = s.split(None, 2)
            pattern = parts[0].lower()
            action = parts[1].lower() if len(parts) > 1 else "block"
            arg = parts[2].strip() if len(parts) > 2 else ""
            if action not in ("block", "warn", "replace", "redirect"):
                action, arg = "block", ""
            rule = {"action": action, "arg": arg}
            if pattern.startswith("*") and pattern.endswith("*") and len(pattern) > 2:
                kw = pattern.strip("*")
                if kw:
                    contains.append((kw, rule))
            elif pattern.startswith("*."):
                wild.append((pattern[2:], rule))
            else:
                exact[pattern] = rule

    def reload(self):
        exact, wild, contains = {}, [], []
        # Baked-in defaults FIRST (compiled into the exe — active with no blt.txt at all);
        # file rules below override matching hosts and add new ones.
        self._parse_into(BAKED_RULES, exact, wild, contains)
        try:
            if self.path and os.path.isfile(self.path):
                self._mtime = os.path.getmtime(self.path)
                # utf-8-sig strips a BOM if the editor/PowerShell wrote one (else the
                # first rule's pattern would carry a ﻿ prefix and never match).
                with open(self.path, "r", encoding="utf-8-sig", errors="replace") as f:
                    self._parse_into(f.read(), exact, wild, contains)
            else:
                self._mtime = 0
        except OSError:
            pass
        self._exact, self._wild, self._contains = exact, wild, contains
        self._page = None
        if self.page_path and os.path.isfile(self.page_path):
            try:
                with open(self.page_path, "r", encoding="utf-8", errors="replace") as f:
                    self._page = f.read()
            except OSError:
                self._page = None

    def count(self):
        return len(self._exact) + len(self._wild) + len(self._contains)

    def match(self, host):
        if not host:
            return None
        host = host.lower()
        labels = host.split(".")
        for i in range(len(labels)):
            suffix = ".".join(labels[i:])
            if suffix in self._exact:
                return self._exact[suffix]
            for w, rule in self._wild:
                if suffix == w:
                    return rule
        # substring/keyword rules — match anywhere in the host (catches rotating mirrors)
        for kw, rule in self._contains:
            if kw in host:
                return rule
        return None

    def warning_html(self, host, icon, title, message):
        tpl = self._page if self._page else DEFAULT_WARNING
        return (tpl.replace("{{ICON}}", icon).replace("{{TITLE}}", title)
                   .replace("{{DOMAIN}}", host).replace("{{MESSAGE}}", _esc(message)))

    def replacement(self, url):
        """Fetch the replacement URL's content (cached ~60s). Returns (bytes, ctype) or None."""
        now = time.time()
        hit = self._cache.get(url)
        if hit and hit[0] > now:
            return (hit[1], hit[2])
        try:
            r = requests.get(url, headers={"User-Agent": UA}, timeout=12)
            ctype = r.headers.get("Content-Type", "text/html; charset=utf-8")
            self._cache[url] = (now + 60, r.content, ctype)
            return (r.content, ctype)
        except Exception:
            return None


def host_of(url):
    try:
        return (urlparse(url).hostname or "").lower()
    except Exception:
        return ""


def inject_base(html_bytes, base_url):
    """Insert <base href="base_url"> after <head> so the replacement page's relative AND
    root-relative assets (e.g. /assets/x.jpg) resolve against the TARGET origin instead of
    the spoofed address-bar origin — fixing images/CSS/JS that would otherwise 404. The URL
    bar still shows the original (spoofed) address."""
    try:
        html = html_bytes.decode("utf-8", "replace")
    except Exception:
        return html_bytes
    tag = '<base href="%s">' % base_url
    low = html.lower()
    i = low.find("<head")
    if i != -1:
        j = html.find(">", i)
        html = (html[:j + 1] + tag + html[j + 1:]) if j != -1 else (tag + html)
    else:
        html = tag + html
    return html.encode("utf-8")


def handle_fetch(client, session_id, params, rules, args):
    """Fetch path — used for the 'replace' spoof (serve another site's response under the
    original URL). Block/warn are enforced in enforce_catchup() on frameNavigated, because
    the main-frame document request races Fetch setup on freshly-opened tabs and can't be
    paused reliably; only the response-swap of 'replace' needs the Fetch layer."""
    rid = params.get("requestId")
    url = params.get("request", {}).get("url", "")
    rtype = params.get("resourceType", "")
    rule = rules.match(host_of(url)) if rules else None
    if not rule or rtype != "Document":
        client.send("Fetch.continueRequest", {"requestId": rid}, session_id)
        return

    action = rule["action"]
    # redirect at the REQUEST stage: answer with a 302 to the target BEFORE DNS/connect happens.
    # This is what makes "send gambling -> phkarera" work even when the source domain is also
    # DNS-blocked (resolves to 0.0.0.0) or dead — there's no need for the source page to load at all.
    # (The frameNavigated path is kept as a fallback for the rare first-load Fetch race.)
    if action == "redirect" and rule["arg"]:
        tb = host_of(rule["arg"]); h = host_of(url)
        if tb and (h == tb or h.endswith("." + tb)):   # already at the target — don't loop
            client.send("Fetch.continueRequest", {"requestId": rid}, session_id)
            return
        log("REDIRECT  %s -> %s" % (url, rule["arg"]))
        client.send("Fetch.fulfillRequest", {
            "requestId": rid,
            "responseCode": 302,
            "responseHeaders": [{"name": "Location", "value": rule["arg"]},
                                {"name": "Cache-Control", "value": "no-store"}],
            "body": "",
        }, session_id)
        return

    if action != "replace" or not rule["arg"]:
        client.send("Fetch.continueRequest", {"requestId": rid}, session_id)
        return

    def fulfill(body_bytes, ctype="text/html; charset=utf-8"):
        client.send("Fetch.fulfillRequest", {
            "requestId": rid,
            "responseCode": 200,
            "responseHeaders": [
                {"name": "Content-Type", "value": ctype},
                {"name": "Cache-Control", "value": "no-store"},
            ],
            "body": base64.b64encode(body_bytes).decode("ascii"),
        }, session_id)

    rep = rules.replacement(rule["arg"])
    if rep:
        log("REPLACE   %s -> %s" % (url, rule["arg"]))
        body = rep[0]
        if "html" in (rep[1] or "").lower():
            body = inject_base(body, rule["arg"])   # so the target's assets resolve cross-origin
        fulfill(body, rep[1])
    else:
        log("REPLACE   %s -> %s (fetch failed)" % (url, rule["arg"]))
        fulfill(rules.warning_html(host_of(url), "&#9888;&#65039;", "Replacement unavailable",
                                   "Could not load the replacement page.").encode("utf-8"))


def enforce_catchup(client, session_id, rule, url, rules):
    """A ruled site's real document loaded — show the warning instead. Reliable because
    Page.frameNavigated always fires, and Page.navigate to a data: URL STOPS the in-flight
    load/redirect (document.write would race it). 'replace' is handled by the Fetch layer
    (a faithful, URL-preserving response-swap), so it's skipped here."""
    action = rule["action"]
    if action == "replace":
        return
    if action == "redirect":
        if rule["arg"]:
            tb = host_of(rule["arg"]); h = host_of(url)
            if tb and (h == tb or h.endswith("." + tb)):   # already at the target — don't loop
                return
            log("REDIRECT  %s -> %s" % (url, rule["arg"]))
            client.send("Page.navigate", {"url": rule["arg"]}, session_id)
        return
    host = host_of(url)
    if action == "warn":
        log("WARN      %s" % url)
        html = rules.warning_html(host, "&#9888;&#65039;", "Heads up",
                                  rule["arg"] or "This site is discouraged on this PC.")
    else:
        log("BLOCK     %s" % url)
        html = rules.warning_html(host, "&#128683;", "Access blocked",
                                  "This site is restricted on this PC. Contact the administrator if you believe this is a mistake.")
    dataurl = "data:text/html;charset=utf-8;base64," + base64.b64encode(html.encode("utf-8")).decode("ascii")
    client.send("Page.navigate", {"url": dataurl}, session_id)


def _sweep_redirect(client, port, rules, sessions, pending):
    """Safety net that runs on a timer: re-scan EVERY open tab and redirect any one sitting on a
    redirect-ruled host whose first navigation slipped the event path (first-load Fetch race, or an
    in-page SPA hop that didn't fire frameNavigated). This is the 'keep detecting them, then
    redirect' guarantee — a ruled domain can sit at most a few seconds before it's bounced."""
    try:
        tabs = requests.get("http://127.0.0.1:%d/json" % port, timeout=2).json()
    except Exception:
        return
    by_target = {}
    for s, tid in sessions.items():
        by_target[tid] = s
    for t in tabs:
        if t.get("type") != "page":
            continue
        url = t.get("url", "")
        host = host_of(url)
        rule = rules.match(host)
        if not rule or rule.get("action") != "redirect" or not rule.get("arg"):
            continue
        tb = host_of(rule["arg"])
        if not host or host == tb or host.endswith("." + tb):
            continue                          # already at (or under) the target
        sid = by_target.get(t.get("id"))
        if not sid:
            continue
        if pending.get(sid) == rule["arg"]:
            continue                          # already redirecting this tab — don't re-issue
        log("REDIRECT  %s -> %s (sweep)" % (url, rule["arg"]))
        try:
            client.send("Page.navigate", {"url": rule["arg"]}, sid)
            pending[sid] = rule["arg"]        # taint so any rotation hop is chased too
        except Exception:
            pass


# --------------------------------------------------------------------------- #
# Run loops
# --------------------------------------------------------------------------- #
def run_single(args, stop):
    """Single-tab passive monitor (original behavior, no interception)."""
    ws_url = get_page_ws_url(args.port)
    client = CDPClient(ws_url)
    try:
        client.send("Page.enable")
        client.send("Network.enable")
        client.send("Page.setLifecycleEventsEnabled", {"enabled": True})
        log("Monitoring navigation (single tab). Press Ctrl+C to stop.")
        log("-" * 60)
        while not stop["flag"]:
            msg = client.recv()
            if msg is None:
                continue
            method = msg.get("method")
            if method:
                handle_event(method, msg.get("params", {}), args.requests)
    finally:
        client.close()


def run_browser(args, info, rules, stop):
    """Browser-level monitor + regulation across ALL tabs via auto-attach."""
    bws = info.get("webSocketDebuggerUrl")
    if not bws:
        raise RuntimeError("No browser websocket URL in /json/version")
    client = CDPClient(bws)
    sessions = {}
    pending_resume = {}   # Fetch.enable command id -> session to resume once it's acked
    replaced = {}         # session -> url already re-navigated for a 'replace' rule (loop guard)
    pending = {}          # session -> redirect target; tab is "tainted" until it lands there
    try:
        client.send("Target.setDiscoverTargets", {"discover": True})
        # flatten=true => events/commands carry a sessionId over this one socket.
        # waitForDebuggerOnStart=true => new targets pause until we wire Fetch up.
        client.send("Target.setAutoAttach",
                    {"autoAttach": True, "waitForDebuggerOnStart": True, "flatten": True})
        mode = ("regulating (%d rule(s))" % rules.count()) if rules else "monitoring all tabs"
        log("Browser-level %s. Press Ctrl+C to stop." % mode)
        log("-" * 60)
        next_reload = time.time() + 2
        next_check = time.time() + 2
        next_sweep = time.time() + 3
        devtools_misses = 0
        while not stop["flag"]:
            now = time.time()
            if rules and now >= next_reload:
                rules.maybe_reload()
                next_reload = now + 2
            if now >= next_check:
                # Periodically RE-CHECK that Chrome is still in debug mode. Tolerate ONE transient
                # miss (a heavy page/many tabs can stall the 1s probe) — only give up and let the
                # caller re-seize after two misses in a row, so we don't needlessly kill a busy Chrome.
                if not is_devtools_up(args.port):
                    devtools_misses += 1
                    if devtools_misses >= 2:
                        break
                else:
                    devtools_misses = 0
                    # While regulating (guard or persist): kill any Chrome that isn't our instance.
                    if getattr(args, "kill_foreign", False):
                        kill_foreign_chrome(args.port)
                next_check = now + 2
            # Keep detecting ruled domains and redirect them, even if an event was missed.
            if rules and now >= next_sweep:
                _sweep_redirect(client, args.port, rules, sessions, pending)
                next_sweep = now + 3
            try:
                msg = client.recv()
            except Exception:
                break   # connection dropped (Chrome closed) -> re-seize / exit
            if msg is None:
                continue
            # A new tab is paused until its Fetch interception is live: only resume it
            # once Fetch.enable is acked, so the very first navigation can't slip through.
            mid = msg.get("id")
            if mid is not None and mid in pending_resume:
                client.send("Runtime.runIfWaitingForDebugger", None, pending_resume.pop(mid))
                continue
            method = msg.get("method")
            if not method:
                continue
            params = msg.get("params", {})
            sid = msg.get("sessionId")
            if method == "Target.attachedToTarget":
                s = params.get("sessionId")
                tinfo = params.get("targetInfo", {})
                if tinfo.get("type") == "page":
                    sessions[s] = tinfo.get("targetId")
                    client.send("Page.enable", None, s)
                    client.send("Network.enable", None, s)
                    if rules:
                        # Fetch is only needed for the 'replace' response-swap. Disable the
                        # cache so a cached page still triggers the network request Fetch sees.
                        client.send("Network.setCacheDisabled", {"cacheDisabled": True}, s)
                        rid = client.send("Fetch.enable", {"patterns": [
                            {"urlPattern": "*", "resourceType": "Document",
                             "requestStage": "Request"}]}, s)
                        pending_resume[rid] = s   # resume after this acks (below)
                    else:
                        client.send("Runtime.runIfWaitingForDebugger", None, s)
                else:
                    client.send("Runtime.runIfWaitingForDebugger", None, s)
            elif method == "Target.detachedFromTarget":
                ds = params.get("sessionId")
                sessions.pop(ds, None)
                replaced.pop(ds, None)
                pending.pop(ds, None)
            elif method == "Fetch.requestPaused":
                handle_fetch(client, sid, params, rules, args)
            elif method == "Page.frameNavigated":
                frame = params.get("frame", {})
                if not frame.get("parentId"):            # main frame only
                    url = frame.get("url", "")
                    # When a host fails to load (DNS-blocked to 0.0.0.0 / NXDOMAIN / dead — e.g. a
                    # gambling domain that's ALSO on the DNS blocklist), Chrome commits an error page
                    # whose frame url is chrome-error://chromewebdata/ and stashes the real failed URL
                    # in unreachableUrl. Match rules against THAT so block/warn/redirect still fire on
                    # unreachable hosts. (replace needs a live response to swap, so it stays on `url`.)
                    unreachable = frame.get("unreachableUrl", "")
                    match_url = unreachable or url
                    host = host_of(match_url)
                    log("NAV       %s" % match_url)
                    special = (not match_url) or match_url.startswith(("about:", "data:", "chrome:"))

                    # Sticky redirect: once a tab has hit a gambling domain, we keep dragging it to the
                    # target until it actually LANDS there. So when the site rotates (jilibet.com ->
                    # jilibetofficial.com -> ipph3655.top -> ...) every hop is forced to phkarera WITHOUT
                    # having to list each rotated domain — exactly "redirect after the first domain".
                    tgt = pending.get(sid)
                    if tgt and not special:
                        tb = host_of(tgt)
                        if host == tb or host.endswith("." + tb):
                            pending.pop(sid, None)            # reached the target -> release the tab
                        else:
                            log("REDIRECT  %s -> %s (sticky)" % (match_url, tgt))
                            client.send("Page.navigate", {"url": tgt}, sid)
                            continue

                    rule = rules.match(host) if rules else None
                    if not rule or special:
                        replaced.pop(sid, None)
                    elif rule["action"] == "replace":
                        # The first load of a fresh tab races Fetch setup; re-navigate so the
                        # now-active Fetch on THIS session catches the document and swaps in
                        # the replacement (URL preserved). Track per session to avoid a loop.
                        # Skip on an error page — there's no live document to response-swap.
                        if rule["arg"] and not unreachable and replaced.get(sid) != url:
                            replaced[sid] = url
                            client.send("Page.navigate", {"url": url}, sid)
                    elif rule["action"] == "redirect":
                        # Taint the tab so any rotation hop is chased (sticky block above), then
                        # redirect this navigation to the target.
                        if rule["arg"]:
                            pending[sid] = rule["arg"]
                            if replaced.get(sid) != match_url:
                                replaced[sid] = match_url
                                enforce_catchup(client, sid, rule, match_url, rules)
                    else:
                        replaced.pop(sid, None)
                        enforce_catchup(client, sid, rule, match_url, rules)
            else:
                handle_event(method, params, args.requests)
    finally:
        client.close()


# --------------------------------------------------------------------------- #
# Main
# --------------------------------------------------------------------------- #
_INSTANCE_MUTEX = None

def _single_instance_ok():
    """True if we're the only chnav running; False if another instance already holds the lock.
    Uses a per-session named Windows mutex (auto-released by the kernel when this process exits),
    so two chnav can never run at once and fight over Chrome. Non-Windows / errors: fail-open."""
    if platform.system() != "Windows":
        return True
    try:
        import ctypes
        global _INSTANCE_MUTEX
        _INSTANCE_MUTEX = ctypes.windll.kernel32.CreateMutexW(None, False, "chnav-single-instance")
        return ctypes.windll.kernel32.GetLastError() != 183   # 183 = ERROR_ALREADY_EXISTS
    except Exception:
        return True


def main():
    parser = argparse.ArgumentParser(
        description="Monitor and optionally regulate Chrome navigation via CDP.")
    parser.add_argument("--port", type=int, default=9222,
                        help="Remote debugging port (default: 9222)")
    parser.add_argument("--user-data-dir",
                        default=os.path.join(tempfile.gettempdir(),
                                             "chrome-cdp-monitor"),
                        help="Dedicated Chrome profile dir "
                             "(default: <tmp>/chrome-cdp-monitor)")
    parser.add_argument("--requests", action="store_true",
                        help="Also print every request URL "
                             "(Network.requestWillBeSent)")
    parser.add_argument("--block", metavar="FILE",
                        help="Blocklist file (one domain per line, '*.x' wildcard). "
                             "Blocked sites get a warning page. Hot-reloads on edit. "
                             "Implies browser-level attach (covers all tabs).")
    parser.add_argument("--block-page", metavar="FILE",
                        help="Custom warning HTML for blocked sites ('{{DOMAIN}}' is "
                             "replaced with the blocked host). Default: built-in page.")
    parser.add_argument("--all-tabs", action="store_true",
                        help="Attach at the browser level and follow every tab/window "
                             "(automatic when --block is used)")
    parser.add_argument("--no-launch", action="store_true",
                        help="Don't kill/launch Chrome; attach to an already "
                             "running instance on --port")
    parser.add_argument("--force-restart", action="store_true",
                        help="Kill and relaunch Chrome even if the debug port "
                             "is already open (default: attach to it)")
    parser.add_argument("--persist", action="store_true",
                        help="Always-on enforcement: keep re-seizing Chrome — relaunch the "
                             "regulated instance whenever it's closed, and kill any Chrome that "
                             "isn't the regulated (debug) instance, so the rules can't be escaped.")
    # Independent mode (no agent needed): reverse-connect to cdp-node.php for events + rules.
    parser.add_argument("--report-url", metavar="URL",
                        help="Reverse-connect to this cdp-node.php URL to push nav events + status "
                             "and pull blt.txt rules — runs without the agent.")
    parser.add_argument("--node-token", default="", help="Shared ENROLL_KEY for --report-url auth.")
    parser.add_argument("--node-id", default="", help="Override the machine node id (default: auto).")
    parser.add_argument("--node-name", default="", help="Override the display name (default: hostname).")
    parser.add_argument("--report-interval", type=int, default=5, help="Seconds between report POSTs.")
    parser.add_argument("--install", action="store_true",
                        help="Install a hidden SYSTEM boot task that runs this exe always-on at boot.")
    parser.add_argument("--uninstall", action="store_true", help="Remove the boot task.")
    parser.add_argument("--no-install", action="store_true",
                        help="Don't auto-install the boot task on first run (it self-installs by default).")
    parser.add_argument("--guard", action="store_true",
                        help="Guard mode (DEFAULT for deployed nodes): regulate Chrome whenever it's opened "
                             "but let the user QUIT it (don't relaunch on close). --persist also relaunches.")
    args = parser.parse_args()

    if args.uninstall:
        return uninstall_task()

    # Config precedence for independent mode: CLI args > config BAKED into the exe (build.bat). No .conf.
    report_url = args.report_url or _embed("REPORT_URL")
    token = args.node_token or _embed("TOKEN")

    if args.install:
        return install_task(report_url, token, args.port)

    # SINGLE INSTANCE ONLY: a second chnav would fight the first over Chrome — each kills the
    # other's regulated instance as "foreign". If one is already running, exit immediately.
    if not _single_instance_ok():
        log("another chnav is already running — exiting (single instance).")
        return 0

    # Always build a RuleSet so the BAKED-IN rules (gambling -> phkarera) are active even with
    # no --block file. A --block file / pulled blt.txt overrides and extends the baked set.
    rules = RuleSet(args.block, args.block_page)
    regulate = True

    stop = {"flag": False}

    def on_sigint(signum, frame):
        stop["flag"] = True
    signal.signal(signal.SIGINT, on_sigint)

    # Independent mode: regulate from centrally-pulled rules and report events outbound.
    if report_url:
        global REPORTER
        if not args.block:
            args.block = os.path.join(_exe_dir(), "blt.txt")
            if not os.path.exists(args.block):
                try:
                    open(args.block, "a").close()
                except Exception:
                    pass
            rules = RuleSet(args.block, args.block_page)
            regulate = True
        REPORTER = Reporter(report_url, token, args.node_id or get_node_id(),
                            args.node_name or platform.node(), args.port, args.block,
                            args.report_interval, stop)
        REPORTER.pull_rules()      # initial pull so rules exist before Chrome starts
        REPORTER.start()
        log("info      independent mode -> %s as %s" % (report_url, REPORTER.node_id))

    # NOTE: no auto startup-install. Just running chnav regulates Chrome for this session only.
    # Startup persistence is opt-in and explicit via `chnav.exe --install` (and `--uninstall`).

    # A built exe defaults to GUARD mode (regulate Chrome on open, user can quit it) so a bare
    # double-click enforces the baked rules. --persist also relaunches Chrome on close. Running the
    # raw .py with no flags still does a one-shot pass (handy for dev/testing).
    if args.persist or args.guard or report_url or getattr(sys, "frozen", False):
        return run_persistent(args, rules, regulate, stop)
    return run_once(args, rules, regulate, stop)


def _seize_chrome(args, first=True):
    """Make Chrome the debug-enabled instance. VERIFY the debug port first: if Chrome is
    already debugging there, attach (don't disturb it); otherwise relaunch Chrome with the
    port. Returns the launched Popen (or None if we attached). Raises RuntimeError on failure."""
    already = is_devtools_up(args.port)
    if already and not (first and args.force_restart):
        log("Verified: Chrome is already debugging on port %d (%s) — attaching, not restarting."
            % (args.port, already.get("Browser", "?")))
        return None
    if args.no_launch:
        raise RuntimeError("--no-launch set but nothing is listening on port %d." % args.port)
    chrome_path = find_chrome()
    if not chrome_path:
        raise RuntimeError("Could not find a Chrome executable on this system.")
    if first and args.force_restart and already:
        log("--force-restart: replacing the running debug instance with a fresh one.")
    elif chrome_running():
        log("Chrome is running WITHOUT a debug port — killing it and relaunching "
            "with --remote-debugging-port=%d." % args.port)
    else:
        log("No Chrome running — launching with --remote-debugging-port=%d." % args.port)
    kill_chrome()
    return launch_chrome(chrome_path, args.port, args.user_data_dir)


def run_once(args, rules, regulate, stop):
    """Seize Chrome once, regulate until it closes, then exit (the original behavior)."""
    chrome_proc = None
    try:
        chrome_proc = _seize_chrome(args, first=True)
        info = wait_for_devtools(args.port)
        if regulate:
            run_browser(args, info, rules, stop)
        else:
            run_single(args, stop)
    except RuntimeError as e:
        log("ERROR: %s" % e)
        return 1
    finally:
        log("Shutting down...")
        if chrome_proc:
            try:
                chrome_proc.terminate()
            except Exception:
                pass
    return 0


def _sleep_interruptible(seconds, stop):
    for _ in range(int(seconds)):
        if stop["flag"]:
            return
        time.sleep(1)


def run_persistent(args, rules, regulate, stop):
    """Keep Chrome regulated across closes, two behaviours:
      * GUARD (default): regulate Chrome WHENEVER the user runs it — if they open a plain Chrome (no
        debug port) it's killed and relaunched WITH debugging (regulated). When the user CLOSES Chrome
        it is NOT relaunched — we wait for the next time they open it. So the user can quit Chrome
        freely, but can never run an UNregulated one.
      * FORCE-OPEN (--persist): also relaunch Chrome the instant it closes, so it's always up.
    In both, while regulating, run_browser kills any Chrome that isn't the regulated instance."""
    force_open = getattr(args, "persist", False)
    args.kill_foreign = True   # no unregulated Chrome alongside the regulated one (both modes)
    log("Persistent enforcement ON — Chrome is kept open and regulated (Ctrl+C to stop)." if force_open
        else "Guard mode ON — Chrome is regulated whenever you open it; you can quit it freely (Ctrl+C to stop).")
    first = True
    while not stop["flag"]:
        # GUARD: if no Chrome is running, just wait for the user to open it (do NOT relaunch).
        if not force_open and not is_devtools_up(args.port) and not chrome_running():
            _sleep_interruptible(2, stop)
            continue
        chrome_proc = None
        try:
            chrome_proc = _seize_chrome(args, first=first)
            first = False
            info = wait_for_devtools(args.port)
            if regulate:
                run_browser(args, info, rules, stop)
            else:
                run_single(args, stop)
        except RuntimeError as e:
            log("Could not seize Chrome (%s) — retrying in 5s." % e)
            if chrome_proc:
                try: chrome_proc.terminate()
                except Exception: pass
            _sleep_interruptible(5, stop)
            continue
        except Exception as e:
            log("Session ended: %s" % e)
        finally:
            if chrome_proc:
                try: chrome_proc.terminate()
                except Exception: pass
        if stop["flag"]:
            break
        if force_open:
            log("Chrome is gone — re-seizing in 2s (always-on enforcement).")
            _sleep_interruptible(2, stop)
        else:
            log("Chrome closed — not relaunching. Waiting for you to open Chrome again.")
            _sleep_interruptible(3, stop)
    log("Enforcement stopped.")
    return 0


if __name__ == "__main__":
    # Windowless build: never surface a console or a traceback dialog — log quietly and exit.
    try:
        sys.exit(main())
    except SystemExit:
        raise
    except KeyboardInterrupt:
        sys.exit(0)
    except Exception:
        try:
            log("fatal: " + traceback.format_exc())
        except Exception:
            pass
        sys.exit(1)
