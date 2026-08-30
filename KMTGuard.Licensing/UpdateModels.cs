namespace KMTGuard.Licensing;

public enum UpdateFileKind
{
    Filter,
    ClientDll,
    GameServerDll,
    ShardManagerDll,
    Database,
    Media,
    Documentation,
    Updater
}

public sealed record UpdateFileDescriptor(
    string RelativePath,
    string Sha256,
    long Size,
    UpdateFileKind Kind);

public sealed record UpdateReleaseInfo(
    string Version,
    string Title,
    string Notes,
    DateTimeOffset PublishedUtc,
    bool IsMandatory,
    string PackageSha256,
    long PackageSize,
    IReadOnlyList<UpdateFileDescriptor> Files);

public sealed record UpdateCatalogDocument(
    int FormatVersion,
    IReadOnlyList<UpdateReleaseInfo> Releases);

public sealed record UpdateCheckRequest(
    string ActivationKey,
    string MachineHash,
    string ServerIp,
    string CurrentVersion);

public sealed record UpdateDownloadRequest(
    string ActivationKey,
    string MachineHash,
    string ServerIp,
    string CurrentVersion);

public sealed record UpdateCheckResponse(
    bool Success,
    bool UpdateAvailable,
    string Message,
    UpdateReleaseInfo? Release,
    string? PackageBindingToken,
    DateTimeOffset ServerUtc);
