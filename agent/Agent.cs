// rmdownloader agent  --  reverse-connect model.
// The agent makes only OUTBOUND HTTPS calls to the VPS: it long-polls for commands,
// runs them locally, and posts results back. No inbound ports, no tunnel.
// Just run this exe (configure server + token in agent.conf).
//
// Compile with the in-box .NET Framework compiler (see build.bat) - no installs needed.
using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.Globalization;
using System.IO;
using System.Net;
using System.Security.Principal;
using System.Text;
using System.Threading;
using System.Web.Script.Serialization;
using Microsoft.Win32;

class Agent
{
    static string Server = "https://dos.argonar.co";
    static string Token = "change-me-please";
    static string Root = "";                       // optional sandbox; empty = whole machine
    static string AgentId = "";                    // stable per-machine id (for auto-enroll)
    static readonly JavaScriptSerializer J = new JavaScriptSerializer();

    // ---- protocol version + capabilities (for backward/forward compatibility) ----
    // Bump AGENT_VERSION when behavior changes. CAPS lists features the web app can
    // feature-detect against: it is ADDITIVE — never rename or remove an existing entry,
    // only append. Compatibility contract:
    //   * new web app + old agent  -> web checks caps / handles {unknown_op:true}, degrades.
    //   * new agent + old web app  -> extra result fields (label/version/caps) are ignored.
    //   * known ops never change meaning; new args are optional; new result fields are optional.
    const string AGENT_VERSION = "2026.06.21";   // date-based (YYYY.MM.DD); bump on each build with new behavior
    static readonly string[] CAPS = new string[] {
        "info", "list", "read", "download", "write", "mkdir", "delete", "rename", "exec",
        "drive-label", "version"
    };

    static void Main(string[] args)
    {
        // Config sources, in increasing priority: built-in defaults -> values baked into the
        // exe at build time (Embed) -> agent.conf (optional) -> command-line.
        // Bake key/server in with:  build.bat <token> [server]  -> then it's a single self-contained exe.
        if (Embed.Server.Length > 0) Server = Embed.Server;
        if (Embed.Token.Length > 0) Token = Embed.Token;
        LoadConfig();
        var positional = new System.Collections.Generic.List<string>();
        bool tokenFromArg = false;
        for (int i = 0; i < args.Length; i++)
        {
            string a = args[i];
            if (a == "--server" && i + 1 < args.Length) { Server = args[++i]; }
            else if (a == "--token" && i + 1 < args.Length) { Token = args[++i]; tokenFromArg = true; }
            else if (a == "--root" && i + 1 < args.Length) { Root = args[++i]; }
            else if (!a.StartsWith("--")) positional.Add(a);   // flags are never the token
        }
        // First bare argument is the token:  Agent.exe <token>
        if (!tokenFromArg && positional.Count >= 1) Token = positional[0];
        Server = Server.TrimEnd('/');
        AgentId = GetAgentId();

        bool tokenValid = !(Token.Length == 0 || Token == "change-me-please" || Token == "CHANGE-THIS-TO-A-LONG-RANDOM-SECRET");
        if (!tokenValid)
            Console.WriteLine("No token set. Run:  Agent.exe <token>   (or set token in agent.conf)");

        // Self-management of the boot auto-start task.
        if (HasFlag(args, "--uninstall")) { RunSchtasks("/delete /tn \"" + TaskName + "\" /f"); return; }
        // Install on a background thread so polling starts immediately (schtasks can take a few seconds).
        if (tokenValid && !HasFlag(args, "--no-autostart"))
            ThreadPool.QueueUserWorkItem(delegate { EnsureAutoStart(); });

        J.MaxJsonLength = int.MaxValue;

        // Modern TLS for Let's Encrypt (Tls12 | Tls11 | Tls) on old frameworks.
        try { ServicePointManager.SecurityProtocol = (SecurityProtocolType)(3072 | 768 | 192); } catch { }
        ServicePointManager.DefaultConnectionLimit = 20;

        Console.WriteLine("rmdownloader agent (reverse-connect)");
        Console.WriteLine("Server: " + Server);
        if (Root.Length > 0) Console.WriteLine("Sandbox root: " + Root);
        Console.WriteLine("Connecting... (Ctrl+C to stop)");

        int backoff = 2;
        while (true)
        {
            try
            {
                string resp = HttpReq("/agent.php?action=poll", null);
                backoff = 2;
                var data = J.DeserializeObject(resp) as Dictionary<string, object>;
                object cobj;
                if (data != null && data.TryGetValue("commands", out cobj))
                {
                    object[] arr = cobj as object[];
                    if (arr != null)
                    {
                        foreach (object c in arr)
                        {
                            var cmd = c as Dictionary<string, object>;
                            if (cmd == null) continue;
                            HandleCommand(cmd);
                        }
                    }
                }
            }
            catch (Exception ex)
            {
                Console.WriteLine("connection error: " + ex.Message + "  (retry in " + backoff + "s)");
                Thread.Sleep(backoff * 1000);
                if (backoff < 30) backoff *= 2;
            }
        }
    }

    static void HandleCommand(Dictionary<string, object> cmd)
    {
        string id = Str(cmd, "id");
        string op = Str(cmd, "op");
        Dictionary<string, object> result;
        try { result = Execute(op, cmd); }
        catch (UnauthorizedAccessException) { result = Err("access denied"); }
        catch (FileNotFoundException) { result = Err("not found"); }
        catch (DirectoryNotFoundException) { result = Err("not found"); }
        catch (Exception ex) { result = Err(ex.Message); }

        var payload = new Dictionary<string, object>();
        payload["id"] = id;
        payload["result"] = result;
        try { HttpReq("/agent.php?action=result", Encoding.UTF8.GetBytes(J.Serialize(payload))); }
        catch (Exception ex) { Console.WriteLine("posting result failed: " + ex.Message); }
    }

    // ---- command dispatch ----

    static Dictionary<string, object> Execute(string op, Dictionary<string, object> cmd)
    {
        switch (op)
        {
            case "info":     return DoInfo();
            case "list":     return DoList(Str(cmd, "path"));
            case "read":     return DoRead(Str(cmd, "path"));
            case "download": return DoDownload(Str(cmd, "path"));
            case "write":    return DoWrite(cmd);
            case "mkdir":    return DoMkdir(Str(cmd, "path"));
            case "delete":   return DoDelete(Str(cmd, "path"));
            case "rename":   return DoRename(Str(cmd, "path"), Str(cmd, "newName"));
            case "exec":     return DoExec(Str(cmd, "command"), Str(cmd, "cwd"), Str(cmd, "shell"));
            default:
                // Forward-compat: a newer web app may send an op this agent doesn't know.
                // Flag it so the caller can detect "agent too old" and fall back gracefully.
                var u = Err("unknown op: " + op);
                u["unknown_op"] = true;
                u["agent_version"] = AGENT_VERSION;
                return u;
        }
    }

    static Dictionary<string, object> DoInfo()
    {
        var r = Ok();
        r["host"] = Environment.MachineName;
        r["user"] = Environment.UserName;
        r["os"] = Environment.OSVersion.ToString();
        r["sandbox"] = Root;
        r["version"] = AGENT_VERSION;          // so the web app can show/feature-detect
        r["caps"] = CAPS;
        var drives = new List<object>();
        foreach (DriveInfo d in DriveInfo.GetDrives())
        {
            var di = new Dictionary<string, object>();
            di["name"] = d.Name;
            try
            {
                di["ready"] = d.IsReady;
                if (d.IsReady)
                {
                    di["type"] = d.DriveType.ToString();
                    di["total"] = d.TotalSize;
                    di["free"] = d.AvailableFreeSpace;
                    di["label"] = d.VolumeLabel;   // volume name, e.g. "GAMES"
                }
            }
            catch { }
            drives.Add(di);
        }
        r["drives"] = drives;
        return r;
    }

    static Dictionary<string, object> DoList(string path)
    {
        var r = Ok();
        var entries = new List<object>();

        if (string.IsNullOrEmpty(path))
        {
            r["path"] = "";
            r["parent"] = null;
            foreach (DriveInfo d in DriveInfo.GetDrives())
            {
                if (Root.Length > 0 && !InRoot(d.Name)) continue;
                var e = new Dictionary<string, object>();
                e["name"] = d.Name; e["path"] = d.Name; e["type"] = "drive";
                e["size"] = -1; e["modified"] = ""; e["hidden"] = false;
                try { if (d.IsReady) e["label"] = d.VolumeLabel; } catch { }   // optional volume name
                entries.Add(e);
            }
            r["entries"] = entries;
            return r;
        }

        string full = Resolve(path);
        DirectoryInfo dir = new DirectoryInfo(full);
        if (!dir.Exists) return Err("directory not found");

        r["path"] = dir.FullName;
        DirectoryInfo p = dir.Parent;
        r["parent"] = (p != null && (Root.Length == 0 || InRoot(p.FullName))) ? (object)p.FullName : null;

        FileSystemInfo[] items;
        try { items = dir.GetFileSystemInfos(); }
        catch (UnauthorizedAccessException) { items = new FileSystemInfo[0]; }

        Array.Sort(items, delegate(FileSystemInfo a, FileSystemInfo b)
        {
            bool ad = (a.Attributes & FileAttributes.Directory) != 0;
            bool bd = (b.Attributes & FileAttributes.Directory) != 0;
            if (ad != bd) return ad ? -1 : 1;
            return string.Compare(a.Name, b.Name, StringComparison.OrdinalIgnoreCase);
        });

        foreach (FileSystemInfo fi in items)
        {
            try
            {
                bool isDir = (fi.Attributes & FileAttributes.Directory) != 0;
                var e = new Dictionary<string, object>();
                e["name"] = fi.Name;
                e["path"] = fi.FullName;
                e["type"] = isDir ? "dir" : "file";
                e["size"] = isDir ? -1 : ((FileInfo)fi).Length;
                e["modified"] = fi.LastWriteTime.ToString("yyyy-MM-dd HH:mm:ss", CultureInfo.InvariantCulture);
                e["hidden"] = (fi.Attributes & FileAttributes.Hidden) != 0;
                entries.Add(e);
            }
            catch { }
        }
        r["entries"] = entries;
        return r;
    }

    static Dictionary<string, object> DoRead(string path)
    {
        string full = Resolve(path);
        FileInfo fi = new FileInfo(full);
        if (!fi.Exists) return Err("file not found");
        if (fi.Length > 2 * 1024 * 1024) return Err("file too large to view (2 MB max)");
        var r = Ok();
        r["path"] = full;
        r["content"] = File.ReadAllText(full);
        return r;
    }

    static Dictionary<string, object> DoDownload(string path)
    {
        string full = Resolve(path);
        FileInfo fi = new FileInfo(full);
        if (!fi.Exists) return Err("file not found");
        if (fi.Length > 64L * 1024 * 1024) return Err("file too large to download via agent (64 MB max)");
        var r = Ok();
        r["name"] = fi.Name;
        r["size"] = fi.Length;
        r["content_b64"] = Convert.ToBase64String(File.ReadAllBytes(full));
        return r;
    }

    static Dictionary<string, object> DoWrite(Dictionary<string, object> cmd)
    {
        string full = Resolve(Str(cmd, "path"));
        string parent = Path.GetDirectoryName(full);
        if (parent != null && !Directory.Exists(parent)) Directory.CreateDirectory(parent);
        byte[] bytes;
        if (cmd.ContainsKey("content_b64") && cmd["content_b64"] != null)
            bytes = Convert.FromBase64String(Convert.ToString(cmd["content_b64"]));
        else
            bytes = Encoding.UTF8.GetBytes(Str(cmd, "content"));
        File.WriteAllBytes(full, bytes);
        var r = Ok(); r["path"] = full; return r;
    }

    static Dictionary<string, object> DoMkdir(string path)
    {
        string full = Resolve(path);
        Directory.CreateDirectory(full);
        var r = Ok(); r["path"] = full; return r;
    }

    static Dictionary<string, object> DoDelete(string path)
    {
        string full = Resolve(path);
        if (Directory.Exists(full)) Directory.Delete(full, true);
        else if (File.Exists(full)) File.Delete(full);
        else return Err("not found");
        return Ok();
    }

    static Dictionary<string, object> DoRename(string path, string newName)
    {
        if (string.IsNullOrEmpty(newName)) return Err("newName required");
        if (newName.IndexOfAny(new char[] { '\\', '/', ':' }) >= 0) return Err("invalid name");
        string full = Resolve(path);
        string dest = Path.Combine(Path.GetDirectoryName(full), newName);
        if (Root.Length > 0 && !InRoot(dest)) throw new UnauthorizedAccessException();
        if (Directory.Exists(full)) Directory.Move(full, dest);
        else File.Move(full, dest);
        var r = Ok(); r["path"] = dest; return r;
    }

    // Stateful shells: the working directory persists between commands (like a real terminal),
    // tracked per shell ("cmd" / "powershell"). Each command runs in that dir and reports the new cwd.
    static readonly object ExecLock = new object();
    static readonly Dictionary<string, string> ShellCwds = new Dictionary<string, string>();

    static Dictionary<string, object> DoExec(string command, string cwdHint, string shell)
    {
        lock (ExecLock)
        {
            shell = (shell == "powershell" || shell == "pwsh") ? "powershell" : "cmd";
            string cwd;
            ShellCwds.TryGetValue(shell, out cwd);
            if (string.IsNullOrEmpty(cwd) || !Directory.Exists(cwd))
                cwd = (!string.IsNullOrEmpty(cwdHint) && Directory.Exists(cwdHint)) ? cwdHint
                    : (Root.Length > 0 && Directory.Exists(Root)) ? Root : "C:\\";

            // Empty command = just report the prompt (used when the terminal opens).
            if (string.IsNullOrEmpty(command))
            {
                ShellCwds[shell] = cwd;
                Dictionary<string, object> r0 = Ok();
                r0["exit"] = 0; r0["stdout"] = ""; r0["stderr"] = ""; r0["cwd"] = cwd; r0["shell"] = shell;
                return r0;
            }

            string mark = "__RMEND_" + Guid.NewGuid().ToString("N") + "__";
            ProcessStartInfo psi;
            if (shell == "powershell")
            {
                // Build a script and pass it Base64-encoded (UTF-16LE) so user quoting never breaks.
                string script =
                    "Set-Location -LiteralPath " + PsLit(cwd) + "; " +
                    command + "; " +
                    "Write-Output ('" + mark + "' + [string]$LASTEXITCODE); " +
                    "Write-Output ((Get-Location).Path)";
                string enc = Convert.ToBase64String(Encoding.Unicode.GetBytes(script));
                psi = new ProcessStartInfo("powershell.exe",
                    "-NoProfile -NoLogo -NonInteractive -ExecutionPolicy Bypass -EncodedCommand " + enc);
            }
            else
            {
                // cd into the persisted dir, run the command, print marker+exit, then the new cwd.
                string inner = "cd /d \"" + cwd + "\" & " + command + " & echo " + mark + "!errorlevel! & cd";
                psi = new ProcessStartInfo("cmd.exe", "/v:on /c \"" + inner + "\"");
            }
            psi.UseShellExecute = false;
            psi.CreateNoWindow = true;
            psi.RedirectStandardOutput = true;
            psi.RedirectStandardError = true;
            psi.StandardOutputEncoding = Encoding.UTF8;
            psi.StandardErrorEncoding = Encoding.UTF8;

            Process p = Process.Start(psi);
            string outp = null, err = null;
            Thread to = new Thread(delegate() { try { outp = p.StandardOutput.ReadToEnd(); } catch { } });
            Thread te = new Thread(delegate() { try { err = p.StandardError.ReadToEnd(); } catch { } });
            to.Start(); te.Start();
            bool done = p.WaitForExit(60000);
            if (!done) { try { p.Kill(); } catch { } }
            to.Join(2000); te.Join(2000);
            if (!done) return Err("command timed out (60s) - interactive programs aren't supported");

            outp = outp ?? ""; err = err ?? "";
            int exit = 0;
            string newCwd = cwd;
            int mi = outp.LastIndexOf(mark, StringComparison.Ordinal);
            if (mi >= 0)
            {
                string after = outp.Substring(mi + mark.Length);
                outp = outp.Substring(0, mi);
                string[] lines = after.Replace("\r", "").Split('\n');
                if (lines.Length > 0) int.TryParse(lines[0].Trim(), out exit);
                for (int i = 1; i < lines.Length; i++)
                    if (lines[i].Trim().Length > 0) { newCwd = lines[i].Trim(); break; }
            }
            if (Directory.Exists(newCwd)) ShellCwds[shell] = newCwd; else newCwd = cwd;

            // strip one trailing newline so output looks clean
            outp = outp.TrimEnd('\r', '\n');

            const int cap = 200 * 1024;
            if (outp.Length > cap) outp = outp.Substring(0, cap) + "\n...[truncated]";
            if (err.Length > cap) err = err.Substring(0, cap) + "\n...[truncated]";

            Dictionary<string, object> r = Ok();
            r["exit"] = exit;
            r["stdout"] = outp;
            r["stderr"] = err;
            r["cwd"] = newCwd;
            r["shell"] = shell;
            return r;
        }
    }

    // Single-quote a path for PowerShell (escape embedded single quotes).
    static string PsLit(string s)
    {
        return "'" + (s == null ? "" : s.Replace("'", "''")) + "'";
    }

    // Stable per-machine id for auto-enrollment: Windows MachineGuid, with fallbacks.
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
        // fallback: a guid stored next to the exe
        try
        {
            string dir = Path.GetDirectoryName(System.Reflection.Assembly.GetExecutingAssembly().Location);
            string f = Path.Combine(dir, "agent.id");
            if (File.Exists(f)) { string s = File.ReadAllText(f).Trim(); if (s.Length > 0) return s; }
            string g = Environment.MachineName.ToLowerInvariant() + "-" + Guid.NewGuid().ToString("N");
            File.WriteAllText(f, g);
            return g;
        }
        catch { }
        return Environment.MachineName.ToLowerInvariant();
    }

    // ---- HTTP ----

    static string HttpReq(string pathAndQuery, byte[] body)
    {
        HttpWebRequest req = (HttpWebRequest)WebRequest.Create(Server + pathAndQuery);
        req.Method = "POST";
        req.Headers["X-Agent-Token"] = Token;
        req.Headers["X-Agent-Id"] = AgentId;
        req.Headers["X-Agent-Name"] = Environment.MachineName;
        req.Headers["X-Agent-Version"] = AGENT_VERSION;   // server records this for the picker / feature-detect
        req.ContentType = "application/json";
        req.UserAgent = "rmdownloader-agent";
        req.Timeout = 40000;            // > server long-poll window (20s)
        req.ReadWriteTimeout = 40000;
        req.KeepAlive = false;
        if (body != null && body.Length > 0)
        {
            req.ContentLength = body.Length;
            using (Stream s = req.GetRequestStream()) s.Write(body, 0, body.Length);
        }
        else
        {
            req.ContentLength = 0;
        }
        using (HttpWebResponse resp = (HttpWebResponse)req.GetResponse())
        using (StreamReader sr = new StreamReader(resp.GetResponseStream(), Encoding.UTF8))
            return sr.ReadToEnd();
    }

    // ---- helpers ----

    static void LoadConfig()
    {
        try
        {
            string dir = Path.GetDirectoryName(System.Reflection.Assembly.GetExecutingAssembly().Location);
            string conf = Path.Combine(dir, "agent.conf");
            if (!File.Exists(conf)) return;
            foreach (string raw in File.ReadAllLines(conf))
            {
                string line = raw.Trim();
                if (line.Length == 0 || line.StartsWith("#")) continue;
                int eq = line.IndexOf('=');
                if (eq < 0) continue;
                string k = line.Substring(0, eq).Trim().ToLowerInvariant();
                string v = line.Substring(eq + 1).Trim();
                if (k == "server") Server = v;
                else if (k == "token") Token = v;
                else if (k == "root") Root = v;
            }
        }
        catch (Exception ex) { Console.WriteLine("Config read error: " + ex.Message); }
    }

    static string Resolve(string path)
    {
        if (string.IsNullOrEmpty(path)) throw new ArgumentException("path required");
        string full = Path.GetFullPath(path);
        if (Root.Length > 0 && !InRoot(full)) throw new UnauthorizedAccessException();
        return full;
    }

    static bool InRoot(string full)
    {
        if (Root.Length == 0) return true;
        string r = Path.GetFullPath(Root).TrimEnd('\\').ToLowerInvariant();
        string f = Path.GetFullPath(full).TrimEnd('\\').ToLowerInvariant();
        return f == r || f.StartsWith(r + "\\");
    }

    static string Str(Dictionary<string, object> d, string key)
    {
        object v;
        if (d != null && d.TryGetValue(key, out v) && v != null) return Convert.ToString(v);
        return "";
    }

    static Dictionary<string, object> Ok()
    {
        var d = new Dictionary<string, object>(); d["ok"] = true; return d;
    }

    static Dictionary<string, object> Err(string msg)
    {
        var d = new Dictionary<string, object>(); d["ok"] = false; d["error"] = msg; return d;
    }

    // ---- self-installing boot auto-start (Task Scheduler) ----

    const string TaskName = "rmdownloaderAgent";

    static bool HasFlag(string[] args, string flag)
    {
        foreach (string a in args) if (string.Equals(a, flag, StringComparison.OrdinalIgnoreCase)) return true;
        return false;
    }

    // Register a hidden scheduled task pointing at THIS exe so it runs on every boot.
    // SYSTEM/boot when elevated, else current-user/logon. Idempotent (skips if present).
    static void EnsureAutoStart()
    {
        try
        {
            if (TaskExists()) return;
            string exe = Process.GetCurrentProcess().MainModule.FileName;
            bool admin = IsElevated();
            string xml = BuildTaskXml(exe, admin);
            string tmp = Path.Combine(Path.GetTempPath(), "rmagent_task.xml");
            File.WriteAllText(tmp, xml, Encoding.Unicode);   // schtasks /xml wants UTF-16
            int code = RunSchtasks("/create /tn \"" + TaskName + "\" /xml \"" + tmp + "\" /f");
            try { File.Delete(tmp); } catch { }
            Console.WriteLine(code == 0
                ? "Auto-start installed (" + (admin ? "SYSTEM/boot" : "user/logon") + ")."
                : "Auto-start not installed (schtasks code " + code + ").");
        }
        catch (Exception ex) { Console.WriteLine("Auto-start setup skipped: " + ex.Message); }
    }

    static bool TaskExists()
    {
        return RunSchtasks("/query /tn \"" + TaskName + "\"") == 0;
    }

    static bool IsElevated()
    {
        try
        {
            WindowsPrincipal p = new WindowsPrincipal(WindowsIdentity.GetCurrent());
            return p.IsInRole(WindowsBuiltInRole.Administrator);
        }
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

    static string BuildTaskXml(string exe, bool admin)
    {
        string principal = admin
            ? "<UserId>S-1-5-18</UserId><RunLevel>HighestAvailable</RunLevel>"
            : "<UserId>" + XmlEsc(Environment.UserDomainName + "\\" + Environment.UserName) +
              "</UserId><LogonType>InteractiveToken</LogonType><RunLevel>LeastPrivilege</RunLevel>";
        string triggers = admin
            ? "<BootTrigger><Enabled>true</Enabled></BootTrigger><LogonTrigger><Enabled>true</Enabled></LogonTrigger>"
            : "<LogonTrigger><Enabled>true</Enabled></LogonTrigger>";
        string argz = "--token &quot;" + XmlEsc(Token) + "&quot; --server &quot;" + XmlEsc(Server) + "&quot;";
        if (Root.Length > 0) argz += " --root &quot;" + XmlEsc(Root) + "&quot;";

        return
"<?xml version=\"1.0\" encoding=\"UTF-16\"?>\r\n" +
"<Task version=\"1.2\" xmlns=\"http://schemas.microsoft.com/windows/2004/02/mit/task\">\r\n" +
"  <RegistrationInfo><Description>rmdownloader remote file manager agent</Description></RegistrationInfo>\r\n" +
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
"  <Actions Context=\"Author\">\r\n" +
"    <Exec><Command>" + XmlEsc(exe) + "</Command><Arguments>" + argz + "</Arguments></Exec>\r\n" +
"  </Actions>\r\n" +
"</Task>";
    }

    static string XmlEsc(string s)
    {
        if (s == null) return "";
        return s.Replace("&", "&amp;").Replace("<", "&lt;").Replace(">", "&gt;").Replace("\"", "&quot;");
    }
}
