namespace KMTGuard.Licensing;

public sealed record LicenseClaims
{
    public required string KeyId { get; init; }
    public required string LicenseId { get; init; }
    public required string CustomerId { get; init; }
    public required string CustomerName { get; init; }
    public required string MachineHash { get; init; }
    public required DateTimeOffset IssuedUtc { get; init; }
    public required DateTimeOffset NotBeforeUtc { get; init; }
    public required DateTimeOffset SubscriptionExpiresUtc { get; init; }
    public required DateTimeOffset LeaseExpiresUtc { get; init; }
    public required LicenseFeature Features { get; init; }
    public required int MaximumInstances { get; init; }
    public required int MaximumPlayers { get; init; }
    public required string PackageId { get; init; }
    public string ServerIp { get; init; } = string.Empty;
    public LicenseBindingMode BindingMode { get; init; } = LicenseBindingMode.Ip;
}
