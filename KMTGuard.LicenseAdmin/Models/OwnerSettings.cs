namespace KMTGuard.LicenseAdmin.Models;

public sealed class OwnerSettings
{
    public string AdminApiUrl { get; set; } = "http://127.0.0.1:5128";
    public string PublicApiUrl { get; set; } = "https://license.play-casy.online";
    public string CertificateSha256 { get; set; } = string.Empty;
    public string SourceRoot { get; set; } = @"D:\kmt-source";
    public string BaseBuildRoot { get; set; } = @"D:\KMTGuard-build";
    public string CustomerOutputRoot { get; set; } = @"D:\KMTGuard-build\Customers";
    public string UpdateRoot { get; set; } = @"%ProgramData%\KMTGuardLicensing\Updates";

    public OwnerSettings Clone() => new()
    {
        AdminApiUrl = AdminApiUrl,
        PublicApiUrl = PublicApiUrl,
        CertificateSha256 = CertificateSha256,
        SourceRoot = SourceRoot,
        BaseBuildRoot = BaseBuildRoot,
        CustomerOutputRoot = CustomerOutputRoot,
        UpdateRoot = UpdateRoot
    };
}
