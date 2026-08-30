namespace KMTGuard.Licensing;

public sealed record LicenseRefreshRequest(
    string ActivationKey,
    string MachineHash,
    string ServerIp,
    string AppVersion,
    string RequiredFeature,
    int CurrentPlayers = 0,
    string RuntimeRole = "");

public sealed record LicenseRefreshResponse(
    bool Success,
    string Message,
    string? LeaseToken,
    DateTimeOffset? LeaseExpiresUtc,
    DateTimeOffset? SubscriptionExpiresUtc,
    string? CustomerName,
    DateTimeOffset ServerUtc);

public sealed record LicenseClientResult(
    bool IsValid,
    string Message,
    LicenseClaims? Claims,
    bool RefreshedOnline);
