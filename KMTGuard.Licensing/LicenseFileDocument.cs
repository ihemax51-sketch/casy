namespace KMTGuard.Licensing;

public sealed class LicenseFileDocument
{
    public const string FileName = "KMTGuard-License.txt";
    public const string Header = "KMTGUARD-LICENSE-V1";

    public string ServerUrl { get; set; } = string.Empty;
    public string CertificateSha256 { get; set; } = string.Empty;
    public string ActivationKey { get; set; } = string.Empty;
    public string LeaseToken { get; set; } = string.Empty;
    public LicenseBindingMode BindingMode { get; set; } = LicenseBindingMode.Ip;

    public static LicenseFileDocument Load(string path)
    {
        var lines = File.ReadAllLines(path);
        if (lines.Length == 0 || !lines[0].Trim().Equals(Header, StringComparison.Ordinal))
            throw new InvalidDataException("KMTGuard license file header is invalid.");

        var values = lines.Skip(1)
            .Select(line => line.Split('=', 2))
            .Where(parts => parts.Length == 2)
            .ToDictionary(parts => parts[0].Trim(), parts => parts[1].Trim(), StringComparer.OrdinalIgnoreCase);

        return new LicenseFileDocument
        {
            ServerUrl = values.GetValueOrDefault("ServerUrl", string.Empty),
            CertificateSha256 = values.GetValueOrDefault("CertificateSha256", string.Empty),
            ActivationKey = values.GetValueOrDefault("ActivationKey", string.Empty),
            LeaseToken = values.GetValueOrDefault("Lease", string.Empty),
            BindingMode = LicenseBindingModeExtensions.Parse(values.GetValueOrDefault("BindingMode", "IP"))
        };
    }

    public void Save(string path)
    {
        var directory = Path.GetDirectoryName(path);
        if (!string.IsNullOrWhiteSpace(directory))
            Directory.CreateDirectory(directory);

        var content = string.Join(Environment.NewLine,
            Header,
            $"ServerUrl={ServerUrl.Trim().TrimEnd('/')}",
            $"CertificateSha256={CertificateSha256.Trim().Replace(":", string.Empty).ToUpperInvariant()}",
            $"ActivationKey={ActivationKey.Trim()}",
            $"BindingMode={BindingMode.ToClaimValue()}",
            $"Lease={LeaseToken.Trim()}",
            string.Empty);
        var temporaryPath = path + $".{Environment.ProcessId}.{Guid.NewGuid():N}.tmp";
        File.WriteAllText(temporaryPath, content);
        File.Move(temporaryPath, path, overwrite: true);
    }

    public static string GetLocalPath() =>
        Environment.GetEnvironmentVariable("KMTGUARD_LICENSE_PATH") is { Length: > 0 } configured
            ? Path.GetFullPath(configured)
            : Path.Combine(AppContext.BaseDirectory, FileName);

    public static string GetMachinePath() => Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData),
        "KMTGuard",
        FileName);
}
