namespace KMTGuard.Licensing;

[Flags]
public enum LicenseFeature
{
    None = 0,
    Filter = 1,
    GameServer = 2,
    ShardManager = 4,
    ClientDll = 8
}

public static class LicenseFeatureExtensions
{
    public static string ToClaimValue(this LicenseFeature features)
    {
        var values = new List<string>(4);
        if (features.HasFlag(LicenseFeature.Filter))
            values.Add("FILTER");
        if (features.HasFlag(LicenseFeature.GameServer))
            values.Add("GAMESERVER");
        if (features.HasFlag(LicenseFeature.ShardManager))
            values.Add("SHARDMANAGER");
        if (features.HasFlag(LicenseFeature.ClientDll))
            values.Add("CLIENTDLL");
        return string.Join(',', values);
    }

    public static LicenseFeature ParseClaimValue(string? value)
    {
        var result = LicenseFeature.None;
        foreach (var item in (value ?? string.Empty).Split(',', StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries))
        {
            result |= item.ToUpperInvariant() switch
            {
                "FILTER" => LicenseFeature.Filter,
                "GAMESERVER" => LicenseFeature.GameServer,
                "SHARDMANAGER" => LicenseFeature.ShardManager,
                "CLIENTDLL" => LicenseFeature.ClientDll,
                _ => LicenseFeature.None
            };
        }

        return result;
    }
}
