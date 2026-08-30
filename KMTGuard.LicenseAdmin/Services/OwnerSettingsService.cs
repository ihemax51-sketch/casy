using System.Text.Json;
using KMTGuard.LicenseAdmin.Models;

namespace KMTGuard.LicenseAdmin.Services;

public sealed class OwnerSettingsService
{
    private static readonly JsonSerializerOptions JsonOptions = new() { WriteIndented = true };
    private readonly string _path = Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData),
        "KMTGuardLicensing",
        "license-admin-settings.json");

    public OwnerSettings Load()
    {
        try
        {
            return File.Exists(_path)
                ? JsonSerializer.Deserialize<OwnerSettings>(File.ReadAllText(_path)) ?? new OwnerSettings()
                : new OwnerSettings();
        }
        catch
        {
            return new OwnerSettings();
        }
    }

    public void Save(OwnerSettings settings)
    {
        Directory.CreateDirectory(Path.GetDirectoryName(_path)!);
        File.WriteAllText(_path, JsonSerializer.Serialize(settings, JsonOptions));
    }
}
