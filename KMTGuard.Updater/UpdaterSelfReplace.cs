using System.Diagnostics;

namespace KMTGuard.Updater;

internal static class UpdaterSelfReplace
{
    private const string RuntimeFolderName = "KMTGuard";

    public static bool TryRun(IReadOnlyList<string> args)
    {
        if (args.Count < 4 ||
            !args[0].Equals("--replace-updater", StringComparison.OrdinalIgnoreCase) ||
            !int.TryParse(args[1], out var oldProcessId))
            return false;

        var target = Path.GetFullPath(args[2]);
        var dashboard = Path.GetFullPath(args[3]);
        var targetRoot = Path.GetDirectoryName(target);
        var dashboardRoot = Path.GetDirectoryName(dashboard);
        var source = Path.GetFullPath(
            Environment.ProcessPath
            ?? throw new InvalidOperationException("The updater process path is unavailable."));
        var runtimeRoot = GetRuntimeRoot()
            .TrimEnd(Path.DirectorySeparatorChar) + Path.DirectorySeparatorChar;
        var legacyReplacement = targetRoot is null
            ? string.Empty
            : Path.GetFullPath(Path.Combine(targetRoot, "KMTGuard.Updater.next.exe"));
        var trustedReplacementSource =
            source.StartsWith(runtimeRoot, StringComparison.OrdinalIgnoreCase) ||
            source.Equals(legacyReplacement, StringComparison.OrdinalIgnoreCase);
        if (targetRoot is null ||
            dashboardRoot is null ||
            !targetRoot.Equals(dashboardRoot, StringComparison.OrdinalIgnoreCase) ||
            !Path.GetFileName(target).Equals("KMTGuard.Updater.exe", StringComparison.OrdinalIgnoreCase) ||
            !Path.GetFileName(dashboard).Equals("KMTGuard.exe", StringComparison.OrdinalIgnoreCase) ||
            !trustedReplacementSource)
            return true;

        try
        {
            try
            {
                using var process = Process.GetProcessById(oldProcessId);
                process.WaitForExit(30000);
            }
            catch
            {
            }

            File.Copy(source, target, overwrite: true);
            if (File.Exists(dashboard))
                Process.Start(new ProcessStartInfo(dashboard) { UseShellExecute = true });
        }
        catch (Exception ex)
        {
            File.WriteAllText(
                Path.Combine(AppContext.BaseDirectory, "KMTGuard-Updater-Replacement.log"),
                $"{DateTimeOffset.UtcNow:O} {ex}");
        }
        return true;
    }

    public static void CleanupStaleFiles()
    {
        var current = Path.GetFullPath(Environment.ProcessPath ?? string.Empty);
        var legacyNext = Path.Combine(AppContext.BaseDirectory, "KMTGuard.Updater.next.exe");
        if (!current.Equals(Path.GetFullPath(legacyNext), StringComparison.OrdinalIgnoreCase))
        {
            try { if (File.Exists(legacyNext)) File.Delete(legacyNext); } catch { }
        }

        var runtimeRoot = GetRuntimeRoot();
        if (!Directory.Exists(runtimeRoot))
            return;
        foreach (var directory in Directory.EnumerateDirectories(runtimeRoot))
        {
            var normalized = Path.GetFullPath(directory)
                .TrimEnd(Path.DirectorySeparatorChar) + Path.DirectorySeparatorChar;
            if (current.StartsWith(normalized, StringComparison.OrdinalIgnoreCase))
                continue;
            try { Directory.Delete(directory, recursive: true); } catch { }
        }
    }

    public static bool Schedule(string preparedRoot)
    {
        var stagedUpdater = Path.Combine(preparedRoot, "Updater", "KMTGuard.Updater.exe");
        if (!File.Exists(stagedUpdater))
            return false;

        CleanupStaleFiles();
        var runtimeDirectory = Path.Combine(GetRuntimeRoot(), Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(runtimeDirectory);
        var replacementHelper = Path.Combine(runtimeDirectory, "KMTGuard.Updater.Replace.exe");
        File.Copy(stagedUpdater, replacementHelper, overwrite: true);
        var target = Path.Combine(AppContext.BaseDirectory, "KMTGuard.Updater.exe");
        var dashboard = Path.Combine(AppContext.BaseDirectory, "KMTGuard.exe");
        Process.Start(new ProcessStartInfo(
            replacementHelper,
            $"--replace-updater {Environment.ProcessId} \"{target}\" \"{dashboard}\"")
        {
            UseShellExecute = true
        });
        return true;
    }

    private static string GetRuntimeRoot() =>
        Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
            RuntimeFolderName,
            "UpdaterRuntime");
}
