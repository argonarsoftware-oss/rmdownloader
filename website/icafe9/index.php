<?php
// Icafe9 — public product landing, download, and manual page.
// Self-contained (no auth, no config.php dependency) so it is reachable at
// https://dos.argonar.co/icafe9/ without a login. Static PHP only — no exec surface.

$product   = 'Icafe9';
$version   = '1.0.0';
$adminPort = 7710;

// Download targets. 69 MB exes are NOT committed to git — point at a GitHub
// Release so the repo stays lean. Update these URLs when a release is cut.
$releaseBase = 'https://github.com/argonarsoftware-oss/rmdownloader/releases/latest/download';
$downloads = [
    'server' => $releaseBase . '/Icafe9-Server-Setup.exe',
    'client' => $releaseBase . '/Icafe9-Client-Setup.exe',
];

$features = [
    ['🖥', 'Live PC grid', 'Every seat as a card with a real-time timer, status colour, and one-click Start / Stop.'],
    ['⏱', 'Flexible billing', 'Open (pay-per-minute), Timed (prepaid), and discounted time Packages — e.g. 5 hours for a fixed price.'],
    ['👤', 'Member accounts', 'Prepaid balances, self-service login on the lock screen, auto-stop before a balance goes negative.'],
    ['🛒', 'Point of sale', 'Sell snacks and drinks for cash, from a balance, or onto a session tab. Stock tracked automatically.'],
    ['🧾', 'Shifts & drawer', 'Operators open a shift with a float and close by counting the drawer — over/short is shown instantly.'],
    ['🛡', 'Operators & audit', 'Administrator / Staff roles and a full audit log of every edit, deposit, delete, and sale.'],
    ['📡', 'Remote control', 'Shut down, restart, message, or Wake-on-LAN any customer PC from the dashboard.'],
    ['📊', 'Reports', 'Cash, balance spend, deposits, time vs product revenue, and full history for today / 7 / 30 days.'],
];

$toc = [
    'start'    => 'Getting started',
    'sessions' => 'Sessions & billing',
    'members'  => 'Members',
    'pos'      => 'Point of sale',
    'shifts'   => 'Shifts & operators',
    'remote'   => 'Remote control',
    'client'   => 'Client app',
    'faq'      => 'FAQ',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($product) ?> — Internet Cafe Management</title>
<style>
    :root {
        --bg:#eef1f6; --panel:#ffffff; --ink:#1a2230; --dim:#5f6b7d; --line:#d7dde7;
        --navy:#0a246a; --blue:#316ac5; --blue2:#244f9c; --green:#2e7d32; --amber:#b8860b;
    }
    * { box-sizing:border-box; margin:0; padding:0; }
    body { font-family:"Segoe UI",Tahoma,system-ui,sans-serif; background:var(--bg); color:var(--ink); line-height:1.65; }
    a { color:var(--blue2); }
    .wrap { max-width:1080px; margin:0 auto; padding:0 20px; }

    /* hero */
    .hero { background:linear-gradient(180deg,#5a7edc 0%,#3a63c4 55%,#23459c 100%); color:#fff; padding:54px 0 46px; }
    .hero .wrap { display:flex; align-items:center; gap:26px; flex-wrap:wrap; }
    .logo { width:84px; height:84px; border-radius:16px; overflow:hidden;
        display:grid; place-items:center;
        box-shadow:0 12px 40px rgba(0,0,0,.35); flex-shrink:0; }
    .logo img { width:100%; height:100%; object-fit:cover; display:block; }
    .hero h1 { font-size:38px; letter-spacing:.5px; }
    .hero p { font-size:16px; opacity:.92; max-width:560px; margin-top:6px; }
    .hero .ver { display:inline-block; margin-top:10px; font-size:12px; background:rgba(255,255,255,.18);
        border:1px solid rgba(255,255,255,.35); padding:2px 10px; border-radius:20px; }

    /* download */
    .dl { display:flex; gap:14px; flex-wrap:wrap; margin-top:20px; }
    .dl a { text-decoration:none; }
    .btn { display:inline-flex; align-items:center; gap:9px; padding:12px 20px; border-radius:8px;
        font-size:14px; font-weight:600; border:1px solid var(--blue2); cursor:pointer; }
    .btn.primary { background:#fff; color:var(--navy); box-shadow:0 6px 18px rgba(0,0,0,.2); }
    .btn.ghost { background:rgba(255,255,255,.12); color:#fff; }
    .btn small { display:block; font-weight:400; font-size:11px; opacity:.75; }

    section { padding:36px 0; }
    section.alt { background:var(--panel); border-top:1px solid var(--line); border-bottom:1px solid var(--line); }
    h2 { font-size:22px; color:var(--navy); margin-bottom:16px; }
    h3 { font-size:15px; color:var(--blue); margin:16px 0 6px; }

    .grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(230px,1fr)); gap:14px; }
    .card { background:var(--panel); border:1px solid var(--line); border-radius:10px; padding:16px; }
    section.alt .card { background:var(--bg); }
    .card .ico { font-size:22px; }
    .card h4 { font-size:14px; margin:8px 0 4px; color:var(--navy); }
    .card p { font-size:13px; color:var(--dim); }

    .manual { display:grid; grid-template-columns:210px 1fr; gap:28px; }
    .manual nav { position:sticky; top:16px; align-self:start; }
    .manual nav a { display:block; padding:6px 10px; border-radius:7px; text-decoration:none; color:var(--dim); font-size:14px; }
    .manual nav a:hover { background:var(--bg); color:var(--ink); }
    .manual .body p, .manual .body li { font-size:14px; }
    .manual .body ul, .manual .body ol { padding-left:22px; margin:6px 0; }
    .manual .body li { margin:3px 0; }
    .manual .body section { padding:0 0 22px; scroll-margin-top:16px; }
    code { background:#eaf0fb; border:1px solid #cfe; border:1px solid var(--line); border-radius:5px;
        padding:1px 6px; font-family:Consolas,monospace; font-size:13px; color:var(--blue2); }
    .note { border-left:3px solid var(--blue); background:#eef3fb; padding:9px 13px; border-radius:0 7px 7px 0; margin:10px 0; font-size:13px; }
    .note.warn { border-left-color:var(--amber); background:#fbf6e8; }

    footer { background:#0e1626; color:#9fb0c8; font-size:12.5px; padding:22px 0; text-align:center; }
    @media (max-width:760px){ .manual { grid-template-columns:1fr; } .manual nav { position:static; } .hero h1 { font-size:30px; } }
</style>
</head>
<body>

<header class="hero">
  <div class="wrap">
    <div class="logo"><img src="logo.png" alt="Icafe9" /></div>
    <div>
      <h1><?= htmlspecialchars($product) ?></h1>
      <p>Internet cafe management — a desktop <b>Server</b> console for the front desk and a lock-screen <b>Client</b> for every customer PC.</p>
      <span class="ver">Version <?= htmlspecialchars($version) ?> · Windows</span>
      <div class="dl">
        <a href="<?= htmlspecialchars($downloads['server']) ?>"><span class="btn primary">⬇ Download Icafe9 Server<small>Front-desk PC · one per cafe</small></span></a>
        <a href="<?= htmlspecialchars($downloads['client']) ?>"><span class="btn ghost">⬇ Download Icafe9 Client<small>Each customer PC</small></span></a>
      </div>
    </div>
  </div>
</header>

<section>
  <div class="wrap">
    <h2>What it does</h2>
    <div class="grid">
      <?php foreach ($features as $f): ?>
        <div class="card">
          <div class="ico"><?= $f[0] ?></div>
          <h4><?= htmlspecialchars($f[1]) ?></h4>
          <p><?= htmlspecialchars($f[2]) ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="alt">
  <div class="wrap manual">
    <nav>
      <?php foreach ($toc as $id => $title): ?>
        <a href="#<?= $id ?>"><?= htmlspecialchars($title) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="body">
      <section id="start">
        <h2>Getting started</h2>
        <ol>
          <li>Run <b>Icafe9 Server</b> on the front-desk PC. It opens to an operator sign-in.</li>
          <li>On first launch, create your <b>administrator</b> account (username + password). Then sign in and open a shift with the cash already in the drawer as the float.</li>
          <li>Run <b>Icafe9 Client</b> on each customer PC and point it at the Server PC's LAN IP and port (shown in the Server sidebar, default <code><?= $adminPort ?></code>). Each PC registers itself automatically.</li>
        </ol>
        <div class="note">Add a <b>Staff</b> account for each attendant in <b>Settings → Operators</b> so cash and actions are attributed per person.</div>
      </section>

      <section id="sessions">
        <h2>Sessions &amp; billing</h2>
        <p>Click <b>Start</b> on a PC card and choose a type:</p>
        <ul>
          <li><b>Open</b> — billed per started minute at the tariff rate; paid when the customer finishes.</li>
          <li><b>Timed</b> — a prepaid block of minutes; the PC auto-locks when it runs out.</li>
          <li><b>Package</b> — a fixed-price bundle (e.g. 5 hours for a set price) at a discount to the hourly rate.</li>
        </ul>
        <div class="note warn">Member sessions auto-stop just before the balance would go negative; timed and package sessions stop when the clock hits zero.</div>
      </section>

      <section id="members">
        <h2>Members</h2>
        <p>Create members with a prepaid balance and top them up with <b>Deposit</b>. Members can log in on any locked PC themselves and play against their balance. A red (negative) balance is debt from a forced auto-stop, collected on the next deposit.</p>
      </section>

      <section id="pos">
        <h2>Point of sale</h2>
        <p><b>Quick Sale</b> for walk-ins or <b>Sell</b> on a PC card. Pay by cash, member balance, or add to the PC's session <b>tab</b> (settled when the session ends). Stock decreases per sale and low stock is highlighted.</p>
      </section>

      <section id="shifts">
        <h2>Shifts &amp; operators</h2>
        <ul>
          <li><b>Administrator</b> — full access including Settings and Operators.</li>
          <li><b>Staff</b> — runs sessions, sales, and members, but cannot change settings or manage operators.</li>
        </ul>
        <p>Each operator opens a shift at the start of their turn and closes it at the end by counting the drawer; the console reports over/short. Every cash movement is attributed to the operator on shift, and all edits/deposits/deletes are recorded in the <b>Audit Log</b>.</p>
      </section>

      <section id="remote">
        <h2>Remote control</h2>
        <p>On each online PC card: <b>Message</b> shows a note on the customer's screen, <b>Restart</b> and <b>Off</b> control power, and <b>Message All</b> broadcasts to everyone. Offline PCs show <b>Wake Up</b> (Wake-on-LAN) once the client has connected once — this needs Wake-on-LAN enabled in the PC's BIOS and network adapter, on a wired connection.</p>
      </section>

      <section id="client">
        <h2>Client app</h2>
        <p>The client locks the customer's screen until a session starts, then shows a small draggable timer. Staff controls on the lock screen (<b>staff exit</b>, <b>setup</b>) require the client exit password from the Server's Settings. If the client loses the Server, an active session keeps running and re-syncs when the connection returns — a front-desk reboot never kicks out a paying customer — while idle PCs stay locked. Prepaid timed sessions still end themselves when their time is up, even offline.</p>
      </section>

      <section id="faq">
        <h2>FAQ</h2>
        <h3>The client can't connect</h3>
        <p>Check the IP/port against the Server sidebar, allow the Server app through Windows Firewall, and make sure both PCs are on the same network.</p>
        <h3>Where is the data stored?</h3>
        <p>On the front-desk PC at <code>%APPDATA%\Icafe9\icafe9-data.json</code>. Back it up; passwords are stored as scrypt hashes.</p>
        <h3>Is my data safe on the same PC as older versions?</h3>
        <p>Yes — the app migrates data from earlier installs automatically on first launch.</p>
      </section>
    </div>
  </div>
</section>

<footer>
  <?= htmlspecialchars($product) ?> v<?= htmlspecialchars($version) ?> · Argonar Software ·
  served from <?= htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'dos.argonar.co') ?>
</footer>

</body>
</html>
