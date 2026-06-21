// rmdownloader agent supervisor  --  agentsvc.exe
//
// The stable boot-task process. It keeps the worker (Agent.exe) running, applies staged
// updates, and AUTO-ROLLS-BACK a build that fails to check in:
//   * worker receives an 'update' command -> writes Agent.new.exe + update.flag -> exits
//   * supervisor backs up the current worker, swaps in the new one, restarts it
//   * the new worker must refresh worker.hb within a probation window, else we roll back
//
// While the worker is intentionally down during the swap, the supervisor keeps the PC marked
// "online" on the server (lightweight ?action=ping) so the connection is never lost.
//
// The supervisor itself almost never changes, so it rarely needs updating. Single-instance.
// Compile with the in-box .NET Framework compiler (see build.bat). No C# 6+ syntax.
using System;
using System.Diagnostics;
using System.Globalization;
using System.IO;
using System.Net;
using System.Security.Principal;
using System.Text;
using System.Threading;
using Microsoft.Win32;

class Supervisor
{
    const string TaskName = "rmdownloaderAgent";   // boot task now runs the supervisor
    const int ProbationSeconds = 90;               // new worker must check in within this
    const int HeartbeatFreshSeconds = 60;          // worker.hb counts as "alive" if newer than this
    const int PingEverySeconds = 15;               // keepalive cadence during a swap

    static string Server = "https://dos.argonar.co";
    static string Token = "";
    static string Dir, Worker, Staged, Backup, Flag, Heartbeat;
    static string AgentId = "";
    static Mutex _mutex;

    static void Main(string[] args)
    {
        Dir = AppDir();
        Worker    = Path.Combine(Dir, "Agent.exe");
        Staged    = Path.Combine(Dir, "Agent.new.exe");
        Backup    = Path.Combine(Dir, "Agent.prev.exe");
        Flag      = Path.Combine(Dir, "update.flag");
        Heartbeat = Path.Combine(Dir, "worker.hb");

        if (Embed.Server.Length > 0) Server = Embed.Server;
        if (Embed.Token.Length > 0) Token = Embed.Token;
        LoadConf();
        Server = Server.TrimEnd('/');
        AgentId = GetAgentId();

        bool first;
        _mutex = new Mutex(true, "Global\\rmdownloader-agentsvc-" + AgentId, out first);
        if (!first) { Console.WriteLine("supervisor already running; exiting."); return; }

        if (HasFlag(args, "--uninstall"))
        {
            RunSchtasks("/end /tn \"" + TaskName + "\"");
            RunSchtasks("/delete /tn \"" + TaskName + "\" /f");
            return;
        }
        if (!HasFlag(args, "--no-autostart")) EnsureAutoStart();

        try { ServicePointManager.SecurityProtocol = (SecurityProtocolType)(3072 | 768 | 192); } catch { }

        Console.WriteLine("rmdownloader supervisor  dir=" + Dir + "  server=" + Server);

        // A previous run may have staged an update but not finished the swap.
        if (PendingUpdate()) Swap();

        bool justSwapped = PendingProbationAfterStartupSwap();
        int crashes = 0;
        while (true)
        {
            if (!File.Exists(Worker))
            {
                if (File.Exists(Backup)) { SafeMove(Backup, Worker); }
                else { Console.WriteLine("worker missing: " + Worker + " (waiting)"); Thread.Sleep(10000); continue; }
            }

            try { File.Delete(Heartbeat); } catch { }   // require a fresh write from the worker we start
            DateTime started = DateTime.UtcNow;
            Process p = StartWorker();
            if (p == null) { Thread.Sleep(5000); continue; }

            if (justSwapped)
            {
                bool healthy = Probation(p);
                justSwapped = false;
                if (!healthy)
                {
                    Console.WriteLine("update unhealthy -> rolling back.");
                    try { if (!p.HasExited) p.Kill(); } catch { }
                    Rollback();
                    continue;     // loop restarts the restored worker
                }
                Console.WriteLine("update healthy; worker promoted.");
            }

            while (!p.HasExited) Thread.Sleep(1000);   // normal supervision

            if (PendingUpdate())
            {
                Swap();
                justSwapped = true;
                crashes = 0;
                continue;
            }

            double up = (DateTime.UtcNow - started).TotalSeconds;
            crashes = (up < 15) ? crashes + 1 : 0;     // crash-loop backoff
            int wait = Math.Min(60, Math.Max(2, 3 * crashes));
            Console.WriteLine("worker exited (up " + (int)up + "s); restarting in " + wait + "s.");
            Thread.Sleep(wait * 1000);
        }
    }

    // If we applied a startup swap above, the next worker we start is on probation.
    static bool _startupSwapped = false;
    static bool PendingProbationAfterStartupSwap() { return _startupSwapped; }

    static bool PendingUpdate() { return File.Exists(Flag) && File.Exists(Staged); }

    static void Swap()
    {
        try
        {
            if (File.Exists(Backup)) File.Delete(Backup);
            if (File.Exists(Worker)) File.Move(Worker, Backup);   // current -> prev
            File.Move(Staged, Worker);                            // staged  -> current
            Console.WriteLine("swapped in new worker.");
            _startupSwapped = true;
        }
        catch (Exception e) { Console.WriteLine("swap failed: " + e.Message); }
        try { File.Delete(Flag); } catch { }
    }

    static void Rollback()
    {
        try
        {
            if (File.Exists(Backup))
            {
                if (File.Exists(Worker)) File.Delete(Worker);
                File.Move(Backup, Worker);                         // prev -> current
                Console.WriteLine("rolled back to previous worker.");
            }
        }
        catch (Exception e) { Console.WriteLine("rollback failed: " + e.Message); }
        try { if (File.Exists(Staged)) File.Delete(Staged); } catch { }
        try { File.Delete(Flag); } catch { }
    }

    // The freshly-swapped worker must refresh the heartbeat (and stay alive) within the window.
    // Meanwhile keep the PC online so the connection survives the swap.
    static bool Probation(Process p)
    {
        DateTime deadline = DateTime.UtcNow.AddSeconds(ProbationSeconds);
        DateTime nextPing = DateTime.UtcNow;
        while (DateTime.UtcNow < deadline)
        {
            if (DateTime.UtcNow >= nextPing) { Ping(); nextPing = DateTime.UtcNow.AddSeconds(PingEverySeconds); }
            Thread.Sleep(1500);
            if (p.HasExited) return false;        // died during probation
            if (HeartbeatFresh()) return true;    // checked in -> healthy
        }
        return false;                              // timed out without checking in
    }

    static bool HeartbeatFresh()
    {
        try
        {
            if (!File.Exists(Heartbeat)) return false;
            return (DateTime.UtcNow - File.GetLastWriteTimeUtc(Heartbeat)).TotalSeconds < HeartbeatFreshSeconds;
        }
        catch { return false; }
    }

    static Process StartWorker()
    {
        try
        {
            ProcessStartInfo psi = new ProcessStartInfo(Worker, "--supervised");
            psi.UseShellExecute = false;
            psi.WorkingDirectory = Dir;
            return Process.Start(psi);
        }
        catch (Exception e) { Console.WriteLine("start worker failed: " + e.Message); return null; }
    }

    static void SafeMove(string from, string to)
    {
        try { if (File.Exists(to)) File.Delete(to); File.Move(from, to); } catch { }
    }

    // Keepalive: mark the PC online without claiming commands (so the worker isn't starved).
    static void Ping()
    {
        try
        {
            HttpWebRequest req = (HttpWebRequest)WebRequest.Create(Server + "/agent.php?action=ping");
            req.Method = "POST";
            req.Headers["X-Agent-Token"] = Token;
            req.Headers["X-Agent-Id"] = AgentId;
            req.Headers["X-Agent-Name"] = Environment.MachineName;
            req.ContentLength = 0;
            req.Timeout = 15000;
            req.ReadWriteTimeout = 15000;
            req.KeepAlive = false;
            using (HttpWebResponse resp = (HttpWebResponse)req.GetResponse())
            using (Stream s = resp.GetResponseStream())
            using (StreamReader sr = new StreamReader(s)) { sr.ReadToEnd(); }
        }
        catch { }
    }

    // ---- config (mirror the worker so the ping uses the same identity) ----
    static void LoadConf()
    {
        try
        {
            string conf = Path.Combine(Dir, "agent.conf");
            if (!File.Exists(conf)) return;
            foreach (string line in File.ReadAllLines(conf))
            {
                string s = line.Trim();
                if (s.Length == 0 || s[0] == '#') continue;
                int eq = s.IndexOf('=');
                if (eq < 0) continue;
                string k = s.Substring(0, eq).Trim().ToLowerInvariant();
                string v = s.Substring(eq + 1).Trim().Trim('"');
                if (k == "server") Server = v;
                else if (k == "token") Token = v;
            }
        }
        catch { }
    }

    static string GetAgentId()
    {
        try
        {
            using (RegistryKey k = RegistryKey.OpenBaseKey(RegistryHive.LocalMachine, RegistryView.Registry64)
                                              .OpenSubKey(@"SOFTWARE\Microsoft\Cryptography"))
            {
                if (k != null)
                {
                    object v = k.GetValue("MachineGuid");
                    if (v != null && v.ToString().Length > 0)
                        return Environment.MachineName.ToLowerInvariant() + "-" + v.ToString().Replace("{", "").Replace("}", "");
                }
            }
        }
        catch { }
        return Environment.MachineName.ToLowerInvariant();
    }

    static string AppDir()
    {
        try { return Path.GetDirectoryName(Process.GetCurrentProcess().MainModule.FileName); }
        catch { return Directory.GetCurrentDirectory(); }
    }

    static bool HasFlag(string[] args, string flag)
    {
        foreach (string a in args) if (string.Equals(a, flag, StringComparison.OrdinalIgnoreCase)) return true;
        return false;
    }

    static bool IsElevated()
    {
        try { return new WindowsPrincipal(WindowsIdentity.GetCurrent()).IsInRole(WindowsBuiltInRole.Administrator); }
        catch { return false; }
    }

    static int RunSchtasks(string arguments)
    {
        try
        {
            ProcessStartInfo psi = new ProcessStartInfo("schtasks.exe", arguments);
            psi.UseShellExecute = false;
            psi.CreateNoWindow = true;
            psi.RedirectStandardOutput = true;
            psi.RedirectStandardError = true;
            Process p = Process.Start(psi);
            p.StandardOutput.ReadToEnd();
            p.StandardError.ReadToEnd();
            p.WaitForExit();
            return p.ExitCode;
        }
        catch { return -1; }
    }

    // Point the boot task at THIS exe. When elevated, always (re)create so a migration from the
    // old worker-as-boot-task is automatic; when not elevated, only create if absent (don't
    // downgrade an existing SYSTEM task).
    static void EnsureAutoStart()
    {
        try
        {
            bool admin = IsElevated();
            bool exists = RunSchtasks("/query /tn \"" + TaskName + "\"") == 0;
            if (exists && !admin) return;
            string exe = Process.GetCurrentProcess().MainModule.FileName;
            string xml = BuildTaskXml(exe, admin);
            string tmp = Path.Combine(Path.GetTempPath(), "rmsvc_task.xml");
            File.WriteAllText(tmp, xml, Encoding.Unicode);   // schtasks /xml wants UTF-16
            int code = RunSchtasks("/create /tn \"" + TaskName + "\" /xml \"" + tmp + "\" /f");
            try { File.Delete(tmp); } catch { }
            Console.WriteLine(code == 0
                ? "boot task -> supervisor (" + (admin ? "SYSTEM/boot" : "user/logon") + ")."
                : "boot task not installed (schtasks code " + code + ").");
        }
        catch (Exception e) { Console.WriteLine("autostart skipped: " + e.Message); }
    }

    static string XmlEsc(string s)
    {
        return s.Replace("&", "&amp;").Replace("<", "&lt;").Replace(">", "&gt;").Replace("\"", "&quot;");
    }

    static string BuildTaskXml(string exe, bool admin)
    {
        string principal = admin
            ? "<UserId>S-1-5-18</UserId><RunLevel>HighestAvailable</RunLevel>"
            : "<UserId>" + XmlEsc(Environment.UserDomainName + "\\" + Environment.UserName) +
              "</UserId><LogonType>InteractiveToken</LogonType><RunLevel>LeastPrivilege</RunLevel>";
        string triggers = admin
            ? "<BootTrigger><Enabled>true</Enabled></BootTrigger><LogonTrigger><Enabled>true</Enabled></LogonTrigger>"
            : "<LogonTrigger><Enabled>true</Enabled></LogonTrigger>";
        return
"<?xml version=\"1.0\" encoding=\"UTF-16\"?>\r\n" +
"<Task version=\"1.2\" xmlns=\"http://schemas.microsoft.com/windows/2004/02/mit/task\">\r\n" +
"  <RegistrationInfo><Description>rmdownloader agent supervisor</Description></RegistrationInfo>\r\n" +
"  <Triggers>" + triggers + "</Triggers>\r\n" +
"  <Principals><Principal id=\"Author\">" + principal + "</Principal></Principals>\r\n" +
"  <Settings>\r\n" +
"    <MultipleInstancesPolicy>IgnoreNew</MultipleInstancesPolicy>\r\n" +
"    <DisallowStartIfOnBatteries>false</DisallowStartIfOnBatteries>\r\n" +
"    <StopIfGoingOnBatteries>false</StopIfGoingOnBatteries>\r\n" +
"    <AllowHardTerminate>true</AllowHardTerminate>\r\n" +
"    <StartWhenAvailable>true</StartWhenAvailable>\r\n" +
"    <RunOnlyIfNetworkAvailable>false</RunOnlyIfNetworkAvailable>\r\n" +
"    <ExecutionTimeLimit>PT0S</ExecutionTimeLimit>\r\n" +
"    <Hidden>true</Hidden>\r\n" +
"    <Enabled>true</Enabled>\r\n" +
"    <RestartOnFailure><Interval>PT1M</Interval><Count>999</Count></RestartOnFailure>\r\n" +
"  </Settings>\r\n" +
"  <Actions Context=\"Author\"><Exec><Command>" + XmlEsc(exe) + "</Command></Exec></Actions>\r\n" +
"</Task>\r\n";
    }
}
