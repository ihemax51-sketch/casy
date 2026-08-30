namespace KMTGuard.LicenseServer;

public sealed class LicenseServerOptions
{
    public int PublicPort { get; set; } = 5127;
    public int AdminPort { get; set; } = 5128;
    public string DatabasePath { get; set; } = @"%ProgramData%\KMTGuardLicensing\Data\licenses.db";
    public string PrivateKeyPath { get; set; } = @"%ProgramData%\KMTGuardLicensing\Secrets\license-signing-key.pk8";
    public string AdminTokenPath { get; set; } = @"%ProgramData%\KMTGuardLicensing\Secrets\admin-api-token.txt";
    public string UpdateRoot { get; set; } = @"%ProgramData%\KMTGuardLicensing\Updates";
    public int DefaultOfflineHours { get; set; } = 24;

    public static string ExpandPath(string value) => Environment.ExpandEnvironmentVariables(value);
}
