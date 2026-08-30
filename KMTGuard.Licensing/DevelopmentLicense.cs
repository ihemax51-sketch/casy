#if KMT_DEVELOPMENT_BUILD
namespace KMTGuard.Licensing;

/// <summary>
/// Claims used only by explicitly compiled Developer/Test builds.
/// This type is not present in production binaries.
/// </summary>
public static class DevelopmentLicense
{
    public const string BuildLabel = "KMTGuard Developer/Test Build";

    public static LicenseClaims CreateClaims(string serverIp = "") => new()
    {
        KeyId = "DEVELOPMENT-BUILD",
        LicenseId = "DEVELOPMENT-BUILD",
        CustomerId = "KMT-INTERNAL",
        CustomerName = BuildLabel,
        MachineHash = "DEVELOPMENT-BUILD",
        IssuedUtc = DateTimeOffset.UtcNow,
        NotBeforeUtc = DateTimeOffset.UnixEpoch,
        SubscriptionExpiresUtc = DateTimeOffset.MaxValue,
        LeaseExpiresUtc = DateTimeOffset.MaxValue,
        Features = LicenseFeature.Filter | LicenseFeature.GameServer | LicenseFeature.ShardManager,
        MaximumInstances = 100,
        MaximumPlayers = 10000,
        PackageId = "DEVELOPMENT-BUILD",
        ServerIp = serverIp?.Trim() ?? string.Empty
    };
}
#endif
