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
import time
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
                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
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
                             capture_output=True, text=True).stdout.lower()
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
# Logging + monitor event handling
# --------------------------------------------------------------------------- #
def log(msg):
    print("[%s] %s" % (datetime.now().strftime("%H:%M:%S"), msg), flush=True)


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


class RuleSet(object):
    """Hot-reloading site rules. One rule per line:
         <pattern> [action] [arg]
       action:  block (default) | warn <message...> | replace <url>
       pattern: a domain, or '*.domain' for subdomains; a bare domain also
                matches its subdomains. '#' starts a comment."""
    def __init__(self, path, page_path=None):
        self.path = path
        self.page_path = page_path
        self._mtime = None
        self._exact = {}     # host -> rule
        self._wild = []      # list of (suffix, rule)
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

    def reload(self):
        exact, wild = {}, []
        try:
            if self.path and os.path.isfile(self.path):
                self._mtime = os.path.getmtime(self.path)
                # utf-8-sig strips a BOM if the editor/PowerShell wrote one (else the
                # first rule's pattern would carry a ﻿ prefix and never match).
                with open(self.path, "r", encoding="utf-8-sig", errors="replace") as f:
                    for line in f:
                        s = line.strip()
                        if not s or s.startswith("#"):
                            continue
                        parts = s.split(None, 2)
                        pattern = parts[0].lower()
                        action = parts[1].lower() if len(parts) > 1 else "block"
                        arg = parts[2].strip() if len(parts) > 2 else ""
                        if action not in ("block", "warn", "replace"):
                            action, arg = "block", ""
                        rule = {"action": action, "arg": arg}
                        if pattern.startswith("*."):
                            wild.append((pattern[2:], rule))
                        else:
                            exact[pattern] = rule
            else:
                self._mtime = 0
        except OSError:
            pass
        self._exact, self._wild = exact, wild
        self._page = None
        if self.page_path and os.path.isfile(self.page_path):
            try:
                with open(self.page_path, "r", encoding="utf-8", errors="replace") as f:
                    self._page = f.read()
            except OSError:
                self._page = None

    def count(self):
        return len(self._exact) + len(self._wild)

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


def handle_fetch(client, session_id, params, rules, args):
    """Fetch path — used for the 'replace' spoof (serve another site's response under the
    original URL). Block/warn are enforced in enforce_catchup() on frameNavigated, because
    the main-frame document request races Fetch setup on freshly-opened tabs and can't be
    paused reliably; only the response-swap of 'replace' needs the Fetch layer."""
    rid = params.get("requestId")
    url = params.get("request", {}).get("url", "")
    rtype = params.get("resourceType", "")
    rule = rules.match(host_of(url)) if rules else None
    if not rule or rtype != "Document" or rule["action"] != "replace" or not rule["arg"]:
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
        fulfill(rep[0], rep[1])
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
        while not stop["flag"]:
            if rules and time.time() >= next_reload:
                rules.maybe_reload()
                next_reload = time.time() + 2
            msg = client.recv()
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
                            {"urlPattern": "*", "requestStage": "Request"}]}, s)
                        pending_resume[rid] = s   # resume after this acks (below)
                    else:
                        client.send("Runtime.runIfWaitingForDebugger", None, s)
                else:
                    client.send("Runtime.runIfWaitingForDebugger", None, s)
            elif method == "Target.detachedFromTarget":
                ds = params.get("sessionId")
                sessions.pop(ds, None)
                replaced.pop(ds, None)
            elif method == "Fetch.requestPaused":
                handle_fetch(client, sid, params, rules, args)
            elif method == "Page.frameNavigated":
                frame = params.get("frame", {})
                if not frame.get("parentId"):            # main frame only
                    url = frame.get("url", "")
                    log("NAV       %s" % url)
                    rule = rules.match(host_of(url)) if rules else None
                    if not rule or not url or url.startswith(("about:", "data:", "chrome:")):
                        replaced.pop(sid, None)
                    elif rule["action"] == "replace":
                        # The first load of a fresh tab races Fetch setup; re-navigate so the
                        # now-active Fetch on THIS session catches the document and swaps in
                        # the replacement (URL preserved). Track per session to avoid a loop.
                        if rule["arg"] and replaced.get(sid) != url:
                            replaced[sid] = url
                            client.send("Page.navigate", {"url": url}, sid)
                    else:
                        replaced.pop(sid, None)
                        enforce_catchup(client, sid, rule, url, rules)
            else:
                handle_event(method, params, args.requests)
    finally:
        client.close()


# --------------------------------------------------------------------------- #
# Main
# --------------------------------------------------------------------------- #
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
    args = parser.parse_args()

    rules = None
    if args.block or args.block_page:
        rules = RuleSet(args.block, args.block_page)
    regulate = rules is not None or args.all_tabs

    chrome_proc = None
    stop = {"flag": False}

    def on_sigint(signum, frame):
        stop["flag"] = True
    signal.signal(signal.SIGINT, on_sigint)

    try:
        # Decision: VERIFY the debug port first. If Chrome is already debugging on
        # this port, attach to it (don't disturb it). Otherwise — whether a plain
        # Chrome is running or none at all — relaunch Chrome with the debug port.
        already = is_devtools_up(args.port)
        if already and not args.force_restart:
            log("Verified: Chrome is already debugging on port %d (%s) — attaching, not restarting."
                % (args.port, already.get("Browser", "?")))
        elif args.no_launch:
            log("ERROR: --no-launch set but nothing is listening on port %d."
                % args.port)
            return 1
        else:
            chrome_path = find_chrome()
            if not chrome_path:
                log("ERROR: Could not find a Chrome executable on this system.")
                return 1
            if args.force_restart and already:
                log("--force-restart: replacing the running debug instance with a fresh one.")
            elif chrome_running():
                log("Chrome is running WITHOUT a debug port — killing it and relaunching "
                    "with --remote-debugging-port=%d." % args.port)
            else:
                log("No Chrome running — launching with --remote-debugging-port=%d." % args.port)
            kill_chrome()
            chrome_proc = launch_chrome(chrome_path, args.port, args.user_data_dir)

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


if __name__ == "__main__":
    sys.exit(main())
