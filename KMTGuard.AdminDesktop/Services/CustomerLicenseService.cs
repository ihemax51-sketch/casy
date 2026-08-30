using System.IO;
using System.Reflection;
using KMTGuard.Licensing;

namespace KMTGuard.AdminDesktop.Services;

public sealed class CustomerLicenseService
{
    private readonly LicenseClient _client = new();

    public string MachineDisplayId => MachineFingerprint.GetDisplayId();
    public string LicensePath => LicenseFileDocument.GetLocalPath();

    public Task<LicenseClientResult> EnsureValidAsync(bool forceOnline, CancellationToken cancellationToken = default)
    {
        var serverIp = string.Empty;
        try
        {
            var settingsService = new SettingsFileService();
            var path = settingsService.FindDefaultSettingsPath();
            if (path is not null && File.Exists(path))
                serverIp = settingsService.Load(path).ServerIP;
        }
        catch
        {
        }

            var version = Assembly.GetExecutingAssembly().GetName().Version?.ToString(3) ?? "2.7.5";
        return _client.EnsureValidAsync(LicenseFeature.Filter, serverIp, version, forceOnline, cancellationToken);
    }

    public void SaveBootstrap(string serverUrl, string activationKey)
    {
        LicenseFileDocument document;
        try
        {
            document = File.Exists(LicensePath)
                ? LicenseFileDocument.Load(LicensePath)
                : new LicenseFileDocument();
        }
        catch
        {
            document = new LicenseFileDocument();
        }

        document.ServerUrl = serverUrl.Trim().TrimEnd('/');
        document.ActivationKey = activationKey.Trim();
        document.Save(LicensePath);
    }

    public LicenseFileDocument LoadBootstrap()
    {
        try
        {
            return File.Exists(LicensePath) ? LicenseFileDocument.Load(LicensePath) : new LicenseFileDocument();
        }
        catch
        {
            return new LicenseFileDocument();
        }
    }
}
