namespace KMTGuard.Licensing;

public sealed class PackageBindingClaims
{
    public required string KeyId { get; init; }
    public required string LicenseId { get; init; }
    public required string CustomerId { get; init; }
    public required string CustomerCode { get; init; }
    public required string PackageId { get; init; }
    public required string ServerIp { get; init; }
    public LicenseBindingMode BindingMode { get; init; } = LicenseBindingMode.Ip;
    public required string Watermark { get; init; }
    public required LicenseFeature Features { get; init; }
    public required DateTimeOffset IssuedUtc { get; init; }
}
