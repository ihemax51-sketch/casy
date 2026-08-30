using System.Diagnostics;
using System.IO.Compression;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Text.Json.Nodes;
using KMTGuard.LicenseAdmin.Models;
using KMTGuard.Licensing;

namespace KMTGuard.LicenseAdmin.Services;

public sealed record CustomerPackageResult(string FolderPath, string ZipPath);

public sealed class CustomerPackageBuilder
{
    private const string DeveloperBuildMarker = "Developer/Test Build";
    private const string CustomerProductionBaseFolder = "CustomerProductionBase";
    private const string CanonicalCustomerMediaRoot = @"D:\KMTGuard-build\Media-KemtGuard";
    private static readonly string[] RequiredBuildFolders = { "Filter", "DLL", "ServerAddons" };
    private static readonly string[] RequiredCustomerMediaFolders = { "Client-import", "Media" };
    private static readonly string[] ProtectedBinaries =
    {
        @"Filter\KMTGuard.exe",
        @"Filter\KMTGuard.Agent.exe",
        @"Filter\KMTGuard.Download.exe",
        @"Filter\KMTGuard.Gateway.exe",
        @"Filter\KMTGuard.Updater.exe",
        @"DLL\KMTGuardKit.dll",
        @"ServerAddons\GameServer\KMTGuard_GameServer.dll",
        @"ServerAddons\ShardManager\KMTGuard_ShardManager.dll"
    };
    private readonly CustomerBinaryPersonalizer _personalizer = new();

    public CustomerPackageResult Build(
        OwnerSettings settings,
        OwnerCustomerSummary customer,
        string activationKey,
        string packageBindingToken)
    {
        var sources = ResolveAndValidateBuildSources(settings);
        var outputRoot = Path.GetFullPath(settings.CustomerOutputRoot);

        Directory.CreateDirectory(outputRoot);
        var customerRoot = Path.GetFullPath(Path.Combine(outputRoot, $"{customer.CustomerCode}-{Slug(customer.CustomerName)}"));
        EnsureInside(customerRoot, outputRoot);
        var revision = DateTime.Now.ToString("yyyyMMdd-HHmmss");
        var packageRoot = Path.Combine(customerRoot, revision);
        var zipPath = packageRoot + ".zip";
        Directory.CreateDirectory(packageRoot);

        try
        {
            foreach (var folder in RequiredBuildFolders)
                CopyTree(Path.Combine(sources.BuildRoot, folder), Path.Combine(packageRoot, folder));
            foreach (var folder in RequiredCustomerMediaFolders)
                CopyTree(Path.Combine(sources.CustomerMediaRoot, folder), Path.Combine(packageRoot, folder));

            PrepareFilterSettings(
                Path.Combine(packageRoot, "Filter", "Settings.json"),
                customer.ServerIp,
                customer.BindingMode);
            PrepareServerAddonSettings(
                Path.Combine(packageRoot, "ServerAddons", "GameServer", "KMTGuard-Addon.ini"));
            PrepareServerAddonSettings(
                Path.Combine(packageRoot, "ServerAddons", "ShardManager", "KMTGuard-Addon.ini"));

            var installationGuide = Path.Combine(
                packageRoot,
                "Filter",
                "docs",
                "KMTGuard-Customer-Installation-Guide-AR.md");
            if (File.Exists(installationGuide))
            {
                File.Copy(
                    installationGuide,
                    Path.Combine(packageRoot, "START-HERE-KMTGuard-AR.md"),
                    overwrite: true);
            }

            var license = new LicenseFileDocument
            {
                ServerUrl = settings.PublicApiUrl.Trim().TrimEnd('/'),
                CertificateSha256 = settings.CertificateSha256,
                ActivationKey = activationKey,
                BindingMode = customer.BindingMode,
                LeaseToken = string.Empty
            };
            license.Save(Path.Combine(packageRoot, "Filter", LicenseFileDocument.FileName));

            var binding = _personalizer.Personalize(packageRoot, customer, packageBindingToken);
            File.WriteAllText(Path.Combine(packageRoot, "PACKAGE-INFO.txt"), BuildPackageInfo(customer, revision, binding));
            if (binding.BindingMode == LicenseBindingMode.Ip)
            {
                File.WriteAllLines(
                    Path.Combine(packageRoot, "LICENSED-SERVER-IPS.txt"),
                    ServerIpSet.Parse(binding.ServerIp),
                    new UTF8Encoding(encoderShouldEmitUTF8Identifier: false));
            }
            WriteChecksums(packageRoot);

            ZipFile.CreateFromDirectory(packageRoot, zipPath, CompressionLevel.Optimal, includeBaseDirectory: true);
            return new CustomerPackageResult(packageRoot, zipPath);
        }
        catch
        {
            if (Directory.Exists(packageRoot))
                Directory.Delete(packageRoot, recursive: true);
            if (File.Exists(zipPath))
                File.Delete(zipPath);
            throw;
        }
    }

    public void ValidateBuildSource(OwnerSettings settings) =>
        _ = ResolveAndValidateBuildSources(settings);

    private static BuildSources ResolveAndValidateBuildSources(OwnerSettings settings)
    {
        var configuredBuildRoot = Path.GetFullPath(settings.BaseBuildRoot);
        var buildRoot = ResolveLicensedBuildRoot(configuredBuildRoot);
        foreach (var folder in RequiredBuildFolders)
        {
            var source = Path.Combine(buildRoot, folder);
            if (!Directory.Exists(source))
                throw new DirectoryNotFoundException($"Required release folder is missing: {source}");
        }

        var customerMediaRoot = Path.GetFullPath(CanonicalCustomerMediaRoot);
        if (!Directory.Exists(customerMediaRoot))
        {
            throw new DirectoryNotFoundException(
                $"The fixed full customer media source is missing: {customerMediaRoot}");
        }

        foreach (var folder in RequiredCustomerMediaFolders)
        {
            var source = Path.Combine(customerMediaRoot, folder);
            if (!Directory.Exists(source))
            {
                throw new DirectoryNotFoundException(
                    $"The fixed full customer media source is incomplete. Required folder is missing: {source}");
            }
        }

        return new BuildSources(buildRoot, customerMediaRoot);
    }

    private static string ResolveLicensedBuildRoot(string configuredBuildRoot)
    {
        var developerBinaries = ProtectedBinaries
            .Select(relative => Path.Combine(configuredBuildRoot, relative))
            .Where(File.Exists)
            .Where(path => BinaryContainsText(path, DeveloperBuildMarker))
            .ToArray();

        var resolvedBuildRoot = developerBinaries.Length == 0
            ? configuredBuildRoot
            : Path.Combine(configuredBuildRoot, CustomerProductionBaseFolder);

        if (developerBinaries.Length > 0 && !Directory.Exists(resolvedBuildRoot))
        {
            throw new InvalidOperationException(
                "The configured base is a Developer/Test build, but its licensed customer production base is missing. " +
                "Run scripts\\Publish-KmtGuardDeveloper.ps1 before generating a customer package.");
        }

        foreach (var relative in ProtectedBinaries)
        {
            var path = Path.Combine(resolvedBuildRoot, relative);
            if (!File.Exists(path))
                throw new FileNotFoundException($"Required protected customer binary is missing: {path}", path);
            if (BinaryContainsText(path, DeveloperBuildMarker))
            {
                throw new InvalidOperationException(
                    $"Customer package generation was blocked because a Developer/Test binary was detected: {path}");
            }
        }

        return resolvedBuildRoot;
    }

    private static bool BinaryContainsText(string path, string text)
    {
        var bytes = File.ReadAllBytes(path);
        return Encoding.ASCII.GetString(bytes).Contains(text, StringComparison.Ordinal) ||
               Encoding.Unicode.GetString(bytes).Contains(text, StringComparison.Ordinal);
    }

    public static void OpenInExplorer(string path)
    {
        Process.Start(new ProcessStartInfo("explorer.exe", $"/select,\"{path}\"") { UseShellExecute = true });
    }

    private static void CopyTree(string source, string destination)
    {
        Directory.CreateDirectory(destination);
        foreach (var directory in Directory.EnumerateDirectories(source, "*", SearchOption.AllDirectories))
        {
            var relative = Path.GetRelativePath(source, directory);
            if (relative.Split(Path.DirectorySeparatorChar).Any(IsExcludedDirectory))
                continue;
            Directory.CreateDirectory(Path.Combine(destination, relative));
        }

        foreach (var file in Directory.EnumerateFiles(source, "*", SearchOption.AllDirectories))
        {
            var relative = Path.GetRelativePath(source, file);
            if (relative.Split(Path.DirectorySeparatorChar).Any(IsExcludedDirectory) || IsExcludedFile(file))
                continue;
            var target = Path.Combine(destination, relative);
            Directory.CreateDirectory(Path.GetDirectoryName(target)!);
            File.Copy(file, target, overwrite: true);
        }
    }

    private static bool IsExcludedDirectory(string value) =>
        value.Equals("logs", StringComparison.OrdinalIgnoreCase) ||
        value.Equals("Customers", StringComparison.OrdinalIgnoreCase);

    private static bool IsExcludedFile(string path) =>
        Path.GetFileName(path).Equals(LicenseFileDocument.FileName, StringComparison.OrdinalIgnoreCase) ||
        Path.GetExtension(path).Equals(".pdb", StringComparison.OrdinalIgnoreCase);

    private static void WriteChecksums(string packageRoot)
    {
        var lines = Directory.EnumerateFiles(packageRoot, "*", SearchOption.AllDirectories)
            .Where(path => !Path.GetFileName(path).Equals("SHA256SUMS.txt", StringComparison.OrdinalIgnoreCase))
            .OrderBy(path => path, StringComparer.OrdinalIgnoreCase)
            .Select(path => $"{Convert.ToHexString(SHA256.HashData(File.ReadAllBytes(path)))}  {Path.GetRelativePath(packageRoot, path).Replace('\\', '/')}");
        File.WriteAllLines(Path.Combine(packageRoot, "SHA256SUMS.txt"), lines, Encoding.UTF8);
    }

    private static void PrepareFilterSettings(
        string path,
        string licensedServerIp,
        LicenseBindingMode bindingMode)
    {
        if (!File.Exists(path))
            return;

        var settings = JsonNode.Parse(File.ReadAllText(path)) as JsonObject
                       ?? throw new InvalidDataException("Filter Settings.json is not a JSON object.");
        settings["Address"] = "127.0.0.1";
        settings["Username"] = string.Empty;
        settings["Password"] = string.Empty;
        if (bindingMode == LicenseBindingMode.Ip)
            settings["ServerIP"] = ServerIpSet.Parse(licensedServerIp).First();
        File.WriteAllText(path, settings.ToJsonString(new JsonSerializerOptions { WriteIndented = true }));
    }

    private static void PrepareServerAddonSettings(string path)
    {
        if (!File.Exists(path))
            return;

        var lines = File.ReadAllLines(path);
        for (var index = 0; index < lines.Length; index++)
        {
            var trimmed = lines[index].TrimStart();
            if (trimmed.StartsWith("SQLSERVER=", StringComparison.OrdinalIgnoreCase))
                lines[index] = "SQLSERVER=127.0.0.1";
            else if (trimmed.StartsWith("LoginId=", StringComparison.OrdinalIgnoreCase))
                lines[index] = "LoginId=";
            else if (trimmed.StartsWith("Password=", StringComparison.OrdinalIgnoreCase))
                lines[index] = "Password=";
        }

        File.WriteAllLines(path, lines, new UTF8Encoding(encoderShouldEmitUTF8Identifier: false));
    }

    private static string BuildPackageInfo(OwnerCustomerSummary customer, string revision, PackageBindingClaims binding) => $"""
        KMTGuard Customer Package
        Customer: {customer.CustomerName}
        Customer Code: {customer.CustomerCode}
        Package Revision: {revision}
        Package ID: {binding.PackageId}
        License Mode: {(binding.BindingMode == LicenseBindingMode.Ip ? "IP ONLY" : "PLAYER LIMIT ONLY")}
        Licensed Server IPs: {(binding.BindingMode == LicenseBindingMode.Ip ? binding.ServerIp : "Not used")}
        Build Watermark: {binding.Watermark}
        Maximum Online Players: {customer.MaximumPlayers}
        Subscription Ends: {customer.ExpiresUtc:yyyy-MM-dd HH:mm} UTC
        Package Components: Filter, licensed Update Center, GameServer add-on, ShardManager add-on and client DLL

        Filter, GameServer and ShardManager independently verify the signed license.
        The client DLL verifies its signed customer package binding before initialization.
        KMTGuard.Updater.exe uses this package's existing activation credential; updates never require a new activation key.
        Filter files can be installed by the Update Center. New client and server DLLs remain in Filter\Updates\<version>\New DLL for manual maintenance.
        IP-only packages accept only the listed public server IP slots. Player-limit-only packages do not check IP or HWID.
        For multiple IP slots, use the same package on every server and set Filter\Settings.json ServerIP to that machine's own listed IP.
        Start Filter once on each server before starting GameServer or ShardManager.
        Enter the customer's database and server addresses in Filter settings before starting the workers.
        """;

    private static string Slug(string value)
    {
        var invalid = Path.GetInvalidFileNameChars();
        var normalized = new string(value.Trim().Select(character => invalid.Contains(character) ? '_' : character).ToArray());
        return string.Join('-', normalized.Split(' ', StringSplitOptions.RemoveEmptyEntries)).Trim('-');
    }

    private static void EnsureInside(string path, string root)
    {
        var normalizedRoot = root.TrimEnd(Path.DirectorySeparatorChar) + Path.DirectorySeparatorChar;
        if (!path.StartsWith(normalizedRoot, StringComparison.OrdinalIgnoreCase))
            throw new InvalidOperationException("Customer package path resolved outside the configured output folder.");
    }

    private sealed record BuildSources(string BuildRoot, string CustomerMediaRoot);
}
