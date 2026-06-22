#!/usr/bin/env python3
"""
chrome_nav_monitor.py — watch Chrome's navigation in real time via the
Chrome DevTools Protocol (CDP).

What it does:
  1. Detects the OS and locates the Chrome executable.
  2. Kills any running Chrome, waits for it to fully exit, then relaunches it
     with --remote-debugging-port and a dedicated --user-data-dir so the debug
     port reliably opens.
  3. Polls http://127.0.0.1:<port>/json/version until the port is ready.
  4. Connects to the page's DevTools websocket, enables the Page and Network
     domains, and prints every navigation as it happens:
       - Page.frameNavigated            (main-frame navigations)
       - Page.navigatedWithinDocument   (in-page SPA / history changes)
       - Network.requestWillBeSent      (full request URLs, with --requests)
  5. Clean shutdown on Ctrl+C.

Dependencies: requests, websocket-client   (no Selenium)
    pip install requests websocket-client
"""

import argparse
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

    def send(self, method, params=None):
        self._id += 1
        msg = {"id": self._id, "method": method}
        if params:
            msg["params"] = params
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
# Event handling
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
# Main
# --------------------------------------------------------------------------- #
def main():
    parser = argparse.ArgumentParser(
        description="Monitor Chrome navigation in real time via CDP.")
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
    parser.add_argument("--no-launch", action="store_true",
                        help="Don't kill/launch Chrome; attach to an already "
                             "running instance on --port")
    parser.add_argument("--force-restart", action="store_true",
                        help="Kill and relaunch Chrome even if the debug port "
                             "is already open (default: attach to it)")
    args = parser.parse_args()

    chrome_proc = None
    client = None
    stop = {"flag": False}

    def on_sigint(signum, frame):
        stop["flag"] = True
    signal.signal(signal.SIGINT, on_sigint)

    try:
        # If Chrome is already in debug mode on this port, attach instead of
        # killing/relaunching — avoids needless restarts and restart churn.
        already = is_devtools_up(args.port)
        if already and not args.force_restart:
            log("Chrome already in debug mode on port %d (%s) — attaching."
                % (args.port, already.get("Browser", "?")))
        elif args.no_launch:
            log("ERROR: --no-launch set but nothing is listening on port %d."
                % args.port)
            return 1
        else:
            if already:
                log("--force-restart: replacing the running debug instance.")
            chrome_path = find_chrome()
            if not chrome_path:
                log("ERROR: Could not find a Chrome executable on this system.")
                return 1
            kill_chrome()
            chrome_proc = launch_chrome(chrome_path, args.port, args.user_data_dir)

        wait_for_devtools(args.port)
        ws_url = get_page_ws_url(args.port)
        client = CDPClient(ws_url)

        client.send("Page.enable")
        client.send("Network.enable")
        # Fire frameNavigated for the page's current state too.
        client.send("Page.setLifecycleEventsEnabled", {"enabled": True})

        log("Monitoring navigation. Press Ctrl+C to stop.")
        log("-" * 60)

        while not stop["flag"]:
            msg = client.recv()
            if msg is None:
                continue
            method = msg.get("method")
            if method:
                handle_event(method, msg.get("params", {}), args.requests)

    except RuntimeError as e:
        log("ERROR: %s" % e)
        return 1
    finally:
        log("Shutting down...")
        if client:
            client.close()
        if chrome_proc:
            try:
                chrome_proc.terminate()
            except Exception:
                pass

    return 0


if __name__ == "__main__":
    sys.exit(main())
