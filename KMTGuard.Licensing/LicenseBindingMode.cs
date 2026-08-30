namespace KMTGuard.Licensing;

public enum LicenseBindingMode
{
    Ip = 0,
    PlayerLimit = 1
}

public static class LicenseBindingModeExtensions
{
    public static string ToClaimValue(this LicenseBindingMode mode) =>
        mode == LicenseBindingMode.PlayerLimit ? "LIMIT" : "IP";

    public static LicenseBindingMode Parse(string? value)
    {
        var normalized = value?.Trim();
        return string.Equals(normalized, "LIMIT", StringComparison.OrdinalIgnoreCase) ||
               string.Equals(normalized, "PLAYERLIMIT", StringComparison.OrdinalIgnoreCase) ||
               string.Equals(normalized, "HWID", StringComparison.OrdinalIgnoreCase)
            ? LicenseBindingMode.PlayerLimit
            : LicenseBindingMode.Ip;
    }
}
