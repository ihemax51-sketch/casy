using System.Collections.Concurrent;
using System.Net;
using System.Net.NetworkInformation;
using System.Net.Sockets;

namespace KMTGuard.Licensing;

public sealed record ServerIpVerificationResult(bool IsMatch, string Message);

public static class ServerIpVerifier
{
    private static readonly TimeSpan CacheLifetime = TimeSpan.FromMinutes(2);
    private static readonly ConcurrentDictionary<string, (DateTimeOffset ExpiresUtc, bool IsMatch)> Cache =
        new(StringComparer.OrdinalIgnoreCase);
    private static readonly HttpClient PublicIpClient = new()
    {
        Timeout = TimeSpan.FromSeconds(4)
    };

    private static readonly string[] PublicIpEndpoints =
    {
        "https://api.ipify.org",
        "https://checkip.amazonaws.com",
        "https://ipv4.icanhazip.com"
    };

    public static async Task<ServerIpVerificationResult> VerifyCurrentMachineAsync(
        string? expectedServerIp,
        CancellationToken cancellationToken = default)
    {
        if (!IPAddress.TryParse(expectedServerIp?.Trim(), out var expected) ||
            expected.AddressFamily != AddressFamily.InterNetwork)
        {
            return new ServerIpVerificationResult(false, "The licensed server IPv4 address is missing or invalid.");
        }

        var canonical = expected.ToString();
        if (Cache.TryGetValue(canonical, out var cached) && cached.ExpiresUtc > DateTimeOffset.UtcNow)
        {
            return cached.IsMatch
                ? new ServerIpVerificationResult(true, "The licensed server IP matches this machine.")
                : new ServerIpVerificationResult(false, "This machine is not using the licensed server IP.");
        }

        var localAddresses = GetLocalIpv4Addresses();
        if (localAddresses.Contains(canonical))
            return CacheResult(canonical, true);

        using var timeout = CancellationTokenSource.CreateLinkedTokenSource(cancellationToken);
        timeout.CancelAfter(TimeSpan.FromSeconds(5));
        var lookups = PublicIpEndpoints
            .Select(endpoint => ReadPublicIpAsync(endpoint, timeout.Token))
            .ToArray();
        var publicAddresses = await Task.WhenAll(lookups);
        var matches = publicAddresses.Any(address =>
            address is not null && address.Equals(expected));
        return CacheResult(canonical, matches);
    }

    private static ServerIpVerificationResult CacheResult(string expectedServerIp, bool matches)
    {
        Cache[expectedServerIp] = (DateTimeOffset.UtcNow.Add(CacheLifetime), matches);
        return matches
            ? new ServerIpVerificationResult(true, "The licensed server IP matches this machine.")
            : new ServerIpVerificationResult(false, "This machine is not using the licensed server IP.");
    }

    private static HashSet<string> GetLocalIpv4Addresses()
    {
        var addresses = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        try
        {
            foreach (var networkInterface in NetworkInterface.GetAllNetworkInterfaces())
            {
                if (networkInterface.OperationalStatus != OperationalStatus.Up ||
                    networkInterface.NetworkInterfaceType == NetworkInterfaceType.Loopback)
                    continue;

                foreach (var unicast in networkInterface.GetIPProperties().UnicastAddresses)
                {
                    if (unicast.Address.AddressFamily == AddressFamily.InterNetwork &&
                        !IPAddress.IsLoopback(unicast.Address))
                    {
                        addresses.Add(unicast.Address.ToString());
                    }
                }
            }
        }
        catch
        {
        }

        try
        {
            foreach (var address in Dns.GetHostAddresses(Dns.GetHostName()))
            {
                if (address.AddressFamily == AddressFamily.InterNetwork && !IPAddress.IsLoopback(address))
                    addresses.Add(address.ToString());
            }
        }
        catch
        {
        }

        return addresses;
    }

    private static async Task<IPAddress?> ReadPublicIpAsync(string endpoint, CancellationToken cancellationToken)
    {
        try
        {
            var response = (await PublicIpClient.GetStringAsync(endpoint, cancellationToken)).Trim();
            return IPAddress.TryParse(response, out var address) &&
                   address.AddressFamily == AddressFamily.InterNetwork
                ? address
                : null;
        }
        catch
        {
            return null;
        }
    }
}
