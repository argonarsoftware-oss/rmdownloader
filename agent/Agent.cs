// rmdownloader agent  --  reverse-connect model.
// The agent makes only OUTBOUND HTTPS calls to the VPS: it long-polls for commands,
// runs them locally, and posts results back. No inbound ports, no tunnel.
// Just run this exe (configure server + token in agent.conf).
//
// Compile with the in-box .NET Framework compiler (see build.bat) - no installs needed.
using System;
using System.Collections.Generic;
using System.Globalization;
using System.IO;
using System.Net;
using System.Text;
using System.Threading;
using System.Web.Script.Serialization;

class Agent
{
    static string Server = "https://dos.argonar.co";
    static string Token = "change-me-please";
    static string Root = "";                       // optional sandbox; empty = whole machine
    static readonly JavaScriptSerializer J = new JavaScriptSerializer();

    static void Main(string[] args)
    {
        // Config sources, in increasing priority: built-in defaults -> agent.conf (optional)
        // -> command-line. No config file is required: just run  Agent.exe <token>.
        LoadConfig();
        var positional = new System.Collections.Generic.List<string>();
        bool tokenFromArg = false;
        for (int i = 0; i < args.Length; i++)
        {
            string a = args[i];
            if (a == "--server" && i + 1 < args.Length) { Server = args[++i]; }
            else if (a == "--token" && i + 1 < args.Length) { Token = args[++i]; tokenFromArg = true; }
            else if (a == "--root" && i + 1 < args.Length) { Root = args[++i]; }
            else positional.Add(a);
        }
        // First bare argument is the token:  Agent.exe <token>
        if (!tokenFromArg && positional.Count >= 1) Token = positional[0];
        Server = Server.TrimEnd('/');

        if (Token.Length == 0 || Token == "change-me-please" || Token == "CHANGE-THIS-TO-A-LONG-RANDOM-SECRET")
        {
            Console.WriteLine("No token set. Run:  Agent.exe <token>   (or set token in agent.conf)");
        }
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
            default:         return Err("unknown op: " + op);
        }
    }

    static Dictionary<string, object> DoInfo()
    {
        var r = Ok();
        r["host"] = Environment.MachineName;
        r["user"] = Environment.UserName;
        r["os"] = Environment.OSVersion.ToString();
        r["sandbox"] = Root;
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

    // ---- HTTP ----

    static string HttpReq(string pathAndQuery, byte[] body)
    {
        HttpWebRequest req = (HttpWebRequest)WebRequest.Create(Server + pathAndQuery);
        req.Method = "POST";
        req.Headers["X-Agent-Token"] = Token;
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
}
