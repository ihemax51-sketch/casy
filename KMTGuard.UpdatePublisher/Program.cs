using KMTGuard.LicenseAdmin.Services;

if (args.Length < 3)
{
    Console.Error.WriteLine(
        "Usage: KMTGuard.UpdatePublisher <version> <title> <notes> [--mandatory] [--media] [--no-database] " +
        "[--no-filter] [--no-client-dll] [--no-gameserver-dll] [--no-shardmanager-dll]");
    return 2;
}

var settings = new OwnerSettingsService().Load();
var release = new UpdatePackageBuilder().Publish(
    settings,
    args[0],
    args[1],
    args[2],
    args.Contains("--mandatory", StringComparer.OrdinalIgnoreCase),
    new UpdatePackageSelection(
        Filter: !args.Contains("--no-filter", StringComparer.OrdinalIgnoreCase),
        ClientDll: !args.Contains("--no-client-dll", StringComparer.OrdinalIgnoreCase),
        GameServerDll: !args.Contains("--no-gameserver-dll", StringComparer.OrdinalIgnoreCase),
        ShardManagerDll: !args.Contains("--no-shardmanager-dll", StringComparer.OrdinalIgnoreCase),
        Database: !args.Contains("--no-database", StringComparer.OrdinalIgnoreCase),
        Media: args.Contains("--media", StringComparer.OrdinalIgnoreCase)));

Console.WriteLine($"Published KMTGuard {release.Version}");
Console.WriteLine($"Files: {release.Files.Count}");
Console.WriteLine($"Package size: {release.PackageSize:N0} bytes");
Console.WriteLine($"SHA-256: {release.PackageSha256}");
return 0;
