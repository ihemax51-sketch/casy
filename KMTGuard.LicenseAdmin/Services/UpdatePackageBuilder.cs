using System.IO.Compression;
using System.Security.Cryptography;
using System.Text.Json;
using KMTGuard.LicenseAdmin.Models;
using KMTGuard.Licensing;

namespace KMTGuard.LicenseAdmin.Services;

public sealed record UpdatePackageSelection(
    bool Filter,
    bool ClientDll,
    bool GameServerDll,
    bool ShardManagerDll,
    bool Database,
    bool Media)
{
    public bool HasCustomerPayload =>
        Filter || ClientDll || GameServerDll || ShardManagerDll || Database || Media;

    public static UpdatePackageSelection Full(bool database, bool media) =>
        new(
            Filter: true,
            ClientDll: true,
            GameServerDll: true,
            ShardManagerDll: true,
            Database: database,
            Media: media);
}

public sealed class UpdatePackageBuilder
{
    private static readonly JsonSerializerOptions JsonOptions = new() { WriteIndented = true };

    public UpdateReleaseInfo Publish(
        OwnerSettings settings,
        string versionText,
        string title,
        string notes,
        bool mandatory,
        UpdatePackageSelection selection)
    {
        if (!selection.HasCustomerPayload)
            throw new InvalidOperationException("Select at least one update component.");

        var version = NormalizeVersion(versionText);
        var buildRoot = Path.GetFullPath(settings.BaseBuildRoot);
        var sourceRoot = Path.Combine(buildRoot, "CustomerProductionBase");
        if (!Directory.Exists(sourceRoot))
        {
            throw new DirectoryNotFoundException(
                "The licensed CustomerProductionBase is missing. Run Publish-KmtGuardDeveloper.ps1 first.");
        }

        var updateRoot = Path.GetFullPath(Environment.ExpandEnvironmentVariables(settings.UpdateRoot));
        var releasesRoot = Path.Combine(updateRoot, "releases");
        var releaseRoot = Path.Combine(releasesRoot, version);
        if (Directory.Exists(releaseRoot))
            throw new InvalidOperationException($"Update {version} is already published.");

        Directory.CreateDirectory(releasesRoot);
        var stagingRoot = Path.Combine(updateRoot, $".publish-{Guid.NewGuid():N}");
        var contentRoot = Path.Combine(stagingRoot, "content");
        var stagedReleaseRoot = Path.Combine(stagingRoot, "release");
        Directory.CreateDirectory(contentRoot);
        Directory.CreateDirectory(stagedReleaseRoot);

        try
        {
            if (selection.Filter)
                CopyFilterFiles(Path.Combine(sourceRoot, "Filter"), Path.Combine(contentRoot, "Filter"));

            CopyRequiredFile(
                Path.Combine(sourceRoot, "Filter", "KMTGuard.Updater.exe"),
                Path.Combine(contentRoot, "Updater", "KMTGuard.Updater.exe"));

            if (selection.ClientDll)
            {
                CopyRequiredFile(
                    Path.Combine(sourceRoot, "DLL", "KMTGuardKit.dll"),
                    Path.Combine(contentRoot, "New DLL", "Client", "KMTGuardKit.dll"));
            }
            if (selection.GameServerDll)
            {
                CopyRequiredFile(
                    Path.Combine(sourceRoot, "ServerAddons", "GameServer", "KMTGuard_GameServer.dll"),
                    Path.Combine(contentRoot, "New DLL", "GameServer", "KMTGuard_GameServer.dll"));
            }
            if (selection.ShardManagerDll)
            {
                CopyRequiredFile(
                    Path.Combine(sourceRoot, "ServerAddons", "ShardManager", "KMTGuard_ShardManager.dll"),
                    Path.Combine(contentRoot, "New DLL", "ShardManager", "KMTGuard_ShardManager.dll"));
            }

            if (selection.Database)
                CopySelectedTree(Path.Combine(sourceRoot, "Filter", "database"), Path.Combine(contentRoot, "New SQL"));
            if (selection.Media)
            {
                CopySelectedTree(Path.Combine(sourceRoot, "Media"), Path.Combine(contentRoot, "New Media", "Media"));
                CopySelectedTree(Path.Combine(sourceRoot, "Client-import"), Path.Combine(contentRoot, "New Media", "Client-import"));
            }

            var files = BuildFileList(contentRoot);
            var packagePath = Path.Combine(stagedReleaseRoot, "KMTGuard.Update.zip");
            ZipFile.CreateFromDirectory(
                contentRoot,
                packagePath,
                CompressionLevel.Optimal,
                includeBaseDirectory: false);
            var packageHash = Convert.ToHexString(SHA256.HashData(File.ReadAllBytes(packagePath)));
            var packageSize = new FileInfo(packagePath).Length;
            var release = new UpdateReleaseInfo(
                version,
                string.IsNullOrWhiteSpace(title) ? $"KMTGuard {version}" : title.Trim(),
                notes.Trim(),
                DateTimeOffset.UtcNow,
                mandatory,
                packageHash,
                packageSize,
                files);

            File.WriteAllText(
                Path.Combine(stagedReleaseRoot, "release.json"),
                JsonSerializer.Serialize(release, JsonOptions));
            Directory.Move(stagedReleaseRoot, releaseRoot);
            WriteCatalog(updateRoot, release);
            return release;
        }
        finally
        {
            if (Directory.Exists(stagingRoot))
                Directory.Delete(stagingRoot, recursive: true);
        }
    }

    public IReadOnlyList<UpdateReleaseInfo> ReadPublished(OwnerSettings settings)
    {
        var root = Path.GetFullPath(Environment.ExpandEnvironmentVariables(settings.UpdateRoot));
        var path = Path.Combine(root, "catalog.json");
        if (!File.Exists(path))
            return Array.Empty<UpdateReleaseInfo>();
        return (JsonSerializer.Deserialize<UpdateCatalogDocument>(
                    File.ReadAllText(path),
                    new JsonSerializerOptions { PropertyNameCaseInsensitive = true })
                ?? new UpdateCatalogDocument(1, Array.Empty<UpdateReleaseInfo>()))
            .Releases
            .OrderByDescending(item => Version.Parse(item.Version))
            .ToArray();
    }

    private static void WriteCatalog(string updateRoot, UpdateReleaseInfo release)
    {
        var catalogPath = Path.Combine(updateRoot, "catalog.json");
        var current = File.Exists(catalogPath)
            ? JsonSerializer.Deserialize<UpdateCatalogDocument>(
                  File.ReadAllText(catalogPath),
                  new JsonSerializerOptions { PropertyNameCaseInsensitive = true })
              ?? new UpdateCatalogDocument(1, Array.Empty<UpdateReleaseInfo>())
            : new UpdateCatalogDocument(1, Array.Empty<UpdateReleaseInfo>());
        var releases = current.Releases
            .Where(item => !item.Version.Equals(release.Version, StringComparison.OrdinalIgnoreCase))
            .Append(release)
            .OrderByDescending(item => Version.Parse(item.Version))
            .ToArray();
        var temporary = catalogPath + $".{Guid.NewGuid():N}.tmp";
        File.WriteAllText(
            temporary,
            JsonSerializer.Serialize(new UpdateCatalogDocument(1, releases), JsonOptions));
        File.Move(temporary, catalogPath, overwrite: true);
    }

    private static IReadOnlyList<UpdateFileDescriptor> BuildFileList(string contentRoot) =>
        Directory.EnumerateFiles(contentRoot, "*", SearchOption.AllDirectories)
            .OrderBy(path => path, StringComparer.OrdinalIgnoreCase)
            .Select(path =>
            {
                var relative = Path.GetRelativePath(contentRoot, path).Replace('\\', '/');
                return new UpdateFileDescriptor(
                    relative,
                    Convert.ToHexString(SHA256.HashData(File.ReadAllBytes(path))),
                    new FileInfo(path).Length,
                    ResolveKind(relative));
            })
            .ToArray();

    private static UpdateFileKind ResolveKind(string relative) =>
        relative.StartsWith("Filter/", StringComparison.OrdinalIgnoreCase) ? UpdateFileKind.Filter :
        relative.StartsWith("New DLL/Client/", StringComparison.OrdinalIgnoreCase) ? UpdateFileKind.ClientDll :
        relative.StartsWith("New DLL/GameServer/", StringComparison.OrdinalIgnoreCase) ? UpdateFileKind.GameServerDll :
        relative.StartsWith("New DLL/ShardManager/", StringComparison.OrdinalIgnoreCase) ? UpdateFileKind.ShardManagerDll :
        relative.StartsWith("New SQL/", StringComparison.OrdinalIgnoreCase) ? UpdateFileKind.Database :
        relative.StartsWith("New Media/", StringComparison.OrdinalIgnoreCase) ? UpdateFileKind.Media :
        relative.StartsWith("Updater/", StringComparison.OrdinalIgnoreCase) ? UpdateFileKind.Updater :
        UpdateFileKind.Documentation;

    private static void CopyFilterFiles(string source, string destination)
    {
        if (!Directory.Exists(source))
            throw new DirectoryNotFoundException($"Production Filter folder is missing: {source}");
        foreach (var file in Directory.EnumerateFiles(source, "*", SearchOption.AllDirectories))
        {
            var relative = Path.GetRelativePath(source, file);
            var parts = relative.Split(Path.DirectorySeparatorChar, Path.AltDirectorySeparatorChar);
            if (parts.Any(part => part.Equals("logs", StringComparison.OrdinalIgnoreCase) ||
                                  part.Equals("database", StringComparison.OrdinalIgnoreCase) ||
                                  part.Equals("docs", StringComparison.OrdinalIgnoreCase) ||
                                  part.Equals("tools", StringComparison.OrdinalIgnoreCase)))
                continue;
            var name = Path.GetFileName(file);
            if (name.Equals("Settings.json", StringComparison.OrdinalIgnoreCase) ||
                name.Equals(LicenseFileDocument.FileName, StringComparison.OrdinalIgnoreCase) ||
                name.Equals("KMTGuard.Updater.exe", StringComparison.OrdinalIgnoreCase) ||
                Path.GetExtension(name).Equals(".pdb", StringComparison.OrdinalIgnoreCase))
                continue;
            CopyRequiredFile(file, Path.Combine(destination, relative));
        }
    }

    private static void CopySelectedTree(string source, string destination)
    {
        if (!Directory.Exists(source))
            throw new DirectoryNotFoundException($"Selected update folder is missing: {source}");
        var files = Directory.EnumerateFiles(source, "*", SearchOption.AllDirectories).ToArray();
        if (files.Length == 0)
            throw new InvalidOperationException($"Selected update folder is empty: {source}");
        foreach (var file in files)
            CopyRequiredFile(file, Path.Combine(destination, Path.GetRelativePath(source, file)));
    }

    private static void CopyRequiredFile(string source, string destination)
    {
        if (!File.Exists(source))
            throw new FileNotFoundException($"Required update file is missing: {source}", source);
        Directory.CreateDirectory(Path.GetDirectoryName(destination)!);
        File.Copy(source, destination, overwrite: true);
    }

    private static string NormalizeVersion(string value)
    {
        var normalized = (value ?? string.Empty).Trim().TrimStart('v', 'V');
        if (!Version.TryParse(normalized, out var parsed) || parsed.Major < 1)
            throw new InvalidOperationException("Enter a semantic version such as 2.5.0.");
        return parsed.Build < 0
            ? $"{parsed.Major}.{parsed.Minor}.0"
            : $"{parsed.Major}.{parsed.Minor}.{parsed.Build}";
    }
}
