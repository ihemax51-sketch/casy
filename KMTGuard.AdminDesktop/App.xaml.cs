using System.Windows;

using System.Diagnostics;
using System.IO;

namespace KMTGuard.AdminDesktop;

public partial class App : Application
{
    protected override async void OnStartup(StartupEventArgs e)
    {
        base.OnStartup(e);
        ShutdownMode = ShutdownMode.OnExplicitShutdown;

#if KMT_DEVELOPMENT_BUILD
        await Task.CompletedTask;
        var mainWindow = new MainWindow(KMTGuard.Licensing.DevelopmentLicense.CreateClaims());
        MainWindow = mainWindow;
        ShutdownMode = ShutdownMode.OnMainWindowClose;
        mainWindow.Show();
#else
        var service = new Services.CustomerLicenseService();
        var result = await service.EnsureValidAsync(forceOnline: true);
        if (!result.IsValid)
        {
            var activationWindow = new LicenseActivationWindow(service, result.Message);
            if (activationWindow.ShowDialog() != true)
            {
                Shutdown(17);
                return;
            }

            result = await service.EnsureValidAsync(forceOnline: false);
            if (!result.IsValid)
            {
                MessageBox.Show(result.Message, "KMTGuard License", MessageBoxButton.OK, MessageBoxImage.Error);
                Shutdown(17);
                return;
            }
        }

        var mainWindow = new MainWindow(result.Claims);
        MainWindow = mainWindow;
        ShutdownMode = ShutdownMode.OnMainWindowClose;
        mainWindow.Show();
        StartUpdaterBackgroundCheck();
#endif
    }

    private static void StartUpdaterBackgroundCheck()
    {
        try
        {
            var path = Path.Combine(AppContext.BaseDirectory, "KMTGuard.Updater.exe");
            if (File.Exists(path))
            {
                Process.Start(new ProcessStartInfo(path, "--background-check")
                {
                    UseShellExecute = true
                });
            }
        }
        catch
        {
            // Update checks never block the licensed administration workspace.
        }
    }
}
