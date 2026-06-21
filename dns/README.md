# dns — TinyDNS server

A tiny, dependency-free DNS server (stdlib only). It serves a hosts-style
`records.txt` to the whole network, blocks domains from `blocklist.txt`
(answered `0.0.0.0`), forwards everything else to an upstream resolver, and
logs every query to `queries.log`. All three files **hot-reload** — edit and
save, no restart.

### Custom routing (`records.txt`)
Each line is `<domain>  <target>`. The target may be:
- an **IP** — `example.local   192.168.1.10` (classic hosts-style mapping)
- **another domain** — `youtube.com   facebook.com` (redirect: the server resolves
  the target via upstream and returns *its* IP for the queried name)

`*.` wildcards work on the left (`*.dev.local 10.0.0.50`). For any name with a
record, `AAAA` (IPv6) is answered with NODATA so the IPv4 mapping/redirect wins
in browsers. Note: a domain redirect is IP-level, so HTTPS sites will show a
certificate-name mismatch — that's inherent to DNS redirection, not a bug.

This is the server that the rmdownloader web UI manages from **`website/dns.php`**.
The agent on the DNS machine edits these files and controls the scheduled task;
the DNS server itself runs separately as `dnl.exe`.

## ⚠️ Provenance — recovered from the exe
The original source was lost (never pushed to GitHub). `dns_server.py` here was
**reconstructed from the PyInstaller-bundled bytecode inside `dnl.exe`** (Python
3.13). It is faithful to the recovered structure and to the file formats
`dns.php` depends on (TSV query log, `BLOCKED`/`LOCAL`/`FWD`/`NXDOMAIN`
dispositions, the `TinyDNS` task, the boot-task XML, CleanBrowsing upstreams).
A few cosmetic log strings / comments may differ slightly from the original.
The raw recovered bytecode is kept locally as `dns_server.recovered.pyc`
(git-ignored) for cross-checking.

## Run / test
```
python dns_server.py                 # listen on 0.0.0.0:53  (needs admin)
python dns_server.py --port 5353     # unprivileged port for testing
nslookup example.local 127.0.0.1
```

## Build the exe
```
build.bat            # pip install pyinstaller first
```
Produces `dist\dnl.exe` (windowless, single file). On first run **as admin** the
exe self-registers a hidden SYSTEM boot task named `TinyDNS` pointing at itself,
so it starts at every boot. `dns_server.py --uninstall` removes that task.

## CLI options
| flag | default | meaning |
|------|---------|---------|
| `--host` | `0.0.0.0` | bind address |
| `--port` | `53` | bind port |
| `--records` | `records.txt` | hosts-style records file |
| `--blocklist` | `blocklist.txt` | blocked domains (→ 0.0.0.0) |
| `--log` | `queries.log` | TSV query log |
| `--no-log` | off | disable query logging |
| `--no-install` | off | don't self-register the boot task |
| `--uninstall` | — | remove the TinyDNS boot task and exit |
| `--upstream` | `185.228.168.10,185.228.168.11` | comma-separated resolvers (failover), or `none` |

Relative paths resolve next to the exe/script, which is why `dns.php`
auto-detects the folder from the task's exe path.
