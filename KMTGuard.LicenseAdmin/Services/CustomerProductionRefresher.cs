using System.Diagnostics;
using KMTGuard.LicenseAdmin.Models;

namespace KMTGuard.LicenseAdmin.Services;

public sealed class CustomerProductionRefresher
{
    private const int MaximumFailureDetailsLength = 4000;
    private static readonly TimeSpan MaximumRefreshDuration = TimeSpan.FromMinutes(15);
    private static readonly string[] RequiredProductionFiles =
    {
        @"Filter\KMTGuard.exe",
        @"Filter\KMTGuard.Agent.exe",
        @"Filter\KMTGuard.Download.exe",
        @"Filter\KMTGuard.Gateway.exe",
        @"DLL\KMTGuardKit.dll",
        @"ServerAddons\GameServer\KMTGuard_GameServer.dll",
        @"ServerAddons\ShardManager\KMTGuard_ShardManager.dll"
    };

    public bool TryUseCurrentRelease(OwnerSettings settings, out string version)
    {
        version = string.Empty;
        var sourceVersionPath = Path.Combine(
            Path.GetFullPath(settings.SourceRoot),
            "VERSION.txt");
        if (!TryReadVersion(sourceVersionPath, out var sourceVersion))
            return false;

        var configuredBuildRoot = Path.GetFullPath(settings.BaseBuildRoot);
        var nestedProductionRoot = Path.Combine(configuredBuildRoot, "CustomerProductionBase");
        var productionRoot = Directory.Exists(nestedProductionRoot)
            ? nestedProductionRoot
            : configuredBuildRoot;
        if (!TryReadVersion(Path.Combine(productionRoot, "VERSION.txt"), out var productionVersion) ||
            !string.Equals(sourceVersion, productionVersion, StringComparison.OrdinalIgnoreCase) ||
            RequiredProductionFiles.Any(relative =>
                !File.Exists(Path.Combine(productionRoot, relative))))
        {
            return false;
        }

        version = productionVersion;
        return true;
    }

    public async Task RefreshAsync(OwnerSettings settings, CancellationToken cancellationToken = default)
    {
        var sourceRoot = Path.GetFullPath(settings.SourceRoot);
        var scriptPath = Path.Combine(sourceRoot, "scripts", "Publish-KmtGuardDeveloper.ps1");
        if (!File.Exists(scriptPath))
        {
            throw new FileNotFoundException(
                "The automatic release refresh script was not found. Check Source Root in License Center Settings.",
                scriptPath);
        }

        var startInfo = new ProcessStartInfo
        {
            FileName = "powershell.exe",
            WorkingDirectory = sourceRoot,
            UseShellExecute = false,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
            CreateNoWindow = true
        };
        startInfo.ArgumentList.Add("-NoLogo");
        startInfo.ArgumentList.Add("-NoProfile");
        startInfo.ArgumentList.Add("-NonInteractive");
        startInfo.ArgumentList.Add("-ExecutionPolicy");
        startInfo.ArgumentList.Add("Bypass");
        startInfo.ArgumentList.Add("-File");
        startInfo.ArgumentList.Add(scriptPath);
        startInfo.ArgumentList.Add("-BuildRoot");
        startInfo.ArgumentList.Add(Path.GetFullPath(settings.BaseBuildRoot));
        startInfo.ArgumentList.Add("-CustomerProductionOnly");

        using var process = Process.Start(startInfo)
                            ?? throw new InvalidOperationException("Could not start the automatic release refresh.");
        using var timeout = CancellationTokenSource.CreateLinkedTokenSource(cancellationToken);
        timeout.CancelAfter(MaximumRefreshDuration);
        var standardOutput = process.StandardOutput.ReadToEndAsync();
        var standardError = process.StandardError.ReadToEndAsync();

        try
        {
            await process.WaitForExitAsync(timeout.Token);
        }
        catch (OperationCanceledException)
        {
            TryStopProcess(process);
            if (cancellationToken.IsCancellationRequested)
                throw;
            throw new TimeoutException(
                $"The automatic licensed release build did not finish within {MaximumRefreshDuration.TotalMinutes:N0} minutes and was stopped.");
        }
        var output = await standardOutput;
        var error = await standardError;
        if (process.ExitCode == 0)
            return;

        var details = string.Join(
            Environment.NewLine,
            new[] { error, output }.Where(value => !string.IsNullOrWhiteSpace(value)));
        throw new InvalidOperationException(
            "The latest licensed release could not be prepared, so package generation was stopped." +
            (details.Length == 0 ? string.Empty : $"{Environment.NewLine}{Environment.NewLine}{Tail(details)}"));
    }

    private static string Tail(string value)
    {
        var normalized = value.Trim();
        return normalized.Length <= MaximumFailureDetailsLength
            ? normalized
            : normalized[^MaximumFailureDetailsLength..];
    }

    private static bool TryReadVersion(string path, out string version)
    {
        version = string.Empty;
        if (!File.Exists(path))
            return false;

        var value = File.ReadAllText(path).Trim();
        if (!Version.TryParse(value, out _))
            return false;

        version = value;
        return true;
    }

    private static void TryStopProcess(Process process)
    {
        try
        {
            if (!process.HasExited)
                process.Kill(entireProcessTree: true);
        }
        catch (InvalidOperationException)
        {
            // The build completed between the cancellation and termination checks.
        }
        catch (System.ComponentModel.Win32Exception)
        {
            // Preserve the original cancellation/timeout result when Windows already
            // released the process or denied a late termination request.
        }
    }
}
