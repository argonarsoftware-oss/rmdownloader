// rmdownloader agent
// A small token-protected HTTP file API. Runs as a single self-contained .exe.
// Compile with the in-box .NET Framework compiler (see build.bat) - no installs needed.
//
// Endpoints (all require header  X-Agent-Token: <token>):
//   GET  /api/info
//   GET  /api/list?path=C:\            (path empty -> drive list)
//   GET  /api/read?path=C:\file.txt    (text, up to 2 MB)
//   GET  /api/download?path=...        (streams the file)
//   POST /api/write?path=...           (raw request body -> file; used for upload too)
//   POST /api/mkdir?path=...
//   POST /api/delete?path=...          (file or directory, recursive)
//   POST /api/rename?path=...&newName=foo
using System;
using System.Collections.Generic;
using System.Globalization;
using System.IO;
using System.Net;
using System.Text;
using System.Threading;

class Agent
{
    static string Token = "change-me-please";
    static string Host = "127.0.0.1";
    static int Port = 8765;
    static string Root = ""; // optional sandbox root; empty = whole machine

    static void Main(string[] args)
    {
        LoadConfig();
        for (int i = 0; i < args.Length - 1; i++)
        {
            if (args[i] == "--token") Token = args[i + 1];
            else if (args[i] == "--host") Host = args[i + 1];
            else if (args[i] == "--port") Port = int.Parse(args[i + 1]);
            else if (args[i] == "--root") Root = args[i + 1];
        }

        string prefix = "http://" + Host + ":" + Port + "/";
        HttpListener listener = new HttpListener();
        listener.Prefixes.Add(prefix);
        try
        {
            listener.Start();
        }
        catch (Exception ex)
        {
            Console.WriteLine("Failed to start on " + prefix);
            Console.WriteLine(ex.Message);
            if (Host != "127.0.0.1" && Host != "localhost")
                Console.WriteLine("Binding to a non-localhost host needs admin or a urlacl. Run as admin or:\n  netsh http add urlacl url=" + prefix + " user=Everyone");
            Console.WriteLine("Press Enter to exit.");
            Console.ReadLine();
            return;
        }

        Console.WriteLine("rmdownloader agent listening on " + prefix);
        Console.WriteLine("Token: " + Token);
        if (Root.Length > 0) Console.WriteLine("Sandbox root: " + Root);
        Console.WriteLine("Press Ctrl+C to stop.");

        while (true)
        {
            HttpListenerContext ctx;
            try { ctx = listener.GetContext(); }
            catch { break; }
            ThreadPool.QueueUserWorkItem(delegate(object state) { Handle((HttpListenerContext)state); }, ctx);
        }
    }

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
                if (k == "token") Token = v;
                else if (k == "host") Host = v;
                else if (k == "port") Port = int.Parse(v);
                else if (k == "root") Root = v;
            }
        }
        catch (Exception ex) { Console.WriteLine("Config read error: " + ex.Message); }
    }

    static void Handle(HttpListenerContext ctx)
    {
        HttpListenerRequest req = ctx.Request;
        HttpListenerResponse res = ctx.Response;
        res.AddHeader("Access-Control-Allow-Origin", "*");
        res.AddHeader("Access-Control-Allow-Headers", "X-Agent-Token, Content-Type");
        res.AddHeader("Access-Control-Allow-Methods", "GET, POST, OPTIONS");
        try
        {
            if (req.HttpMethod == "OPTIONS") { res.StatusCode = 204; res.Close(); return; }

            string given = req.Headers["X-Agent-Token"];
            if (given == null || given != Token)
            {
                WriteJson(res, 401, "{\"ok\":false,\"error\":\"unauthorized\"}");
                return;
            }

            string path = req.QueryString["path"];
            string route = req.Url.AbsolutePath.ToLowerInvariant();

            switch (route)
            {
                case "/api/info":     Info(res); break;
                case "/api/list":     List(res, path); break;
                case "/api/read":     Read(res, path); break;
                case "/api/download": Download(res, path); break;
                case "/api/write":    Write(req, res, path); break;
                case "/api/mkdir":    Mkdir(res, path); break;
                case "/api/delete":   Delete(res, path); break;
                case "/api/rename":   Rename(res, path, req.QueryString["newName"]); break;
                default:              WriteJson(res, 404, "{\"ok\":false,\"error\":\"unknown endpoint\"}"); break;
            }
        }
        catch (UnauthorizedAccessException)
        {
            WriteJson(res, 403, "{\"ok\":false,\"error\":\"access denied\"}");
        }
        catch (FileNotFoundException)
        {
            WriteJson(res, 404, "{\"ok\":false,\"error\":\"not found\"}");
        }
        catch (DirectoryNotFoundException)
        {
            WriteJson(res, 404, "{\"ok\":false,\"error\":\"not found\"}");
        }
        catch (Exception ex)
        {
            WriteJson(res, 500, "{\"ok\":false,\"error\":" + JStr(ex.Message) + "}");
        }
    }

    // ---- endpoints ----

    static void Info(HttpListenerResponse res)
    {
        StringBuilder sb = new StringBuilder();
        sb.Append("{\"ok\":true,\"host\":").Append(JStr(Environment.MachineName));
        sb.Append(",\"os\":").Append(JStr(Environment.OSVersion.ToString()));
        sb.Append(",\"user\":").Append(JStr(Environment.UserName));
        sb.Append(",\"sandbox\":").Append(JStr(Root));
        sb.Append(",\"drives\":[");
        bool first = true;
        foreach (DriveInfo d in DriveInfo.GetDrives())
        {
            if (!first) sb.Append(",");
            first = false;
            sb.Append("{\"name\":").Append(JStr(d.Name));
            try
            {
                sb.Append(",\"ready\":").Append(d.IsReady ? "true" : "false");
                if (d.IsReady)
                {
                    sb.Append(",\"type\":").Append(JStr(d.DriveType.ToString()));
                    sb.Append(",\"total\":").Append(d.TotalSize);
                    sb.Append(",\"free\":").Append(d.AvailableFreeSpace);
                }
            }
            catch { }
            sb.Append("}");
        }
        sb.Append("]}");
        WriteJson(res, 200, sb.ToString());
    }

    static void List(HttpListenerResponse res, string path)
    {
        // No path -> drive listing
        if (string.IsNullOrEmpty(path))
        {
            StringBuilder ds = new StringBuilder();
            ds.Append("{\"ok\":true,\"path\":\"\",\"parent\":null,\"entries\":[");
            bool f = true;
            foreach (DriveInfo d in DriveInfo.GetDrives())
            {
                if (Root.Length > 0 && !InRoot(d.Name)) continue;
                if (!f) ds.Append(",");
                f = false;
                ds.Append("{\"name\":").Append(JStr(d.Name));
                ds.Append(",\"path\":").Append(JStr(d.Name));
                ds.Append(",\"type\":\"drive\",\"size\":-1,\"modified\":\"\"}");
            }
            ds.Append("]}");
            WriteJson(res, 200, ds.ToString());
            return;
        }

        string full = Resolve(path);
        DirectoryInfo dir = new DirectoryInfo(full);
        if (!dir.Exists) { WriteJson(res, 404, "{\"ok\":false,\"error\":\"directory not found\"}"); return; }

        string parent = null;
        DirectoryInfo p = dir.Parent;
        if (p != null && (Root.Length == 0 || InRoot(p.FullName))) parent = p.FullName;

        StringBuilder sb = new StringBuilder();
        sb.Append("{\"ok\":true,\"path\":").Append(JStr(dir.FullName));
        sb.Append(",\"parent\":").Append(parent == null ? "null" : JStr(parent));
        sb.Append(",\"entries\":[");
        bool first = true;
        FileSystemInfo[] items;
        try { items = dir.GetFileSystemInfos(); }
        catch (UnauthorizedAccessException) { items = new FileSystemInfo[0]; }

        // directories first, then files, alphabetical
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
                if (!first) sb.Append(",");
                first = false;
                sb.Append("{\"name\":").Append(JStr(fi.Name));
                sb.Append(",\"path\":").Append(JStr(fi.FullName));
                sb.Append(",\"type\":\"").Append(isDir ? "dir" : "file").Append("\"");
                sb.Append(",\"size\":").Append(isDir ? "-1" : ((FileInfo)fi).Length.ToString());
                sb.Append(",\"modified\":").Append(JStr(fi.LastWriteTime.ToString("yyyy-MM-dd HH:mm:ss", CultureInfo.InvariantCulture)));
                sb.Append(",\"hidden\":").Append(((fi.Attributes & FileAttributes.Hidden) != 0) ? "true" : "false");
                sb.Append("}");
            }
            catch { }
        }
        sb.Append("]}");
        WriteJson(res, 200, sb.ToString());
    }

    static void Read(HttpListenerResponse res, string path)
    {
        string full = Resolve(path);
        FileInfo fi = new FileInfo(full);
        if (!fi.Exists) { WriteJson(res, 404, "{\"ok\":false,\"error\":\"file not found\"}"); return; }
        if (fi.Length > 2 * 1024 * 1024) { WriteJson(res, 413, "{\"ok\":false,\"error\":\"file too large to view (2 MB max)\"}"); return; }
        string content = File.ReadAllText(full);
        WriteJson(res, 200, "{\"ok\":true,\"path\":" + JStr(full) + ",\"content\":" + JStr(content) + "}");
    }

    static void Download(HttpListenerResponse res, string path)
    {
        string full = Resolve(path);
        FileInfo fi = new FileInfo(full);
        if (!fi.Exists) { WriteJson(res, 404, "{\"ok\":false,\"error\":\"file not found\"}"); return; }
        res.StatusCode = 200;
        res.ContentType = "application/octet-stream";
        res.ContentLength64 = fi.Length;
        res.AddHeader("Content-Disposition", "attachment; filename=\"" + fi.Name.Replace("\"", "") + "\"");
        using (FileStream fs = fi.OpenRead())
        {
            byte[] buf = new byte[65536];
            int n;
            while ((n = fs.Read(buf, 0, buf.Length)) > 0)
                res.OutputStream.Write(buf, 0, n);
        }
        res.OutputStream.Close();
    }

    static void Write(HttpListenerRequest req, HttpListenerResponse res, string path)
    {
        string full = Resolve(path);
        string parent = Path.GetDirectoryName(full);
        if (parent != null && !Directory.Exists(parent)) Directory.CreateDirectory(parent);
        using (FileStream fs = new FileStream(full, FileMode.Create, FileAccess.Write))
        {
            byte[] buf = new byte[65536];
            int n;
            while ((n = req.InputStream.Read(buf, 0, buf.Length)) > 0)
                fs.Write(buf, 0, n);
        }
        WriteJson(res, 200, "{\"ok\":true,\"path\":" + JStr(full) + "}");
    }

    static void Mkdir(HttpListenerResponse res, string path)
    {
        string full = Resolve(path);
        Directory.CreateDirectory(full);
        WriteJson(res, 200, "{\"ok\":true,\"path\":" + JStr(full) + "}");
    }

    static void Delete(HttpListenerResponse res, string path)
    {
        string full = Resolve(path);
        if (Directory.Exists(full)) Directory.Delete(full, true);
        else if (File.Exists(full)) File.Delete(full);
        else { WriteJson(res, 404, "{\"ok\":false,\"error\":\"not found\"}"); return; }
        WriteJson(res, 200, "{\"ok\":true}");
    }

    static void Rename(HttpListenerResponse res, string path, string newName)
    {
        if (string.IsNullOrEmpty(newName)) { WriteJson(res, 400, "{\"ok\":false,\"error\":\"newName required\"}"); return; }
        if (newName.IndexOfAny(new char[] { '\\', '/', ':' }) >= 0) { WriteJson(res, 400, "{\"ok\":false,\"error\":\"invalid name\"}"); return; }
        string full = Resolve(path);
        string dest = Path.Combine(Path.GetDirectoryName(full), newName);
        if (Root.Length > 0 && !InRoot(dest)) throw new UnauthorizedAccessException();
        if (Directory.Exists(full)) Directory.Move(full, dest);
        else File.Move(full, dest);
        WriteJson(res, 200, "{\"ok\":true,\"path\":" + JStr(dest) + "}");
    }

    // ---- helpers ----

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

    static void WriteJson(HttpListenerResponse res, int status, string json)
    {
        byte[] data = Encoding.UTF8.GetBytes(json);
        res.StatusCode = status;
        res.ContentType = "application/json; charset=utf-8";
        res.ContentLength64 = data.Length;
        res.OutputStream.Write(data, 0, data.Length);
        res.OutputStream.Close();
    }

    static string JStr(string s)
    {
        if (s == null) return "null";
        StringBuilder sb = new StringBuilder(s.Length + 2);
        sb.Append('"');
        foreach (char c in s)
        {
            switch (c)
            {
                case '"': sb.Append("\\\""); break;
                case '\\': sb.Append("\\\\"); break;
                case '\b': sb.Append("\\b"); break;
                case '\f': sb.Append("\\f"); break;
                case '\n': sb.Append("\\n"); break;
                case '\r': sb.Append("\\r"); break;
                case '\t': sb.Append("\\t"); break;
                default:
                    if (c < ' ') sb.Append("\\u").Append(((int)c).ToString("x4"));
                    else sb.Append(c);
                    break;
            }
        }
        sb.Append('"');
        return sb.ToString();
    }
}
