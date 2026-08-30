using System.Net;

namespace KMTGuard.Licensing;

public sealed record LicenseValidationResult(bool IsValid, string Message, LicenseClaims? Claims)
{
    public static LicenseValidationResult Validate(
        string? token,
        LicenseFeature requiredFeature,
        string machineHash,
        DateTimeOffset? now = null,
        string? expectedServerIp = null)
    {
        if (!LicenseTokenCodec.TryVerify(token, out var claims, out var error) || claims is null)
            return new LicenseValidationResult(false, error, null);

        var current = now ?? DateTimeOffset.UtcNow;
        if (!claims.MachineHash.Equals(machineHash, StringComparison.OrdinalIgnoreCase))
            return new LicenseValidationResult(false, "This lease belongs to a different Windows server.", claims);
        if (claims.BindingMode == LicenseBindingMode.Ip && expectedServerIp is not null)
        {
            if (!IPAddress.TryParse(expectedServerIp.Trim(), out var expected) ||
                !IPAddress.TryParse(claims.ServerIp.Trim(), out var licensed) ||
                !expected.Equals(licensed))
            {
                return new LicenseValidationResult(
                    false,
                    "This license belongs to a different server IP.",
                    claims);
            }
        }
        if (!claims.Features.HasFlag(requiredFeature))
            return new LicenseValidationResult(false, $"The license does not include {requiredFeature}.", claims);
        if (claims.MaximumPlayers is < 1 or > 10000)
            return new LicenseValidationResult(false, "The licensed player limit is invalid.", claims);
        if (claims.NotBeforeUtc > current.AddMinutes(5))
            return new LicenseValidationResult(false, "The license is not active yet.", claims);
        if (claims.SubscriptionExpiresUtc <= current)
            return new LicenseValidationResult(false, "The subscription has expired.", claims);
        if (claims.LeaseExpiresUtc <= current)
            return new LicenseValidationResult(false, "The offline license period has expired.", claims);

        return new LicenseValidationResult(true, "License is valid.", claims);
    }
}
