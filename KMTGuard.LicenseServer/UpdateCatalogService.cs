using System.Security.Cryptography;
using System.Text.Json;
using KMTGuard.Licensing;
using Microsoft.Extensions.Options;

namespace KMTGuard.LicenseServer;

public sealed class UpdateCatalogService
{
    private const int SupportedFormatVersion = 1;
    private static readonly JsonSerializerOptions JsonOptions = new()
    {
        PropertyNameCaseInsensitive = true
    };

    private readonly string _root;

    public UpdateCatalogService(IOptions<LicenseServerOptions> options)
    {
        _root = Path.GetFullPath(LicenseServerOptions.ExpandPath(options.Value.UpdateRoot));
        Directory.CreateDirectory(_root);
    }

    public UpdateReleaseInfo? FindLatestNewerThan(string currentVersion)
    {
        var current = ParseVersion(currentVersion);
        return LoadCatalog().Releases
            .Where(release => ParseVersion(release.Version) > current)
            .OrderByDescending(release => ParseVersion(release.Version))
            .FirstOrDefault();
    }

    public async Task<FileStream> OpenVerifiedPackageAsync(
        string version,
        CancellationToken cancellationToken)
    {
        var release = LoadCatalog().Releases.FirstOrDefault(
            item => item.Version.Equals(version, StringComparison.OrdinalIgnoreCase))
            ?? throw new FileNotFoundException("The requested update release was not found.");
        var packagePath = ResolvePackagePath(release.Version);
        if (!File.Exists(packagePath))
            throw new FileNotFoundException("The requested update package is not available.", packagePath);

        await using (var stream = new FileStream(
                         packagePath,
                         FileMode.Open,
                         FileAccess.Read,
                         FileShare.Read,
                         1024 * 128,
                         FileOptions.Asynchronous | FileOptions.SequentialScan))
        {
            var actual = Convert.ToHexString(
                await SHA256.HashDataAsync(stream, cancellationToken));
            if (!actual.Equals(release.PackageSha256, StringComparison.OrdinalIgnoreCase))
                throw new InvalidDataException("The published update package failed its integrity check.");
        }

        return new FileStream(
            packagePath,
            FileMode.Open,
            FileAccess.Read,
            FileShare.Read,
            1024 * 128,
            FileOptions.Asynchronous | FileOptions.SequentialScan);
    }

    private UpdateCatalogDocument LoadCatalog()
    {
        var path = Path.Combine(_root, "catalog.json");
        if (!File.Exists(path))
            return new UpdateCatalogDocument(SupportedFormatVersion, Array.Empty<UpdateReleaseInfo>());
        var catalog = JsonSerializer.Deserialize<UpdateCatalogDocument>(
                          File.ReadAllText(path),
                          JsonOptions)
                      ?? throw new InvalidDataException("The update catalog is empty.");
        if (catalog.FormatVersion != SupportedFormatVersion)
            throw new InvalidDataException("The update catalog format is not supported.");
        return catalog;
    }

    private string ResolvePackagePath(string version)
    {
        if (!Version.TryParse(version, out _))
            throw new InvalidDataException("The update version is invalid.");
        var releaseRoot = Path.GetFullPath(Path.Combine(_root, "releases", version));
        var expectedPrefix = Path.GetFullPath(Path.Combine(_root, "releases"))
            .TrimEnd(Path.DirectorySeparatorChar) + Path.DirectorySeparatorChar;
        if (!releaseRoot.StartsWith(expectedPrefix, StringComparison.OrdinalIgnoreCase))
            throw new InvalidDataException("The update path resolved outside the release store.");
        return Path.Combine(releaseRoot, "KMTGuard.Update.zip");
    }

    private static Version ParseVersion(string value) =>
        Version.TryParse((value ?? string.Empty).Trim().TrimStart('v', 'V'), out var version)
            ? version
            : new Version(0, 0, 0);
}
