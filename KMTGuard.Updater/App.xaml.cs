using System.Windows;

namespace KMTGuard.Updater;

public partial class App : Application
{
    protected override void OnStartup(StartupEventArgs e)
    {
        base.OnStartup(e);
        if (UpdaterSelfReplace.TryRun(e.Args))
        {
            Shutdown();
            return;
        }
        UpdaterSelfReplace.CleanupStaleFiles();
        try
        {
            var settings = UpdaterSettings.Load();
            new UpdateInstaller().CleanupCompletedUpdates(settings.InstalledVersion);
        }
        catch
        {
            // A locked legacy file can be retried on the next normal launch.
        }
        var backgroundCheck = e.Args.Any(
            value => value.Equals("--background-check", StringComparison.OrdinalIgnoreCase));
        var window = new MainWindow(backgroundCheck);
        MainWindow = window;
        window.Show();
    }
}
