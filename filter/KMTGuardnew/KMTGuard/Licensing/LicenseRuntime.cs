using System.Threading;
using KMTGuard.Licensing;

namespace KMTGuard.LicensingRuntime;

public static class LicenseRuntime
{
    private static LicenseClaims? _claims;
    private static long _lastVerifiedUtcTicks;
    private static readonly TimeSpan MaximumHeartbeatAge = TimeSpan.FromMinutes(3);

    public static LicenseClaims Claims =>
        Volatile.Read(ref _claims) ?? throw new InvalidOperationException("The signed license has not been loaded.");

    public static int MaximumPlayers => Claims.MaximumPlayers;

    public static bool IsAdmissionAuthorized
    {
        get
        {
            var claims = Volatile.Read(ref _claims);
            if (claims is null)
                return false;

            var now = DateTimeOffset.UtcNow;
            var lastVerified = new DateTimeOffset(
                Interlocked.Read(ref _lastVerifiedUtcTicks),
                TimeSpan.Zero);
            return claims.LeaseExpiresUtc > now &&
                   claims.SubscriptionExpiresUtc > now &&
                   now - lastVerified <= MaximumHeartbeatAge;
        }
    }

    public static void Apply(LicenseClaims claims)
    {
        ArgumentNullException.ThrowIfNull(claims);
        if (claims.MaximumPlayers is < 1 or > 10000)
            throw new InvalidOperationException("The signed license contains an invalid player limit.");

        Volatile.Write(ref _claims, claims);
        Interlocked.Exchange(ref _lastVerifiedUtcTicks, DateTimeOffset.UtcNow.UtcTicks);
    }
}
