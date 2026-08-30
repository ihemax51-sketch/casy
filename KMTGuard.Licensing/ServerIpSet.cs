using System.Net;
using System.Net.Sockets;

namespace KMTGuard.Licensing;

public static class ServerIpSet
{
    public static IReadOnlyList<string> Parse(string? value, bool requireAtLeastOne = true, int maximum = 64)
    {
        var result = (value ?? string.Empty)
            .Split(new[] { ',', ';', '\r', '\n', ' ', '\t' }, StringSplitOptions.RemoveEmptyEntries)
            .Select(ParseIpv4)
            .Distinct(StringComparer.OrdinalIgnoreCase)
            .ToArray();

        if (requireAtLeastOne && result.Length == 0)
            throw new InvalidOperationException("At least one valid IPv4 server address is required.");
        if (result.Length > maximum)
            throw new InvalidOperationException($"No more than {maximum} server IP addresses are allowed.");
        return result;
    }

    public static string Normalize(string? value, bool requireAtLeastOne = true, int maximum = 64) =>
        string.Join(',', Parse(value, requireAtLeastOne, maximum));

    public static bool Contains(string? value, string candidate)
    {
        var normalizedCandidate = ParseIpv4(candidate);
        return Parse(value).Contains(normalizedCandidate, StringComparer.OrdinalIgnoreCase);
    }

    public static bool SetEquals(string? left, string? right) =>
        Parse(left).ToHashSet(StringComparer.OrdinalIgnoreCase)
            .SetEquals(Parse(right));

    private static string ParseIpv4(string value)
    {
        if (!IPAddress.TryParse(value.Trim(), out var address) ||
            address.AddressFamily != AddressFamily.InterNetwork)
        {
            throw new InvalidOperationException($"'{value.Trim()}' is not a valid IPv4 server address.");
        }

        return address.ToString();
    }
}
