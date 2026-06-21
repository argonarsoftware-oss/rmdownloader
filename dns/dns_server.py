"""
A tiny, dependency-free DNS server.
Maps domains -> IPs from a hosts-style file (records.txt) and forwards
everything else to an upstream resolver, so the machine still works normally.
Usage:
    python dns_server.py                 # listen on 0.0.0.0:53 (needs admin)
    python dns_server.py --port 5353     # unprivileged port for testing
    python dns_server.py --records my.txt --upstream 1.1.1.1
Test it from another terminal:
    nslookup example.local 127.0.0.1
    nslookup -port=5353 example.local 127.0.0.1     # if using --port 5353

NOTE: this file was reconstructed from the PyInstaller-bundled bytecode inside
dnl.exe after the original source was lost (never pushed). It is faithful to the
recovered string/structure evidence and to what website/dns.php expects, but
exact whitespace/comments in a few log lines may differ from the original.
"""

import argparse
import os
import socket
import struct
import subprocess
import sys
import tempfile
import threading
import time

# ---- DNS constants ----
TYPE_A = 1
CLASS_IN = 1
TYPE_NAMES = {1: 'A', 5: 'CNAME', 28: 'AAAA', 64: 'SVCB', 65: 'HTTPS'}

# ---- default config files (created on first run if missing) ----
DEFAULT_RECORDS = """\
# DNS records file  -  like the Windows hosts file, but served to the whole network.
#
# Format:   <domain>   <ip-address>
#   - one mapping per line
#   - whitespace separated
#   - lines starting with '#' are comments
#   - blank lines ignored
#   - wildcards allowed with a leading "*."  (matches any single-or-multi label subdomain)
#
# This file is HOT-RELOADED: edit + save and the running server picks it up. No restart.

# --- examples (edit / delete these) ---
example.local      192.168.1.10
test.local         127.0.0.1
router.local       192.168.1.1
*.dev.local        10.0.0.50
"""

DEFAULT_BLOCKLIST = """\
# Blocklist  -  domains here are answered with 0.0.0.0 so they can't be reached.
#
# Format: one domain per line.
#   - "*." prefix blocks all subdomains too, e.g.  *.facebook.com
#   - a bare domain like "ads.example.com" blocks only that exact name
#   - lines starting with '#' are comments
#
# This file is HOT-RELOADED: edit + save and blocking updates live.

# --- examples (uncomment / edit / add your own) ---
# *.doubleclick.net
# ads.example.com
"""


def ensure_default(path, content):
    """Create a config file with default content if it doesn't exist yet."""
    if os.path.exists(path):
        return
    try:
        with open(path, 'w', encoding='utf-8') as f:
            f.write(content)
        print('[init] created ' + path)
    except OSError as exc:
        print('[init] could not create ' + path + ': ' + str(exc))


# ---- self-install as a SYSTEM boot task when running as the built exe ----
TASK_NAME = 'TinyDNS'
TASK_XML = """\
<?xml version="1.0" encoding="UTF-16"?>
<Task version="1.2" xmlns="http://schemas.microsoft.com/windows/2004/02/mit/task">
  <RegistrationInfo><Description>TinyDNS server</Description></RegistrationInfo>
  <Triggers><BootTrigger><Enabled>true</Enabled></BootTrigger></Triggers>
  <Principals><Principal id="Author"><UserId>S-1-5-18</UserId><RunLevel>HighestAvailable</RunLevel></Principal></Principals>
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
  <Actions Context="Author"><Exec><Command>%s</Command></Exec></Actions>
</Task>
"""

# don't pop a console window for the schtasks helper calls
_NO_WINDOW = 0x08000000 if os.name == 'nt' else 0


def _schtasks(*args):
    return subprocess.run(['schtasks'] + list(args),
                          capture_output=True, creationflags=_NO_WINDOW)


def _xml_escape(s):
    return (s.replace('&', '&amp;').replace('<', '&lt;')
             .replace('>', '&gt;').replace('"', '&quot;'))


def ensure_task():
    """When running as the built exe, self-register a SYSTEM boot task (needs admin)."""
    if not getattr(sys, 'frozen', False):
        return
    try:
        res = _schtasks('/query', '/tn', TASK_NAME)
        if res.returncode == 0:
            return  # already installed
        xml = TASK_XML % _xml_escape(sys.executable)
        tmp = os.path.join(tempfile.gettempdir(), 'tinydns_task.xml')
        with open(tmp, 'w', encoding='utf-16') as f:
            f.write(xml)
        res = _schtasks('/create', '/tn', TASK_NAME, '/xml', tmp, '/f')
        try:
            os.remove(tmp)
        except OSError:
            pass
        if res.returncode == 0:
            print("[init] registered boot task '" + TASK_NAME + "' (SYSTEM, starts at every boot)")
        else:
            print('[init] could not register boot task - run as Administrator to enable auto-start')
    except Exception as exc:
        print('[init] task setup skipped: ' + str(exc))


def remove_task():
    res = _schtasks('/delete', '/tn', TASK_NAME, '/f')
    if res.returncode == 0:
        print("[init] removed boot task '" + TASK_NAME + "'")
    else:
        print("[init] boot task '" + TASK_NAME + "' not found")


# ---- hot-reloaded record / block stores ----
class RecordStore:
    def __init__(self, path):
        self.path = path
        self._lock = threading.Lock()
        self._exact = {}
        self._wildcard = []   # list of (suffix, ip)
        self._mtime = 0
        self.reload(force=True)

    def reload(self, force=False):
        try:
            mtime = os.path.getmtime(self.path)
        except OSError:
            return
        if not force and mtime == self._mtime:
            return
        exact = {}
        wildcard = []
        try:
            with open(self.path, 'r', encoding='utf-8') as f:
                for lineno, line in enumerate(f, 1):
                    s = line.strip()
                    if not s or s.startswith('#'):
                        continue
                    parts = s.split()
                    if len(parts) < 2:
                        print('[records] line ' + str(lineno) + ': ignoring malformed entry: ' + line.rstrip())
                        continue
                    domain = parts[0].lower().rstrip('.')
                    ip = parts[1]
                    if domain.startswith('*.'):
                        wildcard.append((domain[2:], ip))
                    else:
                        exact[domain] = ip
        except OSError:
            print('[records] could not read ' + self.path)
            return
        with self._lock:
            self._exact = exact
            self._wildcard = wildcard
            self._mtime = mtime
        print('[records] loaded ' + str(len(exact)) + ' exact + ' + str(len(wildcard)) +
              ' wildcard mapping(s) from ' + self.path)

    def lookup(self, domain):
        """Return an IP string for a domain, or None."""
        domain = domain.lower().rstrip('.')
        with self._lock:
            ip = self._exact.get(domain)
            if ip:
                return ip
            labels = domain.split('.')
            for i in range(len(labels)):
                suffix = '.'.join(labels[i:])
                for base, bip in self._wildcard:
                    if suffix == base:
                        return bip
        return None


class BlockStore:
    """A set of blocked domains, hot-reloaded. Blocked names -> answered 0.0.0.0."""
    def __init__(self, path):
        self.path = path
        self._lock = threading.Lock()
        self._exact = set()
        self._wildcard = []
        self._mtime = 0
        self.reload(force=True)

    def reload(self, force=False):
        try:
            mtime = os.path.getmtime(self.path)
        except OSError:
            return
        if not force and mtime == self._mtime:
            return
        exact = set()
        wildcard = []
        try:
            with open(self.path, 'r', encoding='utf-8') as f:
                for line in f:
                    s = line.strip().lower().rstrip('.')
                    if not s or s.startswith('#'):
                        continue
                    if s.startswith('*.'):
                        wildcard.append(s[2:])
                    else:
                        exact.add(s)
        except OSError:
            print('[blocklist] could not read ' + self.path)
            return
        with self._lock:
            self._exact = exact
            self._wildcard = wildcard
            self._mtime = mtime
        print('[blocklist] loaded ' + str(len(exact)) + ' exact + ' + str(len(wildcard)) +
              ' wildcard block(s) from ' + self.path)

    def is_blocked(self, domain):
        domain = domain.lower().rstrip('.')
        with self._lock:
            if domain in self._exact:
                return True
            labels = domain.split('.')
            for i in range(len(labels)):
                if '.'.join(labels[i:]) in self._wildcard:
                    return True
        return False


def watch_files(stores, interval=2.0):
    while True:
        time.sleep(interval)
        for s in stores:
            try:
                s.reload()
            except Exception:
                pass


# ---- query log (TSV: time, client IP, domain, type, disposition) ----
class QueryLog:
    """Append every query to a TSV log: time, client IP, domain, type, disposition.
    Rotates to <log>.1 once it passes MAX_BYTES so it can't grow without bound."""
    MAX_BYTES = 5 * 1024 * 1024

    def __init__(self, path, enabled=True):
        self.path = path
        self.enabled = enabled
        self._lock = threading.Lock()

    def write(self, client, domain, qtype, disposition):
        if not self.enabled:
            return
        ts = time.strftime('%Y-%m-%d %H:%M:%S')
        line = '\t'.join((ts, client, domain, qtype, disposition)) + '\n'
        try:
            with self._lock:
                try:
                    if os.path.getsize(self.path) > self.MAX_BYTES:
                        try:
                            os.remove(self.path + '.1')
                        except OSError:
                            pass
                        os.replace(self.path, self.path + '.1')
                except OSError:
                    pass
                with open(self.path, 'a', encoding='utf-8') as f:
                    f.write(line)
        except OSError:
            pass


# ---- wire format ----
def parse_question(data, offset=12):
    """Parse the first question from a DNS query.
    Returns (qname_str, qtype, qclass, end_offset) or None on parse failure."""
    labels = []
    try:
        while True:
            length = data[offset]
            offset += 1
            if length == 0:
                break
            labels.append(data[offset:offset + length].decode('ascii', 'replace'))
            offset += length
        qtype, qclass = struct.unpack_from('!HH', data, offset)
        offset += 4
        qname = '.'.join(labels)
        return qname, qtype, qclass, offset
    except (IndexError, struct.error):
        return None


def build_a_response(query, question_end, ip, ttl=300):
    """Build a DNS response packet answering an A query from `query` with `ip`."""
    txn_id = query[:2]
    question = query[12:question_end]
    # 0x8580 = response + authoritative answer (AA) + recursion available
    header = struct.pack('!HHHHH', 0x8580, 1, 1, 0, 0)
    answer = struct.pack('!HHHIH', 0xC00C, TYPE_A, CLASS_IN, ttl, 4) + socket.inet_aton(ip)
    return txn_id + header + question + answer


def build_nxdomain(query, question_end):
    """Build an authoritative NXDOMAIN-ish response (used only if no upstream)."""
    txn_id = query[:2]
    question = query[12:question_end]
    header = struct.pack('!HHHHH', 0x8183, 1, 0, 0, 0)
    return txn_id + header + question


def forward(query, upstreams, timeout=3.0):
    """Send the raw query to each upstream in turn; return the first reply."""
    if not upstreams:
        raise RuntimeError('no upstream configured')
    last_exc = None
    for server in upstreams:
        try:
            sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
            sock.settimeout(timeout)
            try:
                sock.sendto(query, (server, 53))
                reply, _ = sock.recvfrom(4096)
                return reply
            finally:
                sock.close()
        except (socket.timeout, OSError) as exc:
            last_exc = exc
    raise last_exc or RuntimeError('all upstreams failed')


# ---- domain redirection (records.txt target may be a domain, not an IP) ----
def is_ipv4(s):
    parts = s.split('.')
    if len(parts) != 4:
        return False
    for p in parts:
        if not p.isdigit() or not (0 <= int(p) <= 255):
            return False
    return True


def build_empty(query, question_end):
    """NODATA response (NOERROR, no answers) - used to suppress AAAA on redirected names
    so the IPv4 (A) redirect wins in browsers instead of leaking the real IPv6 address."""
    txn_id = query[:2]
    question = query[12:question_end]
    header = struct.pack('!HHHHH', 0x8580, 1, 0, 0, 0)
    return txn_id + header + question


def build_query(name, txn=b'\x13\x37'):
    qn = b''.join(struct.pack('!B', len(p)) + p.encode('ascii') for p in name.split('.') if p) + b'\x00'
    return txn + struct.pack('!HHHHH', 0x0100, 1, 0, 0, 0) + qn + struct.pack('!HH', TYPE_A, CLASS_IN)


def _skip_name(data, off):
    while True:
        l = data[off]
        if l == 0:
            return off + 1
        if (l & 0xC0) == 0xC0:   # compression pointer
            return off + 2
        off += 1 + l


def first_a_ip(resp):
    """Return the first A-record IPv4 string from a DNS response, or None."""
    try:
        qd = struct.unpack_from('!H', resp, 4)[0]
        an = struct.unpack_from('!H', resp, 6)[0]
        off = 12
        for _ in range(qd):
            off = _skip_name(resp, off) + 4   # + qtype + qclass
        for _ in range(an):
            off = _skip_name(resp, off)
            rtype, rclass, ttl, rdlen = struct.unpack_from('!HHIH', resp, off)
            off += 10
            if rtype == TYPE_A and rdlen == 4:
                return socket.inet_ntoa(resp[off:off + 4])
            off += rdlen
    except (IndexError, struct.error):
        pass
    return None


_alias_cache = {}          # target domain -> (ip, expiry)
_alias_lock = threading.Lock()


def resolve_alias(target, upstreams, ttl=60):
    """Resolve a redirect target domain to an IP via upstream, with a short cache."""
    now = time.time()
    with _alias_lock:
        hit = _alias_cache.get(target)
        if hit and hit[1] > now:
            return hit[0]
    ip = first_a_ip(forward(build_query(target), upstreams))
    if ip:
        with _alias_lock:
            _alias_cache[target] = (ip, now + ttl)
    return ip


# ---- forward-result cache (keyed by name/type/class, TTL-respecting) ----
_fwd_cache = {}            # (name, qtype, qclass) -> (response_bytes, expiry)
_fwd_lock = threading.Lock()
FWD_CACHE_MAX = 5000       # bound memory; entries also expire by TTL
FWD_TTL_CAP = 3600         # never trust a TTL longer than an hour


def _min_ttl(resp):
    """Smallest TTL across the answer records, or None if there are no answers."""
    try:
        qd = struct.unpack_from('!H', resp, 4)[0]
        an = struct.unpack_from('!H', resp, 6)[0]
        if an == 0:
            return None
        off = 12
        for _ in range(qd):
            off = _skip_name(resp, off) + 4
        ttls = []
        for _ in range(an):
            off = _skip_name(resp, off)
            rtype, rclass, ttl, rdlen = struct.unpack_from('!HHIH', resp, off)
            off += 10 + rdlen
            ttls.append(ttl)
        return min(ttls) if ttls else None
    except (IndexError, struct.error):
        return None


def cache_get(key):
    now = time.time()
    with _fwd_lock:
        hit = _fwd_cache.get(key)
        if hit:
            if hit[1] > now:
                return hit[0]
            del _fwd_cache[key]
    return None


def cache_put(key, resp, ttl):
    if not ttl or ttl <= 0:
        return
    if ttl > FWD_TTL_CAP:
        ttl = FWD_TTL_CAP
    now = time.time()
    with _fwd_lock:
        if len(_fwd_cache) >= FWD_CACHE_MAX:
            for k in [k for k, v in _fwd_cache.items() if v[1] <= now]:
                del _fwd_cache[k]
            if len(_fwd_cache) >= FWD_CACHE_MAX:
                _fwd_cache.clear()
        _fwd_cache[key] = (resp, now + ttl)


def handle(data, addr, sock, records, blocks, qlog, upstreams):
    try:
        client = addr[0]
        parsed = parse_question(data)
        if not parsed:
            return
        qname, qtype, qclass, qend = parsed
        tname = TYPE_NAMES.get(qtype, str(qtype))
        lname = qname.lower().rstrip('.')

        # 1) blocked -> 0.0.0.0
        if blocks.is_blocked(lname):
            sock.sendto(build_a_response(data, qend, '0.0.0.0'), addr)
            qlog.write(client, qname, tname, 'BLOCKED')
            print('[query] ' + qname + ' -> BLOCKED (0.0.0.0)')
            return

        # 2) local record — target is either an IP or a domain to redirect to
        target = records.lookup(lname)
        if target:
            # Suppress AAAA for any name we have a record for, so the IPv4 redirect
            # wins in browsers instead of leaking the real IPv6 address.
            if qtype == 28:  # AAAA
                sock.sendto(build_empty(data, qend), addr)
                qlog.write(client, qname, tname, 'LOCAL (no AAAA)')
                return
            if qtype == TYPE_A:
                if is_ipv4(target):
                    ip = target
                    disp = 'LOCAL ' + ip
                    note = '  (local)'
                else:
                    # redirect: resolve the target domain to its current IP via upstream
                    ip = None
                    try:
                        ip = resolve_alias(target, upstreams)
                    except Exception:
                        ip = None
                    disp = ('LOCAL ' + ip + ' <- ' + target) if ip else None
                    note = '  (redirect -> ' + target + ')'
                if ip:
                    sock.sendto(build_a_response(data, qend, ip), addr)
                    qlog.write(client, qname, tname, disp)
                    print('[query] LOCAL ' + qname + ' A -> ' + ip + note)
                    return
                # redirect target couldn't be resolved -> fall through to forward

        # 3) forward everything else (TTL-cached)
        key = (lname, qtype, qclass)
        cached = cache_get(key)
        if cached is not None:
            # serve cached bytes, but stamp the client's transaction id (first 2 bytes)
            sock.sendto(data[:2] + cached[2:], addr)
            qlog.write(client, qname, tname, 'FWD (cache)')
            return
        try:
            reply = forward(data, upstreams)
            # cache before replying so other concurrent PCs hit it right away
            cache_put(key, reply, _min_ttl(reply))
            sock.sendto(reply, addr)
            qlog.write(client, qname, tname, 'FWD')
            print('[query] ' + qname + ' type=' + tname + ' -> forwarded to upstream')
        except Exception as exc:
            sock.sendto(build_nxdomain(data, qend), addr)
            qlog.write(client, qname, tname, 'NXDOMAIN')
            print('[query] ' + qname + ': all upstreams failed: ' + str(exc) + ' -> NXDOMAIN')
    except Exception:
        pass


def server(host, port, records, blocks, qlog, upstreams):
    sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    # Windows: stop ICMP port-unreachable from killing the UDP socket (WSAECONNRESET).
    # Best-effort: not all Python builds accept this ioctl; the recvfrom loop below also
    # swallows OSError (ConnectionResetError) as the real safety net.
    try:
        SIO_UDP_CONNRESET = 0x9800000C
        sock.ioctl(SIO_UDP_CONNRESET, False)
    except (AttributeError, OSError, ValueError):
        pass
    try:
        sock.bind((host, port))
    except PermissionError:
        print('[fatal] cannot bind ' + host + ':' + str(port) +
              ' - run as Administrator, or use --port 5353')
        sys.exit(1)
    except OSError as exc:
        print('[fatal] cannot bind ' + host + ':' + str(port) + ' - ' + str(exc))
        sys.exit(1)

    print('[ready] DNS server listening on ' + host + ':' + str(port))
    print('[ready] upstream = ' + (', '.join(upstreams) if upstreams else 'none (local records only)'))
    print('[ready] query log = ' + (qlog.path if qlog.enabled else 'disabled'))
    print("[ready] point a client's DNS at this machine's IP. Ctrl+C to stop.")

    try:
        while True:
            try:
                data, addr = sock.recvfrom(4096)
            except OSError:
                continue
            t = threading.Thread(target=handle,
                                 args=(data, addr, sock, records, blocks, qlog, upstreams),
                                 daemon=True)
            t.start()
    except KeyboardInterrupt:
        pass


def main():
    # windowless exe has no console: guard prints so they can't crash on None streams
    if sys.stdout is None:
        sys.stdout = open(os.devnull, 'w')
    if sys.stderr is None:
        sys.stderr = sys.stdout

    parser = argparse.ArgumentParser(description='Tiny hosts-file-style DNS server.')
    parser.add_argument('--host', default='0.0.0.0', help='bind address (default 0.0.0.0)')
    parser.add_argument('--port', type=int, default=53, help='bind port (default 53)')
    parser.add_argument('--records', default='records.txt', help='records file (default records.txt)')
    parser.add_argument('--blocklist', default='blocklist.txt', help='blocklist file (default blocklist.txt)')
    parser.add_argument('--log', default='queries.log', help='query log file (default queries.log)')
    parser.add_argument('--no-log', action='store_true', help='disable query logging')
    parser.add_argument('--no-install', action='store_true', help="don't self-register the boot task")
    parser.add_argument('--uninstall', action='store_true', help='remove the TinyDNS boot task and exit')
    parser.add_argument('--upstream', default='185.228.168.10,185.228.168.11',
                        help="comma-separated upstream resolvers (failover order), or 'none'. "
                             "Default: CleanBrowsing 185.228.168.10,185.228.168.11")
    args = parser.parse_args()

    if args.uninstall:
        remove_task()
        return

    # resolve relative paths next to the exe (frozen) or this script
    if getattr(sys, 'frozen', False):
        here = os.path.dirname(sys.executable)
    else:
        here = os.path.dirname(os.path.abspath(__file__))

    def resolve(p):
        return p if os.path.isabs(p) else os.path.join(here, p)

    records_path = resolve(args.records)
    blocklist_path = resolve(args.blocklist)
    log_path = resolve(args.log)

    ensure_default(records_path, DEFAULT_RECORDS)
    ensure_default(blocklist_path, DEFAULT_BLOCKLIST)

    if not args.no_install:
        ensure_task()

    up = args.upstream.strip().lower()
    if up == 'none' or not up:
        upstreams = []
    else:
        upstreams = [u.strip() for u in args.upstream.split(',') if u.strip()]

    records = RecordStore(records_path)
    blocks = BlockStore(blocklist_path)
    qlog = QueryLog(log_path, enabled=not args.no_log)

    watcher = threading.Thread(target=watch_files, args=([records, blocks],), daemon=True)
    watcher.start()

    try:
        server(args.host, args.port, records, blocks, qlog, upstreams)
    finally:
        print('[stop] bye')


if __name__ == '__main__':
    main()
