using KMTGuard.CommandManager;
using KMTGuard.ConsoleUi;
using KMTGuard.Helpers;
using KMTGuard.Licensing;
using KMTGuard.LicensingRuntime;
using KMTGuard.Localization;
using KMTGuard.Runtime;
using KMTGuard.RuntimeContract;
using KMTGuard.ServerManagers;
using KMTGuard.SettingManager;

using Serilog;
using Serilog.Core;
using Serilog.Events;

using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.Reflection;
using System.Net;
using System.Net.Http;
using System.Net.NetworkInformation;
using System.Net.Sockets;
using System.Runtime.InteropServices;
using System.Security.Cryptography;
using System.Text;
using System.Threading;
using System.Threading.Tasks;
using Microsoft.Win32;
using Microsoft.Data.SqlClient;

public static class Program
{
    public static string Connectionstring { get; set; } = string.Empty;
    public static string MainMachineIP { get; set; } = string.Empty;
    public static string ProxyDb { get; set; } = string.Empty;
    public static byte[] QuickLoginMasterKey { get; private set; } = Array.Empty<byte>();
    public static ISettings RuntimeSettings { get; private set; } = new Settings().Init();
    public static LoggingLevelSwitch LoggingLevelSwitch { get; } = new LoggingLevelSwitch();

    // periodic re-check timer
    private static Timer? _securityTimer;
    private static Timer? _licenseTimer;
    private static readonly TimeSpan SecurityInterval = TimeSpan.FromMinutes(1); // re-verify every 1 minute
    private static readonly SemaphoreSlim RuntimeLock = new(1, 1);
    private static readonly SemaphoreSlim LicenseRefreshLock = new(1, 1);
    private static int _consecutiveLicenseHeartbeatFailures;
    private static readonly List<Mutex> InstanceMutexes = new();
    private static RuntimeControlServer? _runtimeControlServer;
    private static TaskCompletionSource? _workerStopSignal;
    private static bool _isRunning;
    private static DateTime? _startedAtUtc;
    private static FilterRole _currentRole = FilterRole.All;

    public static bool IsEmbeddedRunning => _isRunning;
    public static DateTime? EmbeddedStartedAtUtc => _startedAtUtc;
    public static FilterRole CurrentRole => _currentRole;
    public static string EmbeddedStatus => _isRunning ? $"{_currentRole} service is running" : "Stopped";

    private static void Main()
    {
        StartCoreAsync(exitOnSecurityFailure: true, FilterRole.All).GetAwaiter().GetResult();

        var commandHandler = new CommandHandler();
        try
        {
            while (true)
            {
                FilterConsole.Prompt();
                var command = Console.ReadLine();
                if (string.IsNullOrEmpty(command))
                    continue;

                if (command.Equals("/exit", StringComparison.OrdinalIgnoreCase))
                {
                    FilterConsole.WriteCommandSuccess("Stopping filter...");
                    break;
                }

                commandHandler.ExecuteCommand(command).GetAwaiter().GetResult();
            }
        }
        catch (Exception exception)
        {
            Log.Warning("Program.cs Main| {0}", exception.Message);
            Log.Warning("Program.cs Main| {0}", exception.StackTrace);
        }
        finally
        {
            StopEmbeddedAsync().GetAwaiter().GetResult();
        }
    }

    public static async Task StartEmbeddedAsync()
    {
        await StartRoleAsync(FilterRole.All);
    }

    public static async Task StartRoleAsync(FilterRole role)
    {
        await RuntimeLock.WaitAsync();
        try
        {
            if (_isRunning)
                return;

            try
            {
                await StartCoreAsync(exitOnSecurityFailure: false, role);
            }
            catch
            {
                CleanupRuntime();
                throw;
            }
        }
        finally
        {
            RuntimeLock.Release();
        }
    }

    public static async Task StopEmbeddedAsync()
    {
        await RuntimeLock.WaitAsync();
        try
        {
            if (!_isRunning && InstanceMutexes.Count == 0 && _securityTimer is null)
                return;

            CleanupRuntime();
        }
        finally
        {
            RuntimeLock.Release();
        }
    }

    public static async Task RestartEmbeddedAsync()
    {
        await StopEmbeddedAsync();
        await StartEmbeddedAsync();
    }

    public static async Task RunWorkerAsync(FilterRole role)
    {
        if (role == FilterRole.All)
            throw new ArgumentOutOfRangeException(nameof(role), "A worker must host one concrete server role.");

        _workerStopSignal = new TaskCompletionSource(TaskCreationOptions.RunContinuationsAsynchronously);
        ConsoleCancelEventHandler cancelHandler = (_, args) =>
        {
            args.Cancel = true;
            _workerStopSignal.TrySetResult();
        };
        Console.CancelKeyPress += cancelHandler;

        try
        {
            await StartRoleAsync(role);
            await _workerStopSignal.Task;
        }
        finally
        {
            Console.CancelKeyPress -= cancelHandler;
            await StopEmbeddedAsync();
            _workerStopSignal = null;
        }
    }

    private static Task RequestWorkerShutdownAsync()
    {
        if (_workerStopSignal != null)
        {
            _workerStopSignal.TrySetResult();
            return Task.CompletedTask;
        }

        return StopEmbeddedAsync();
    }

    private static void CleanupRuntime()
    {
        if (_runtimeControlServer != null)
        {
            try
            {
                _runtimeControlServer.DisposeAsync().AsTask().GetAwaiter().GetResult();
            }
            catch (Exception ex)
            {
                Log.Warning("Runtime control server dispose failed: {Message}", ex.Message);
            }

            _runtimeControlServer = null;
        }

        try
        {
            ServerManager.Dispose();
        }
        catch (Exception ex)
        {
            Log.Warning("ServerManager dispose failed: {Message}", ex.Message);
        }

        _securityTimer?.Dispose();
        _securityTimer = null;
        _licenseTimer?.Dispose();
        _licenseTimer = null;

        for (var index = InstanceMutexes.Count - 1; index >= 0; index--)
        {
            try
            {
                InstanceMutexes[index].ReleaseMutex();
            }
            catch
            {
            }

            InstanceMutexes[index].Dispose();
        }

        InstanceMutexes.Clear();
        if (QuickLoginMasterKey.Length > 0)
            CryptographicOperations.ZeroMemory(QuickLoginMasterKey);
        QuickLoginMasterKey = Array.Empty<byte>();
        _isRunning = false;
        _startedAtUtc = null;
        Log.CloseAndFlush();
    }

    private static async Task StartCoreAsync(bool exitOnSecurityFailure, FilterRole role)
    {
        _currentRole = role;
        FilterConsole.Configure(role);

        // ---- Settings ----
        var settings = new SettingsManager();
        ValidateSettings(settings.Settings);
        RuntimeSettings = settings.Settings;
        ProxyDb = settings.Settings.ProxyDb;

        var maximumPool = role switch
        {
            FilterRole.Download => Math.Min(Math.Max(settings.Settings.MaximumPool, 16), 32),
            FilterRole.Gateway => Math.Min(Math.Max(settings.Settings.MaximumPool, 32), 256),
            _ => settings.Settings.MaximumPool
        };
        var minimumPool = role is FilterRole.Agent or FilterRole.All
            ? settings.Settings.MinimumPool
            : 0;

        minimumPool = Math.Min(minimumPool, maximumPool);
        var sqlPassword = !string.IsNullOrEmpty(settings.Settings.Password)
            ? settings.Settings.Password
            : WindowsCredentialStore.ReadPassword(
                settings.Settings.CredentialTarget, settings.Settings.Username);
        var dataSource = settings.Settings.Port.HasValue
            ? $"{settings.Settings.Address},{settings.Settings.Port.Value}"
            : settings.Settings.Address;
        var connectionBuilder = new SqlConnectionStringBuilder
        {
            DataSource = dataSource,
            InitialCatalog = settings.Settings.ProxyDb,
            UserID = settings.Settings.Username,
            Password = sqlPassword,
            PersistSecurityInfo = false,
            MultipleActiveResultSets = true,
            ApplicationName = $"KMTGuard-{role}",
            Encrypt = false,
            Pooling = true,
            MaxPoolSize = maximumPool,
            MinPoolSize = minimumPool,
            LoadBalanceTimeout = settings.Settings.ConnectionLifetime,
            ConnectTimeout = 10
        };
        Connectionstring = connectionBuilder.ConnectionString;
        sqlPassword = string.Empty;

        if (role is FilterRole.Gateway or FilterRole.All)
            QuickLoginMasterKey = settings.GetOrCreateQuickLoginMasterKey();
        else
            QuickLoginMasterKey = Array.Empty<byte>();

        MainMachineIP = settings.Settings.ServerIP;
        DatabaseJobQueue.Start();

        // ---- Logging ----
        LoggingLevelSwitch.MinimumLevel = LogEventLevel.Debug;
        var loggerConfiguration = new LoggerConfiguration()
            .MinimumLevel.ControlledBy(LoggingLevelSwitch)
            .MinimumLevel.Override(
                "KMTGuard.Clientless.ClientlessHuntEngine",
                LogEventLevel.Error);

        if (FilterConsole.IsEnabled)
        {
            loggerConfiguration = loggerConfiguration.WriteTo.Console(
                formatter: new KmtServiceLogFormatter(role));
        }

        Log.Logger = loggerConfiguration
            .WriteTo.File(
                new KmtServiceLogFormatter(role),
                $"logs/kmtguard-{role.ToString().ToLowerInvariant()}-events-.log",
                rollingInterval: RollingInterval.Day,
                fileSizeLimitBytes: 32L * 1024L * 1024L,
                rollOnFileSizeLimit: true,
                retainedFileCountLimit: 14)
            .CreateLogger();

        AcquireInstanceMutexes(role);

        FilterConsole.Initialize();
        FilterConsole.WriteStartupStep("Configuration", $"DB={ProxyDb}, ServerIP={MainMachineIP}");
        var languageResult = PlayerLanguage.Initialize(settings.Settings.Language);
        FilterConsole.WriteStartupStep(
            "Player language",
            $"{languageResult.Language} ({languageResult.ActiveKeyCount} keys, {languageResult.MissingKeyCount} fallback)");

        // ---- Security: hard checks before any server init ----
        if (string.IsNullOrWhiteSpace(MainMachineIP))
        {
            FilterConsole.WriteStartupStep("Configuration", "Allowed IP is missing.", false);
            Log.Error("Configuration error: Allowed IP (ServerIP) is not set. Application will exit.");
            if (exitOnSecurityFailure)
                Environment.FailFast("Missing allowed IP configuration.");

            throw new InvalidOperationException("Allowed IP (ServerIP) is not set in Settings.json.");
        }

#if KMT_DEVELOPMENT_BUILD
        var developmentClaims = DevelopmentLicense.CreateClaims(MainMachineIP);
        LicenseRuntime.Apply(developmentClaims);
        FilterConsole.WriteStartupStep("License", $"{DevelopmentLicense.BuildLabel} - activation is disabled.");
        Log.Warning(
            "Non-production channel detected :: flavor={BuildLabel} :: customer distribution is blocked",
            DevelopmentLicense.BuildLabel);
#else
        FilterConsole.WriteStartupStep("License", "Verifying KMTGuard subscription...");
        var licenseResult = await RefreshLicenseAsync(forceOnline: true);
        if (!licenseResult.IsValid)
        {
            FilterConsole.WriteStartupStep("License", licenseResult.Message, false);
            Log.Error("License verification failed: {Message}", licenseResult.Message);
            if (exitOnSecurityFailure)
                Environment.FailFast("KMTGuard license verification failed.");
            throw new InvalidOperationException(licenseResult.Message);
        }

        LicenseRuntime.Apply(licenseResult.Claims
            ?? throw new InvalidOperationException("License Server returned no signed claims."));
        _consecutiveLicenseHeartbeatFailures = 0;

        FilterConsole.WriteStartupStep(
            "License",
            $"Active for {licenseResult.Claims?.CustomerName}; expires {licenseResult.Claims?.SubscriptionExpiresUtc:yyyy-MM-dd} UTC");
        _licenseTimer = new Timer(
            _ => _ = EnforceLicenseHeartbeatAsync(),
            null,
            TimeSpan.FromMinutes(1),
            TimeSpan.FromMinutes(1));
#endif

        var enforceIpBinding = LicenseRuntime.Claims.BindingMode == LicenseBindingMode.Ip;
        FilterConsole.WriteStartupStep(
            "Security",
            enforceIpBinding ? "Verifying environment and public IP address..." : "Player-limit mode; IP binding is disabled.");
        Log.Information("Security perimeter selected :: node binding={NodeBinding}", enforceIpBinding);

        // Anti-debug & anti-tamper
        SecurityGuard.EnforceAntiDebugOrExit();

        // IP strict verification (local + public)
        string matched = "Not required";
        if (enforceIpBinding && !SecurityGuard.VerifyIp(MainMachineIP, out matched))
        {
            FilterConsole.WriteStartupStep("IP verification", $"Denied. Expected {MainMachineIP}.", false);
            Log.Error("IP verification failed. Authorized IP: {Allowed}. Detected local/public addresses do not match. Shutting down.",
                MainMachineIP);
            if (exitOnSecurityFailure)
                Environment.FailFast("Unauthorized IP.");

            throw new InvalidOperationException($"IP verification failed. Expected {MainMachineIP}.");
        }

        FilterConsole.WriteStartupStep("IP verification", enforceIpBinding ? $"Matched {matched}" : "Skipped for player-limit mode.");
        Log.Information("Node identity accepted :: network signature={MatchedIp}", matched);

        // Periodic re-check while running (defense-in-depth)
        _securityTimer = new Timer(state =>
        {
            try
            {
                SecurityGuard.EnforceAntiDebugOrExit();

                string matchedRuntime;
                if (LicenseRuntime.Claims.BindingMode == LicenseBindingMode.Ip &&
                    !SecurityGuard.VerifyIp(MainMachineIP, out matchedRuntime))
                {
                    Log.Error("Runtime IP verification failed. Shutting down immediately.");
                    Environment.FailFast("Unauthorized IP (runtime).");
                }
            }
            catch (Exception ex)
            {
                // If anything suspicious or broken occurs, fail fast.
                Log.Error("Security timer exception: {Message}", ex.Message);
                Environment.FailFast("Security timer failure.");
            }
        }, null, SecurityInterval, SecurityInterval);

        // ---- Start servers only after passing all checks ----
        await GameServerPacketAuthenticator.InitializeAsync(Connectionstring);
        FilterConsole.WriteStartupStep("Packet authentication", "Internal channel authenticated.");
        FilterConsole.WriteStartupStep("Server startup", "Loading services and packet handlers...");
        await ServerManager.InitialServers(role);
        FilterConsole.SetConnectionTitle();
        FilterConsole.WriteReady(MainMachineIP, ProxyDb);
        _isRunning = true;
        _startedAtUtc = DateTime.UtcNow;
        _runtimeControlServer = new RuntimeControlServer(role, RequestWorkerShutdownAsync);
        _runtimeControlServer.Start();
        Log.Information("Runtime channel online :: role={Role} :: process={ProcessId} :: accepting traffic",
            role,
            Environment.ProcessId);
    }

    private static void ValidateSettings(ISettings settings)
    {
        if (string.IsNullOrWhiteSpace(settings.Address) ||
            string.Equals(settings.Address, "0.0.0.0", StringComparison.OrdinalIgnoreCase))
            throw new InvalidDataException("Settings.Address must identify the SQL Server host.");
        if (settings.Port is <= 0 or > 65535)
            throw new InvalidDataException("Settings.Port must be from 1 to 65535 when specified.");
        if (string.IsNullOrWhiteSpace(settings.ProxyDb) ||
            string.IsNullOrWhiteSpace(settings.Username))
            throw new InvalidDataException(
                "ProxyDb and Username are required.");
        if (string.IsNullOrEmpty(settings.Password) && string.IsNullOrWhiteSpace(settings.CredentialTarget))
            throw new InvalidDataException(
                "Set Password in Settings.json or configure CredentialTarget in Windows Credential Manager.");
        if (settings.MaximumPool is < 1 or > 2000 ||
            settings.MinimumPool < 0 || settings.MinimumPool > settings.MaximumPool)
            throw new InvalidDataException("SQL pool settings are outside the supported range.");
        if (settings.ConnectionLifetime is < 0 or > 86400)
            throw new InvalidDataException("ConnectionLifetime must be from 0 to 86400 seconds.");
        if (settings.UpstreamConnectTimeoutSeconds is < 1 or > 120 ||
            settings.HandshakeTimeoutSeconds is < 5 or > 120 ||
            settings.LoginTimeoutSeconds is < 15 or > 600 ||
            settings.UnauthenticatedIdleTimeoutSeconds is < 15 or > 900 ||
            settings.AuthenticatedHeartbeatTimeoutSeconds is < 30 or > 3600)
            throw new InvalidDataException("Transport timeout settings are outside the supported range.");
        if (settings.GatewaySessionCap is < 1 or > 100000 ||
            settings.AgentSessionCap is < 1 or > 100000 ||
            settings.DownloadSessionCap is < 1 or > 100000)
            throw new InvalidDataException("Per-service session caps must be from 1 to 100000.");
        if (!IPAddress.TryParse(settings.ServerIP, out _))
            throw new InvalidDataException("ServerIP must be a valid IP address.");
    }

    private static async Task<LicenseClientResult> RefreshLicenseAsync(bool forceOnline)
    {
            var version = Assembly.GetExecutingAssembly().GetName().Version?.ToString(3) ?? "2.7.18";
        var client = new LicenseClient();
        var currentPlayers = _currentRole is FilterRole.Agent or FilterRole.All
            ? ServerManager.GetOnlinePlayerCount()
            : 0;
        return await client.EnsureValidAsync(
            LicenseFeature.Filter,
            MainMachineIP,
            version,
            forceOnline,
            currentPlayers: currentPlayers,
            runtimeRole: _currentRole.ToString());
    }

    private static async Task EnforceLicenseHeartbeatAsync()
    {
        if (!await LicenseRefreshLock.WaitAsync(0))
            return;

        try
        {
            var result = await RefreshLicenseAsync(forceOnline: true);
            if (result.IsValid)
            {
                LicenseRuntime.Apply(result.Claims
                    ?? throw new InvalidOperationException("License Server returned no signed claims."));
                _consecutiveLicenseHeartbeatFailures = 0;
                PlayerLicenseLimitService.EnforceCurrentLimit();
                Log.Debug("License heartbeat accepted. Lease expires {LeaseExpiresUtc}.", result.Claims?.LeaseExpiresUtc);
                return;
            }

            Log.Error("License heartbeat denied: {Message}", result.Message);
            _workerStopSignal?.TrySetResult();
            if (_workerStopSignal is null)
                Environment.FailFast("KMTGuard license expired or was revoked.");
        }
        catch (Exception ex)
        {
            var failures = Interlocked.Increment(ref _consecutiveLicenseHeartbeatFailures);
            Log.Error(ex, "License heartbeat failed ({Failures}/3).", failures);
            if (failures >= 3)
            {
                _workerStopSignal?.TrySetResult();
                if (_workerStopSignal is null)
                    Environment.FailFast("KMTGuard licensing heartbeat failed repeatedly.");
            }
        }
        finally
        {
            LicenseRefreshLock.Release();
        }
    }

    private static void AcquireInstanceMutexes(FilterRole role)
    {
        var roles = role == FilterRole.All
            ? new[] { FilterRole.Agent, FilterRole.Download, FilterRole.Gateway }
            : new[] { role };

        foreach (var ownedRole in roles)
        {
            var mutex = new Mutex(true, $@"Global\KMTGuard_Filter_{ownedRole}", out var isNewInstance);
            if (isNewInstance)
            {
                InstanceMutexes.Add(mutex);
                continue;
            }

            mutex.Dispose();
            for (var index = InstanceMutexes.Count - 1; index >= 0; index--)
            {
                try { InstanceMutexes[index].ReleaseMutex(); } catch { }
                InstanceMutexes[index].Dispose();
            }

            InstanceMutexes.Clear();
            Log.Error("Another KMTGuard {Role} service is already running.", ownedRole);
            throw new InvalidOperationException($"Another KMTGuard {ownedRole} service is already running.");
        }
    }

    public static void PrintInColor(string message, ConsoleColor color)
    {
        var originalColor = Console.ForegroundColor;
        Console.ForegroundColor = color;
        Log.Warning(message);
        Console.ForegroundColor = originalColor;
    }
}

// ---------------- Security Guard (defense-in-depth) ----------------
internal static class SecurityGuard
{
    private static readonly string[] SuspiciousProcessNames = new[]
    {
        "dnspy", "dnspy.x86", "ilspy", "ilspycmd", "x64dbg", "x32dbg",
        "ollydbg", "cheatengine", "fiddler", "charles", "httpdebugger",
        "wireshark", "mitmproxy", "processhacker", "procmon", "scylla"
    };

    // Verify IP against local NICs and public IP. Returns true if any matches the allowed IP.
    public static bool VerifyIp(string allowedIp, out string matchedIp)
    {
        matchedIp = string.Empty;
        var locals = GetLocalIPv4Addresses();
        foreach (var ip in locals)
        {
            if (SecureEquals(ip, allowedIp))
            {
                matchedIp = ip;
                return true;
            }
        }

        // Try public IP (best-effort)
        string publicIp;
        if (TryGetPublicIp(out publicIp))
        {
            if (SecureEquals(publicIp, allowedIp))
            {
                matchedIp = publicIp;
                return true;
            }
        }

        return false;
    }

    // Enforce anti-debug checks; terminate immediately on detection.
    public static void EnforceAntiDebugOrExit()
    {
        if (IsDebuggerDetected() || IsSuspiciousProcessPresent())
        {
            Log.Error("Unauthorized debugging/tampering activity detected. Shutting down.");
            Environment.FailFast("Anti-debug/anti-tamper triggered.");
        }
    }

    // --------- Helpers ---------
    private static bool IsDebuggerDetected()
    {
        try
        {
            if (Debugger.IsAttached || Debugger.IsLogging()) return true;
            if (NativeMethods.IsDebuggerPresent()) return true;

            bool remoteDebuggerPresent = false;
            NativeMethods.CheckRemoteDebuggerPresent(Process.GetCurrentProcess().Handle, ref remoteDebuggerPresent);
            if (remoteDebuggerPresent) return true;

            // Profilers/Tracing flags
            var envs = new[]
            {
                "COR_ENABLE_PROFILING", "COR_PROFILER", "COR_PROFILER_PATH",
                "CORECLR_ENABLE_PROFILING", "CORECLR_PROFILER", "CORECLR_PROFILER_PATH"
            };
            foreach (var e in envs)
            {
                var v = Environment.GetEnvironmentVariable(e);
                if (!string.IsNullOrEmpty(v)) return true;
            }
        }
        catch { /* treat errors as suspicious */ return true; }

        return false;
    }

    private static bool IsSuspiciousProcessPresent()
    {
        try
        {
            var procs = Process.GetProcesses();
            foreach (var p in procs)
            {
                string? name;
                try { name = p.ProcessName?.ToLowerInvariant(); } catch { continue; }
                if (string.IsNullOrEmpty(name)) continue;

                foreach (var s in SuspiciousProcessNames)
                {
                    if (name.Contains(s)) return true;
                }
            }
        }
        catch { return true; }

        return false;
    }

    private static List<string> GetLocalIPv4Addresses()
    {
        var result = new List<string>();
        foreach (var ni in NetworkInterface.GetAllNetworkInterfaces())
        {
            if (ni.OperationalStatus != OperationalStatus.Up) continue;
            if (ni.NetworkInterfaceType == NetworkInterfaceType.Loopback) continue;

            var ipProps = ni.GetIPProperties();
            foreach (var ua in ipProps.UnicastAddresses)
            {
                if (ua.Address.AddressFamily == AddressFamily.InterNetwork && !IPAddress.IsLoopback(ua.Address))
                {
                    var ip = ua.Address.ToString();
                    if (!result.Contains(ip)) result.Add(ip);
                }
            }
        }

        // Fallback via DNS host lookup
        try
        {
            var hostIps = Dns.GetHostAddresses(Dns.GetHostName());
            foreach (var addr in hostIps)
            {
                if (addr.AddressFamily == AddressFamily.InterNetwork && !IPAddress.IsLoopback(addr))
                {
                    var s = addr.ToString();
                    if (!result.Contains(s)) result.Add(s);
                }
            }
        }
        catch { /* ignore */ }

        return result;
    }

    private static bool TryGetPublicIp(out string ip)
    {
        ip = string.Empty;
        try
        {
            using (var cts = new System.Threading.CancellationTokenSource(TimeSpan.FromSeconds(2)))
            using (var http = new HttpClient { Timeout = TimeSpan.FromSeconds(2) })
            {
                // multiple providers, first that responds wins
                var endpoints = new[]
                {
                    "https://api.ipify.org",
                    "https://checkip.amazonaws.com",
                    "https://ipv4.icanhazip.com"
                };

                foreach (var url in endpoints)
                {
                    try
                    {
                        var resp = http.GetStringAsync(url, cts.Token).GetAwaiter().GetResult();
                        var raw = (resp ?? string.Empty).Trim();
                        if (IPAddress.TryParse(raw, out var addr) && addr.AddressFamily == AddressFamily.InterNetwork)
                        {
                            ip = addr.ToString();
                            return true;
                        }
                    }
                    catch { /* try next */ }
                }
            }
        }
        catch { /* ignore */ }

        return false;
    }

    // constant-time string equality
    private static bool SecureEquals(string a, string b)
    {
        if (a == null || b == null) return false;
        var ba = Encoding.UTF8.GetBytes(a);
        var bb = Encoding.UTF8.GetBytes(b);
        if (ba.Length != bb.Length) return false;

        int diff = 0;
        for (int i = 0; i < ba.Length; i++)
            diff |= ba[i] ^ bb[i];
        return diff == 0;
    }

    // Optional: machine fingerprint (not enforced here, but available if needed)
    public static string GetMachineGuid()
    {
        if (!OperatingSystem.IsWindows())
            return "UNKNOWN";

        try
        {
            using (var key = Registry.LocalMachine.OpenSubKey(@"SOFTWARE\Microsoft\Cryptography"))
            {
                var v = key == null ? null : key.GetValue("MachineGuid") as string;
                return string.IsNullOrWhiteSpace(v) ? "UNKNOWN" : v;
            }
        }
        catch { return "UNKNOWN"; }
    }

    private static class NativeMethods
    {
        [DllImport("kernel32.dll")]
        public static extern bool IsDebuggerPresent();

        [DllImport("kernel32.dll", SetLastError = true)]
        public static extern bool CheckRemoteDebuggerPresent(IntPtr hProcess, ref bool isDebuggerPresent);
    }
}
