using System.Diagnostics;
using System.IO.Compression;
using System.Security.Cryptography;
using System.Text.Json;
using KMTGuard.LicenseAdmin.Services;
using KMTGuard.Licensing;

namespace KMTGuard.Updater;

public sealed class UpdateInstaller
{
    private static readonly JsonSerializerOptions JsonOptions = new() { WriteIndented = true };

    public async Task<string> PrepareAsync(
        string packagePath,
        UpdateReleaseInfo release,
        string packageBindingToken,
        CancellationToken cancellationToken)
    {
        await VerifyPackageAsync(packagePath, release, cancellationToken);
        var updatesRoot = ResolveUpdatesRoot();
        Directory.CreateDirectory(updatesRoot);
        var versionRoot = ResolveVersionRoot(updatesRoot, release.Version);
        var stagingRoot = Path.Combine(updatesRoot, $".prepare-{Guid.NewGuid():N}");
        Directory.CreateDirectory(stagingRoot);
        try
        {
            ZipFile.ExtractToDirectory(packagePath, stagingRoot);
            await VerifyExtractedFilesAsync(stagingRoot, release.Files, cancellationToken);
            PersonalizeDlls(stagingRoot, release.Files, packageBindingToken);

            var prepared = release.Files
                .Where(file => file.Kind is UpdateFileKind.ClientDll or
                    UpdateFileKind.GameServerDll or
                    UpdateFileKind.ShardManagerDll)
                .Where(file => File.Exists(ResolveInside(stagingRoot, file.RelativePath)))
                .Select(file =>
                {
                    var path = ResolveInside(stagingRoot, file.RelativePath);
                    return new PreparedDll(
                        file.Kind,
                        file.RelativePath,
                        Convert.ToHexString(SHA256.HashData(File.ReadAllBytes(path))));
                })
                .ToArray();
            File.WriteAllText(
                Path.Combine(stagingRoot, "prepared-dlls.json"),
                JsonSerializer.Serialize(prepared, JsonOptions));
            File.WriteAllText(
                Path.Combine(stagingRoot, "release.json"),
                JsonSerializer.Serialize(release, JsonOptions));

            if (Directory.Exists(versionRoot))
                Directory.Delete(versionRoot, recursive: true);
            Directory.Move(stagingRoot, versionRoot);
            return versionRoot;
        }
        catch
        {
            if (Directory.Exists(stagingRoot))
                Directory.Delete(stagingRoot, recursive: true);
            throw;
        }
    }

    public async Task<bool> ApplyFilterAsync(
        string preparedRoot,
        UpdateReleaseInfo release,
        CancellationToken cancellationToken)
    {
        var filterSource = Path.Combine(preparedRoot, "Filter");
        if (!Directory.Exists(filterSource))
            return false;

        var updatesRoot = Directory.GetParent(Path.GetFullPath(preparedRoot))?.FullName
                          ?? throw new InvalidDataException("The prepared update path is invalid.");
        var stagedRollbackRoot = Path.Combine(updatesRoot, $".rollback-{Guid.NewGuid():N}");
        var backupRoot = Path.Combine(stagedRollbackRoot, "Filter");
        Directory.CreateDirectory(backupRoot);
        var copiedTargets = new List<(string Relative, bool HadOriginal)>();
        try
        {
            await StopRuntimeProcessesAsync(cancellationToken);
            foreach (var source in Directory.EnumerateFiles(filterSource, "*", SearchOption.AllDirectories))
            {
                cancellationToken.ThrowIfCancellationRequested();
                var relative = Path.GetRelativePath(filterSource, source);
                if (IsPreserved(relative))
                    continue;
                var target = ResolveInside(AppContext.BaseDirectory, relative);
                var hadOriginal = File.Exists(target);
                if (hadOriginal)
                {
                    var backup = ResolveInside(backupRoot, relative);
                    Directory.CreateDirectory(Path.GetDirectoryName(backup)!);
                    File.Copy(target, backup, overwrite: true);
                }
                Directory.CreateDirectory(Path.GetDirectoryName(target)!);
                File.Copy(source, target, overwrite: true);
                copiedTargets.Add((relative, hadOriginal));
            }
            File.WriteAllText(
                Path.Combine(preparedRoot, "filter-installed.txt"),
                $"{release.Version}|{DateTimeOffset.UtcNow:O}");
            CommitRollback(stagedRollbackRoot, updatesRoot, release.Version);
            return true;
        }
        catch
        {
            foreach (var (relative, hadOriginal) in copiedTargets)
            {
                var backup = ResolveInside(backupRoot, relative);
                var target = ResolveInside(AppContext.BaseDirectory, relative);
                if (hadOriginal && File.Exists(backup))
                    File.Copy(backup, target, overwrite: true);
                else if (!hadOriginal && File.Exists(target))
                    File.Delete(target);
            }
            if (Directory.Exists(stagedRollbackRoot))
                Directory.Delete(stagedRollbackRoot, recursive: true);
            throw;
        }
    }

    public void CleanupAfterSuccess(string preparedRoot)
    {
        var normalizedPreparedRoot = Path.GetFullPath(preparedRoot);
        var updatesRoot = Directory.GetParent(normalizedPreparedRoot)?.FullName
                          ?? throw new InvalidDataException("The prepared update path is invalid.");
        CleanupCompletedUpdates(updatesRoot, Path.GetFileName(normalizedPreparedRoot));
    }

    public void CleanupCompletedUpdates(string installedVersion)
    {
        if (!Version.TryParse(installedVersion, out _))
            return;
        CleanupCompletedUpdates(ResolveUpdatesRoot(), installedVersion);
    }

    private static void CleanupCompletedUpdates(string updatesRoot, string installedVersion)
    {
        if (!Directory.Exists(updatesRoot) ||
            !Version.TryParse(installedVersion, out var installed))
            return;

        var completedFolders = Directory.EnumerateDirectories(updatesRoot)
            .Select(path => new
            {
                Path = path,
                Parsed = Version.TryParse(Path.GetFileName(path), out var version) ? version : null
            })
            .Where(item => item.Parsed is not null && item.Parsed <= installed)
            .OrderByDescending(item => item.Parsed)
            .Select(item => item.Path)
            .ToArray();

        MigrateLatestLegacyBackup(completedFolders, updatesRoot);
        foreach (var versionFolder in completedFolders)
        {
            DeleteDirectoryIfPresent(Path.Combine(versionFolder, "Filter"));
            DeleteDirectoryIfPresent(Path.Combine(versionFolder, "Updater"));
            DeleteDirectoryIfPresent(Path.Combine(versionFolder, "Backup"));
            DeleteEmptyDirectories(Path.Combine(versionFolder, "New DLL"));
            DeleteEmptyDirectories(Path.Combine(versionFolder, "New SQL"));
            DeleteEmptyDirectories(Path.Combine(versionFolder, "New Media"));

            var hasManualFiles = new[] { "New DLL", "New SQL", "New Media" }
                .Select(name => Path.Combine(versionFolder, name))
                .Any(path => Directory.Exists(path) &&
                             Directory.EnumerateFiles(path, "*", SearchOption.AllDirectories).Any());
            if (!hasManualFiles && Directory.Exists(versionFolder))
                Directory.Delete(versionFolder, recursive: true);
        }
    }

    public IReadOnlyList<PreparedDll> LoadPreparedDlls(string preparedRoot)
    {
        var path = Path.Combine(preparedRoot, "prepared-dlls.json");
        return File.Exists(path)
            ? JsonSerializer.Deserialize<PreparedDll[]>(File.ReadAllText(path)) ?? Array.Empty<PreparedDll>()
            : Array.Empty<PreparedDll>();
    }

    public static bool VerifyInstalledDll(string folder, string fileName, PreparedDll expected)
    {
        if (string.IsNullOrWhiteSpace(folder))
            return false;
        var path = Path.Combine(folder, fileName);
        return File.Exists(path) &&
               Convert.ToHexString(SHA256.HashData(File.ReadAllBytes(path)))
                   .Equals(expected.Sha256, StringComparison.OrdinalIgnoreCase);
    }

    private static async Task VerifyPackageAsync(
        string packagePath,
        UpdateReleaseInfo release,
        CancellationToken cancellationToken)
    {
        await using var stream = new FileStream(
            packagePath,
            FileMode.Open,
            FileAccess.Read,
            FileShare.Read,
            1024 * 128,
            FileOptions.Asynchronous | FileOptions.SequentialScan);
        var hash = Convert.ToHexString(await SHA256.HashDataAsync(stream, cancellationToken));
        if (!hash.Equals(release.PackageSha256, StringComparison.OrdinalIgnoreCase))
            throw new InvalidDataException("The downloaded update package failed its integrity check.");
    }

    private static async Task VerifyExtractedFilesAsync(
        string root,
        IReadOnlyList<UpdateFileDescriptor> files,
        CancellationToken cancellationToken)
    {
        foreach (var file in files)
        {
            cancellationToken.ThrowIfCancellationRequested();
            var path = ResolveInside(root, file.RelativePath);
            if (!File.Exists(path))
                throw new InvalidDataException($"The update is missing {file.RelativePath}.");
            await using var stream = File.OpenRead(path);
            var hash = Convert.ToHexString(await SHA256.HashDataAsync(stream, cancellationToken));
            if (!hash.Equals(file.Sha256, StringComparison.OrdinalIgnoreCase))
                throw new InvalidDataException($"Update file integrity failed: {file.RelativePath}.");
        }
    }

    private static void PersonalizeDlls(
        string root,
        IReadOnlyList<UpdateFileDescriptor> files,
        string token)
    {
        if (!PackageBindingTokenCodec.TryVerify(token, out var binding, out var error) ||
            binding is null)
            throw new InvalidDataException($"The update package binding is invalid: {error}");

        foreach (var file in files.Where(item => item.Kind is
                     UpdateFileKind.ClientDll or
                     UpdateFileKind.GameServerDll or
                     UpdateFileKind.ShardManagerDll))
        {
            var requiredFeature = file.Kind switch
            {
                UpdateFileKind.ClientDll => LicenseFeature.ClientDll,
                UpdateFileKind.GameServerDll => LicenseFeature.GameServer,
                UpdateFileKind.ShardManagerDll => LicenseFeature.ShardManager,
                _ => LicenseFeature.None
            };
            if (!binding.Features.HasFlag(requiredFeature))
            {
                File.Delete(ResolveInside(root, file.RelativePath));
                continue;
            }
            PackageBindingResource.Write(ResolveInside(root, file.RelativePath), token);
        }
    }

    private static async Task StopRuntimeProcessesAsync(CancellationToken cancellationToken)
    {
        var targetDashboard = Path.GetFullPath(Path.Combine(AppContext.BaseDirectory, "KMTGuard.exe"));
        foreach (var process in Process.GetProcessesByName("KMTGuard"))
        {
            using (process)
            {
                if (!ProcessMatchesPath(process, targetDashboard))
                    continue;
                process.CloseMainWindow();
                try { await process.WaitForExitAsync(cancellationToken).WaitAsync(TimeSpan.FromSeconds(8), cancellationToken); }
                catch (TimeoutException) { process.Kill(entireProcessTree: true); }
            }
        }

        foreach (var name in new[] { "KMTGuard.Gateway", "KMTGuard.Download", "KMTGuard.Agent" })
        {
            var expectedWorker = Path.GetFullPath(
                Path.Combine(AppContext.BaseDirectory, name + ".exe"));
            foreach (var process in Process.GetProcessesByName(name))
            {
                using (process)
                {
                    if (!ProcessMatchesPath(process, expectedWorker))
                        continue;
                    process.Kill(entireProcessTree: true);
                    await process.WaitForExitAsync(cancellationToken);
                }
            }
        }
    }

    private static bool ProcessMatchesPath(Process process, string expected)
    {
        try
        {
            return Path.GetFullPath(process.MainModule?.FileName ?? string.Empty)
                .Equals(expected, StringComparison.OrdinalIgnoreCase);
        }
        catch
        {
            return false;
        }
    }

    private static bool IsPreserved(string relative)
    {
        var name = Path.GetFileName(relative);
        return name.Equals("Settings.json", StringComparison.OrdinalIgnoreCase) ||
               name.Equals(LicenseFileDocument.FileName, StringComparison.OrdinalIgnoreCase) ||
               name.Equals("KMTGuard-Updater.json", StringComparison.OrdinalIgnoreCase) ||
               name.Equals("KMTGuard.Updater.exe", StringComparison.OrdinalIgnoreCase) ||
               relative.Split(Path.DirectorySeparatorChar, Path.AltDirectorySeparatorChar)
                   .Any(part => part.Equals("logs", StringComparison.OrdinalIgnoreCase) ||
                                part.Equals("Updates", StringComparison.OrdinalIgnoreCase));
    }

    private static string ResolveVersionRoot(string root, string version)
    {
        if (!Version.TryParse(version, out _))
            throw new InvalidDataException("The update version is invalid.");
        return ResolveInside(root, version);
    }

    private static string ResolveInside(string root, string relative)
    {
        var normalizedRoot = Path.GetFullPath(root).TrimEnd(Path.DirectorySeparatorChar) +
                             Path.DirectorySeparatorChar;
        var path = Path.GetFullPath(Path.Combine(root, relative.Replace('/', Path.DirectorySeparatorChar)));
        if (!path.StartsWith(normalizedRoot, StringComparison.OrdinalIgnoreCase))
            throw new InvalidDataException("An update file resolved outside the expected folder.");
        return path;
    }

    private static void CommitRollback(
        string stagedRollbackRoot,
        string updatesRoot,
        string version)
    {
        var rollbackRoot = Path.Combine(updatesRoot, "Rollback");
        Directory.CreateDirectory(rollbackRoot);
        File.WriteAllText(
            Path.Combine(stagedRollbackRoot, "rollback.txt"),
            $"{version}|{DateTimeOffset.UtcNow:O}");
        var versionRollbackRoot = ResolveVersionRoot(rollbackRoot, version);
        if (Directory.Exists(versionRollbackRoot))
            Directory.Delete(versionRollbackRoot, recursive: true);
        Directory.Move(stagedRollbackRoot, versionRollbackRoot);

        try
        {
            foreach (var oldBackup in Directory.EnumerateDirectories(rollbackRoot))
            {
                if (Path.GetFullPath(oldBackup).Equals(
                        Path.GetFullPath(versionRollbackRoot),
                        StringComparison.OrdinalIgnoreCase))
                    continue;
                try { Directory.Delete(oldBackup, recursive: true); } catch { }
            }
        }
        catch { }
    }

    private static void MigrateLatestLegacyBackup(
        IReadOnlyList<string> versionFolders,
        string updatesRoot)
    {
        var rollbackRoot = Path.Combine(updatesRoot, "Rollback");
        if (Directory.Exists(rollbackRoot) &&
            Directory.EnumerateDirectories(rollbackRoot).Any())
            return;

        var legacyVersionFolder = versionFolders.FirstOrDefault(path =>
            Directory.Exists(Path.Combine(path, "Backup", "Filter")));
        if (legacyVersionFolder is null)
            return;

        Directory.CreateDirectory(rollbackRoot);
        var version = Path.GetFileName(legacyVersionFolder);
        var legacyBackup = Path.Combine(legacyVersionFolder, "Backup");
        var destination = ResolveVersionRoot(rollbackRoot, version);
        if (Directory.Exists(destination))
            Directory.Delete(destination, recursive: true);
        Directory.Move(legacyBackup, destination);
        File.WriteAllText(
            Path.Combine(destination, "rollback.txt"),
            $"{version}|migrated|{DateTimeOffset.UtcNow:O}");
    }

    private static void DeleteDirectoryIfPresent(string path)
    {
        if (Directory.Exists(path))
            Directory.Delete(path, recursive: true);
    }

    private static void DeleteEmptyDirectories(string path)
    {
        if (!Directory.Exists(path))
            return;
        foreach (var child in Directory.EnumerateDirectories(path))
            DeleteEmptyDirectories(child);
        if (!Directory.EnumerateFileSystemEntries(path).Any())
            Directory.Delete(path);
    }

    private static string ResolveUpdatesRoot()
    {
        var configuredRoot = Environment.GetEnvironmentVariable("KMTGUARD_UPDATE_ROOT");
        return string.IsNullOrWhiteSpace(configuredRoot)
            ? Path.Combine(AppContext.BaseDirectory, "Updates")
            : Path.GetFullPath(configuredRoot);
    }

    public sealed record PreparedDll(UpdateFileKind Kind, string RelativePath, string Sha256);
}
