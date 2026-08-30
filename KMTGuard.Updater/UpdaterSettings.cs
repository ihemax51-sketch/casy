using System.Text.Json;

namespace KMTGuard.Updater;

public sealed class UpdaterSettings
{
    public string InstalledVersion { get; set; } = string.Empty;
    public string ClientFolder { get; set; } = string.Empty;
    public string GameServerFolder { get; set; } = string.Empty;
    public string ShardManagerFolder { get; set; } = string.Empty;

    private static string SettingsPath =>
        Path.Combine(AppContext.BaseDirectory, "KMTGuard-Updater.json");

    public static UpdaterSettings Load()
    {
        try
        {
            return File.Exists(SettingsPath)
                ? JsonSerializer.Deserialize<UpdaterSettings>(
                      File.ReadAllText(SettingsPath),
                      new JsonSerializerOptions { PropertyNameCaseInsensitive = true })
                  ?? new UpdaterSettings()
                : new UpdaterSettings();
        }
        catch
        {
            return new UpdaterSettings();
        }
    }

    public void Save()
    {
        var temporary = SettingsPath + $".{Guid.NewGuid():N}.tmp";
        File.WriteAllText(
            temporary,
            JsonSerializer.Serialize(this, new JsonSerializerOptions { WriteIndented = true }));
        File.Move(temporary, SettingsPath, overwrite: true);
    }
}
