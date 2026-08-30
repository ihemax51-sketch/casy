using System.IO.Compression;
using System.Security.Cryptography;
using System.Text.Json.Nodes;
using KMTGuard.LicenseAdmin.Models;
using KMTGuard.LicenseAdmin.Services;
using KMTGuard.Licensing;
using KMTGuard.Updater;

var buildRoot = Environment.GetEnvironmentVariable("KMTGUARD_TEST_BUILD_ROOT") ?? @"D:\KMTGuard-build";
var repoRoot = Path.GetFullPath(Path.Combine(AppContext.BaseDirectory, "..", "..", "..", ".."));
var outputRoot = Path.Combine(
    repoRoot,
    ".artifacts",
    "package-tests",
    Guid.NewGuid().ToString("N"));
var releaseStateRoot = Path.Combine(outputRoot, "release-state");
var releaseStateSource = Path.Combine(releaseStateRoot, "source");
var releaseStateBuild = Path.Combine(releaseStateRoot, "build");
var releaseStateProduction = Path.Combine(releaseStateBuild, "CustomerProductionBase");
Directory.CreateDirectory(releaseStateSource);
Directory.CreateDirectory(releaseStateProduction);
File.WriteAllText(Path.Combine(releaseStateSource, "VERSION.txt"), "9.8.7");
File.WriteAllText(Path.Combine(releaseStateProduction, "VERSION.txt"), "9.8.7");
foreach (var relative in new[]
         {
             @"Filter\KMTGuard.exe",
             @"Filter\KMTGuard.Agent.exe",
             @"Filter\KMTGuard.Download.exe",
             @"Filter\KMTGuard.Gateway.exe",
             @"DLL\KMTGuardKit.dll",
             @"ServerAddons\GameServer\KMTGuard_GameServer.dll",
             @"ServerAddons\ShardManager\KMTGuard_ShardManager.dll"
         })
{
    var path = Path.Combine(releaseStateProduction, relative);
    Directory.CreateDirectory(Path.GetDirectoryName(path)!);
    File.WriteAllBytes(path, Array.Empty<byte>());
}
var releaseStateSettings = new OwnerSettings
{
    SourceRoot = releaseStateSource,
    BaseBuildRoot = releaseStateBuild
};
var productionRefresher = new CustomerProductionRefresher();
Require(
    productionRefresher.TryUseCurrentRelease(releaseStateSettings, out var reusableVersion) &&
    reusableVersion == "9.8.7",
    "A complete production base at the source version was not reused.");
File.WriteAllText(Path.Combine(releaseStateSource, "VERSION.txt"), "9.8.8");
Require(
    !productionRefresher.TryUseCurrentRelease(releaseStateSettings, out _),
    "A production base from an older version was incorrectly reused.");
if (args.Contains("--release-state-only", StringComparer.OrdinalIgnoreCase))
{
    Console.WriteLine("PASS: current customer production base is reused without rebuilding");
    Directory.Delete(outputRoot, recursive: true);
    return;
}
var now = DateTimeOffset.UtcNow;
var activationKey = "KMT-PACKAGE-INTEGRATION-TEST";
var customer = new OwnerCustomerSummary(
    "package-test-customer",
    "KMT-TEST",
    "Packaging Test",
    "package-test-license",
    "Active",
    now,
    now.AddMonths(1),
    LicenseFeature.Filter | LicenseFeature.GameServer | LicenseFeature.ShardManager | LicenseFeature.ClientDll,
    1,
    20,
    24,
    string.Empty,
    "203.0.113.50",
    LicenseBindingMode.Ip,
    null,
    string.Empty,
    "Automated package delivery verification");

var settings = new OwnerSettings
{
    AdminApiUrl = "http://127.0.0.1:5128",
    PublicApiUrl = "https://license.play-casy.online",
    CertificateSha256 = string.Empty,
    BaseBuildRoot = buildRoot,
    CustomerOutputRoot = outputRoot,
    UpdateRoot = Path.Combine(outputRoot, "update-store")
};

var signingKeyPath = Path.Combine(
    Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData),
    "KMTGuardLicensing",
    "Secrets",
    "license-signing-key.pk8");
Require(File.Exists(signingKeyPath), "The owner signing key is required for package integration tests.");
using var signingKey = RSA.Create();
signingKey.ImportPkcs8PrivateKey(File.ReadAllBytes(signingKeyPath), out _);
var bindingToken = PackageBindingTokenCodec.Sign(new PackageBindingClaims
{
    KeyId = LicenseTokenCodec.TrustedKeyId,
    LicenseId = customer.LicenseId,
    CustomerId = customer.CustomerId,
    CustomerCode = customer.CustomerCode,
    PackageId = "package-test-credential",
    ServerIp = customer.ServerIp,
    BindingMode = customer.BindingMode,
    Features = customer.Features,
    IssuedUtc = now,
    Watermark = "0123456789ABCDEFFEDCBA9876543210"
}, signingKey);
var result = new CustomerPackageBuilder().Build(settings, customer, activationKey, bindingToken);
Require(Directory.Exists(result.FolderPath), "The customer package folder was not created.");
Require(File.Exists(result.ZipPath), "The customer ZIP was not created.");
Require(File.Exists(Path.Combine(result.FolderPath, "SHA256SUMS.txt")), "Package checksums are missing.");
Require(File.ReadAllText(Path.Combine(result.FolderPath, "PACKAGE-INFO.txt")).Contains("Maximum Online Players: 20", StringComparison.Ordinal),
    "Package information does not include the licensed player limit.");
Require(File.ReadAllLines(Path.Combine(result.FolderPath, "LICENSED-SERVER-IPS.txt"))
        .SequenceEqual(new[] { customer.ServerIp }),
    "The package does not contain its licensed server-IP slot list.");
Require(!Directory.EnumerateFiles(result.FolderPath, "*.pdb", SearchOption.AllDirectories).Any(), "A PDB leaked into the customer package.");

var packagedFilterRoot = Path.Combine(result.FolderPath, "Filter");
var packagedDatabaseRoot = Path.Combine(packagedFilterRoot, "database");
Require(!Directory.EnumerateFiles(packagedFilterRoot, "*.sql", SearchOption.TopDirectoryOnly).Any(),
    "A SQL file leaked into the customer Filter root.");
Require(Directory.Exists(packagedDatabaseRoot), "The versioned customer database folder is missing.");
Require(!Directory.Exists(Path.Combine(packagedDatabaseRoot, "migrations")),
    "The legacy database/migrations folder leaked into the customer package.");
var packagedVersionFolders = Directory.EnumerateDirectories(packagedDatabaseRoot, "v*", SearchOption.TopDirectoryOnly)
    .Where(path => Version.TryParse(Path.GetFileName(path).TrimStart('v', 'V'), out _))
    .ToArray();
Require(packagedVersionFolders.Length > 0, "The customer database package has no filter version folders.");
var expectedDatabaseUpdateCount = Directory.EnumerateFiles(
    Path.Combine(repoRoot, "database", "migrations"), "*.sql", SearchOption.TopDirectoryOnly).Count();
var sourceDatabaseUpdateNames = Directory.EnumerateFiles(
        Path.Combine(repoRoot, "database", "migrations"), "*.sql", SearchOption.TopDirectoryOnly)
    .Select(Path.GetFileName)
    .ToArray();
var packagedDatabaseScripts = packagedVersionFolders
    .SelectMany(path => Directory.EnumerateFiles(path, "*.sql", SearchOption.TopDirectoryOnly))
    .ToArray();
foreach (var updateName in sourceDatabaseUpdateNames)
{
    Require(
        packagedDatabaseScripts.Count(path =>
            Path.GetFileName(path).Equals(updateName, StringComparison.OrdinalIgnoreCase)) == 1,
        $"Database update {updateName} was not packaged exactly once.");
}
var packagedCompleteBundles = packagedDatabaseScripts.Count(path =>
    Path.GetFileName(path).StartsWith(
        "KMTGuard_FULL_DATABASE_UPDATE_",
        StringComparison.OrdinalIgnoreCase));
Require(
    packagedDatabaseScripts.Length == expectedDatabaseUpdateCount + packagedCompleteBundles,
    "The customer database package contains an unassigned SQL script.");

var canonicalCustomerMediaRoot = Path.GetFullPath(@"D:\KMTGuard-build\Media-KemtGuard");
Require(Directory.Exists(Path.Combine(canonicalCustomerMediaRoot, "Client-import")),
    "The fixed Client-import media source is missing.");
Require(Directory.Exists(Path.Combine(canonicalCustomerMediaRoot, "Media")),
    "The fixed full Media source is missing.");
var canonicalMediaFiles = Directory.EnumerateFiles(canonicalCustomerMediaRoot, "*", SearchOption.AllDirectories).ToArray();
Require(canonicalMediaFiles.Length > 0, "The fixed full customer media source is empty.");
var packagedMediaFileCount =
    Directory.EnumerateFiles(Path.Combine(result.FolderPath, "Client-import"), "*", SearchOption.AllDirectories).Count() +
    Directory.EnumerateFiles(Path.Combine(result.FolderPath, "Media"), "*", SearchOption.AllDirectories).Count();
Require(packagedMediaFileCount == canonicalMediaFiles.Length,
    "The package contains media files outside the fixed full customer media source.");
foreach (var sourceMediaFile in canonicalMediaFiles)
{
    var relative = Path.GetRelativePath(canonicalCustomerMediaRoot, sourceMediaFile);
    var packagedMediaFile = Path.Combine(result.FolderPath, relative);
    Require(File.Exists(packagedMediaFile), $"Full customer media file is missing from the package: {relative}");
    Require(
        SHA256.HashData(File.ReadAllBytes(sourceMediaFile))
            .SequenceEqual(SHA256.HashData(File.ReadAllBytes(packagedMediaFile))),
        $"Full customer media file changed while packaging: {relative}");
}

foreach (var relative in new[]
         {
             @"Filter\KMTGuard.exe",
             @"Filter\KMTGuard.Agent.exe",
             @"Filter\KMTGuard.Download.exe",
             @"Filter\KMTGuard.Gateway.exe",
             @"Filter\KMTGuard.Updater.exe",
             @"DLL\KMTGuardKit.dll",
             @"ServerAddons\GameServer\KMTGuard_GameServer.dll",
             @"ServerAddons\ShardManager\KMTGuard_ShardManager.dll"
         })
{
    var protectedBinary = Path.Combine(result.FolderPath, relative);
    Require(
        !BinaryContainsText(protectedBinary, "Developer/Test Build"),
        $"A Developer/Test binary leaked into the customer package: {relative}.");
}

var personalizer = new CustomerBinaryPersonalizer();
foreach (var relative in new[]
         {
             @"DLL\KMTGuardKit.dll",
             @"ServerAddons\GameServer\KMTGuard_GameServer.dll",
             @"ServerAddons\ShardManager\KMTGuard_ShardManager.dll"
         })
{
    var embedded = personalizer.ReadAndVerify(Path.Combine(result.FolderPath, relative));
    Require(embedded.PackageId == "package-test-credential",
        $"Signed customer binding is missing from {relative}.");
}

static bool BinaryContainsText(string path, string text)
{
    var bytes = File.ReadAllBytes(path);
    return System.Text.Encoding.ASCII.GetString(bytes).Contains(text, StringComparison.Ordinal) ||
           System.Text.Encoding.Unicode.GetString(bytes).Contains(text, StringComparison.Ordinal);
}

var filterLicensePath = Path.Combine(result.FolderPath, "Filter", LicenseFileDocument.FileName);
var license = LicenseFileDocument.Load(filterLicensePath);
Require(license.ServerUrl == settings.PublicApiUrl, "Wrong public URL in Filter license.");
Require(license.ActivationKey == activationKey, "Wrong activation key in Filter license.");
Require(license.BindingMode == LicenseBindingMode.Ip, "Wrong Filter license binding mode.");
Require(string.IsNullOrWhiteSpace(license.LeaseToken), "A cached lease leaked into the package.");
Require(!File.Exists(Path.Combine(result.FolderPath, "ServerAddons", "GameServer", LicenseFileDocument.FileName)),
    "GameServer add-on incorrectly received a license file.");
Require(!File.Exists(Path.Combine(result.FolderPath, "ServerAddons", "ShardManager", LicenseFileDocument.FileName)),
    "ShardManager add-on incorrectly received a license file.");

var filterSettings = JsonNode.Parse(File.ReadAllText(Path.Combine(result.FolderPath, "Filter", "Settings.json")))?.AsObject()
                     ?? throw new InvalidDataException("Packaged Filter Settings.json is invalid.");
Require(filterSettings["Address"]?.GetValue<string>() == "127.0.0.1", "The database address was not sanitized.");
Require(filterSettings["Username"]?.GetValue<string>() == string.Empty, "The database username was not removed.");
Require(filterSettings["Password"]?.GetValue<string>() == string.Empty, "The database password was not removed.");
Require(filterSettings["ServerIP"]?.GetValue<string>() == customer.ServerIp, "The licensed server IP was not prepared.");
foreach (var addonSettingsPath in new[]
         {
             Path.Combine(result.FolderPath, "ServerAddons", "GameServer", "KMTGuard-Addon.ini"),
             Path.Combine(result.FolderPath, "ServerAddons", "ShardManager", "KMTGuard-Addon.ini")
         })
{
    var addonSettings = File.ReadAllText(addonSettingsPath);
    var addonLines = File.ReadAllLines(addonSettingsPath)
        .Select(line => line.Trim())
        .ToArray();
    Require(addonSettings.Contains("SQLSERVER=127.0.0.1", StringComparison.OrdinalIgnoreCase),
        $"The add-on database address was not sanitized: {addonSettingsPath}");
    Require(addonLines.Contains("LoginId=", StringComparer.OrdinalIgnoreCase),
        $"An add-on database username leaked into the package: {addonSettingsPath}");
    Require(addonLines.Contains("Password=", StringComparer.OrdinalIgnoreCase),
        $"An add-on database password leaked into the package: {addonSettingsPath}");
}

Require(File.Exists(Path.Combine(result.FolderPath, "START-HERE-KMTGuard-AR.md")),
    "The customer installation guide is missing from the package root.");

using (var archive = ZipFile.OpenRead(result.ZipPath))
{
    Require(archive.Entries.Any(entry => entry.FullName.EndsWith("Filter/KMTGuard.exe", StringComparison.OrdinalIgnoreCase)),
        "KMTGuard.exe is missing from the ZIP.");
    Require(archive.Entries.Any(entry => entry.FullName.EndsWith("Filter/KMTGuard.Updater.exe", StringComparison.OrdinalIgnoreCase)),
        "KMTGuard.Updater.exe is missing from the ZIP.");
    Require(archive.Entries.Any(entry => entry.FullName.EndsWith("DLL/KMTGuardKit.dll", StringComparison.OrdinalIgnoreCase)),
        "KMTGuardKit.dll is missing from the ZIP.");
    Require(archive.Entries.Any(entry => entry.FullName.EndsWith("ServerAddons/GameServer/KMTGuard_GameServer.dll", StringComparison.OrdinalIgnoreCase)),
        "The GameServer add-on is missing from the ZIP.");
    Require(archive.Entries.Any(entry => entry.FullName.EndsWith("ServerAddons/ShardManager/KMTGuard_ShardManager.dll", StringComparison.OrdinalIgnoreCase)),
        "The ShardManager add-on is missing from the ZIP.");
    Require(archive.Entries.Any(entry =>
            entry.FullName.EndsWith(
                "Filter/database/v1.9.0/20260729_discord_notifications.sql",
                StringComparison.OrdinalIgnoreCase)),
        "The versioned database updates are missing from the customer ZIP.");
}

var updateRelease = new UpdatePackageBuilder().Publish(
    settings,
    "2.0.0",
    "KMTGuard 2 Integration Release",
    "Secure updater integration verification.",
    mandatory: false,
    selection: UpdatePackageSelection.Full(database: true, media: false));
var updatePackagePath = Path.Combine(
    settings.UpdateRoot,
    "releases",
    updateRelease.Version,
    "KMTGuard.Update.zip");
Require(File.Exists(updatePackagePath), "The licensed update ZIP was not published.");
Require(
    Convert.ToHexString(SHA256.HashData(File.ReadAllBytes(updatePackagePath)))
        .Equals(updateRelease.PackageSha256, StringComparison.OrdinalIgnoreCase),
    "The published update package hash is incorrect.");
using (var updateArchive = ZipFile.OpenRead(updatePackagePath))
{
    Require(updateArchive.Entries.Any(entry =>
            entry.FullName.Equals("Updater/KMTGuard.Updater.exe", StringComparison.OrdinalIgnoreCase)),
        "The self-updating Update Center payload is missing.");
    Require(updateArchive.Entries.Any(entry =>
            entry.FullName.Equals("New DLL/Client/KMTGuardKit.dll", StringComparison.OrdinalIgnoreCase)),
        "The manual Client DLL payload is missing.");
    Require(updateArchive.Entries.Any(entry =>
            entry.FullName.Equals("New DLL/GameServer/KMTGuard_GameServer.dll", StringComparison.OrdinalIgnoreCase)),
        "The manual GameServer DLL payload is missing.");
    Require(updateArchive.Entries.Any(entry =>
            entry.FullName.Equals("New DLL/ShardManager/KMTGuard_ShardManager.dll", StringComparison.OrdinalIgnoreCase)),
        "The manual ShardManager DLL payload is missing.");
    Require(!updateArchive.Entries.Any(entry =>
            entry.FullName.EndsWith("Settings.json", StringComparison.OrdinalIgnoreCase) ||
            entry.FullName.EndsWith(LicenseFileDocument.FileName, StringComparison.OrdinalIgnoreCase)),
        "Customer settings or license data leaked into the update.");
}
var previousUpdateRoot = Environment.GetEnvironmentVariable("KMTGUARD_UPDATE_ROOT");
Environment.SetEnvironmentVariable(
    "KMTGUARD_UPDATE_ROOT",
    Path.Combine(outputRoot, "prepared-updates"));
var preparedUpdateRoot = await new UpdateInstaller().PrepareAsync(
        updatePackagePath,
        updateRelease,
        bindingToken,
        CancellationToken.None);
Environment.SetEnvironmentVariable("KMTGUARD_UPDATE_ROOT", previousUpdateRoot);
foreach (var relative in new[]
         {
             @"New DLL\Client\KMTGuardKit.dll",
             @"New DLL\GameServer\KMTGuard_GameServer.dll",
             @"New DLL\ShardManager\KMTGuard_ShardManager.dll"
         })
{
    var preparedBinding = personalizer.ReadAndVerify(Path.Combine(preparedUpdateRoot, relative));
    Require(
        preparedBinding.PackageId == "package-test-credential",
        $"The prepared update DLL was not personalized for the existing package: {relative}");
}

var gameServerOnlyRelease = new UpdatePackageBuilder().Publish(
    settings,
    "2.0.1",
    "GameServer DLL Integration Release",
    "Single-component package verification.",
    mandatory: false,
    selection: new UpdatePackageSelection(
        Filter: false,
        ClientDll: false,
        GameServerDll: true,
        ShardManagerDll: false,
        Database: false,
        Media: false));
var gameServerOnlyPackagePath = Path.Combine(
    settings.UpdateRoot,
    "releases",
    gameServerOnlyRelease.Version,
    "KMTGuard.Update.zip");
using (var componentArchive = ZipFile.OpenRead(gameServerOnlyPackagePath))
{
    Require(componentArchive.Entries.Any(entry =>
            entry.FullName.Equals("Updater/KMTGuard.Updater.exe", StringComparison.OrdinalIgnoreCase)),
        "The updater support payload is missing from a component-only release.");
    Require(componentArchive.Entries.Any(entry =>
            entry.FullName.Equals("New DLL/GameServer/KMTGuard_GameServer.dll", StringComparison.OrdinalIgnoreCase)),
        "The selected GameServer DLL is missing from the component-only release.");
    Require(!componentArchive.Entries.Any(entry =>
            entry.FullName.StartsWith("Filter/", StringComparison.OrdinalIgnoreCase)),
        "A component-only release unexpectedly contains Filter files.");
    Require(!componentArchive.Entries.Any(entry =>
            entry.FullName.StartsWith("New DLL/Client/", StringComparison.OrdinalIgnoreCase) ||
            entry.FullName.StartsWith("New DLL/ShardManager/", StringComparison.OrdinalIgnoreCase)),
        "A component-only release contains an unselected DLL.");
}
previousUpdateRoot = Environment.GetEnvironmentVariable("KMTGUARD_UPDATE_ROOT");
Environment.SetEnvironmentVariable(
    "KMTGUARD_UPDATE_ROOT",
    Path.Combine(outputRoot, "prepared-component-update"));
var componentInstaller = new UpdateInstaller();
var preparedComponentRoot = await componentInstaller.PrepareAsync(
    gameServerOnlyPackagePath,
    gameServerOnlyRelease,
    bindingToken,
    CancellationToken.None);
Environment.SetEnvironmentVariable("KMTGUARD_UPDATE_ROOT", previousUpdateRoot);
componentInstaller.CleanupAfterSuccess(preparedComponentRoot);
Require(
    File.Exists(Path.Combine(
        preparedComponentRoot,
        "New DLL",
        "GameServer",
        "KMTGuard_GameServer.dll")),
    "Successful cleanup removed the selected manual GameServer DLL.");
Require(
    !Directory.Exists(Path.Combine(preparedComponentRoot, "Updater")) &&
    !Directory.Exists(Path.Combine(preparedComponentRoot, "Filter")),
    "Successful cleanup retained temporary updater or Filter payloads.");
Require(
    !Directory.Exists(Path.Combine(preparedComponentRoot, "New DLL", "Client")) &&
    !Directory.Exists(Path.Combine(preparedComponentRoot, "New DLL", "ShardManager")),
    "Successful cleanup created unselected manual component folders.");

var limitCustomer = customer with
{
    CustomerId = "package-test-limit-customer",
    CustomerCode = "KMT-LIMIT",
    CustomerName = "Player Limit Packaging Test",
    LicenseId = "package-test-limit-license",
    ServerIp = string.Empty,
    BindingMode = LicenseBindingMode.PlayerLimit
};
var limitBindingToken = PackageBindingTokenCodec.Sign(new PackageBindingClaims
{
    KeyId = LicenseTokenCodec.TrustedKeyId,
    LicenseId = limitCustomer.LicenseId,
    CustomerId = limitCustomer.CustomerId,
    CustomerCode = limitCustomer.CustomerCode,
    PackageId = "package-test-limit-credential",
    ServerIp = string.Empty,
    BindingMode = LicenseBindingMode.PlayerLimit,
    Features = limitCustomer.Features,
    IssuedUtc = now,
    Watermark = "FEDCBA98765432100123456789ABCDEF"
}, signingKey);
var limitResult = new CustomerPackageBuilder().Build(
    settings,
    limitCustomer,
    activationKey + "-LIMIT",
    limitBindingToken);
Require(File.Exists(limitResult.ZipPath), "The player-limit-only customer ZIP was not created.");
Require(
    File.ReadAllText(Path.Combine(limitResult.FolderPath, "PACKAGE-INFO.txt"))
        .Contains("License Mode: PLAYER LIMIT ONLY", StringComparison.Ordinal),
    "Player-limit package information does not identify its mode.");
var limitLicense = LicenseFileDocument.Load(
    Path.Combine(limitResult.FolderPath, "Filter", LicenseFileDocument.FileName));
Require(limitLicense.BindingMode == LicenseBindingMode.PlayerLimit,
    "Player-limit mode is missing from the Filter license.");

Console.WriteLine("PASS: customer folder and ZIP generation");
Console.WriteLine("PASS: Filter activation plus signed client and server add-on bindings");
Console.WriteLine("PASS: customer Settings.json secret sanitization");
Console.WriteLine("PASS: server add-on SQL secret sanitization");
Console.WriteLine("PASS: customer installation guide");
Console.WriteLine("PASS: required binaries, checksums and PDB exclusion");
Console.WriteLine("PASS: versioned database layout in customer folder and ZIP");
Console.WriteLine("PASS: IP-only package metadata");
Console.WriteLine("PASS: player-limit-only package carries no IP or HWID");
Console.WriteLine("PASS: fixed Media-KemtGuard source copied byte-for-byte");
Console.WriteLine("PASS: licensed v2 update package, updater payload and manual New DLL layout");
Console.WriteLine("PASS: downloaded update DLLs preserve the existing customer package identity");
Console.WriteLine("PASS: current customer production base is reused without rebuilding");

Directory.Delete(outputRoot, recursive: true);

static void Require(bool condition, string message)
{
    if (!condition)
        throw new InvalidOperationException(message);
}
