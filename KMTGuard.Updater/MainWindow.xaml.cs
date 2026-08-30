using System.Diagnostics;
using System.Reflection;
using System.Windows;
using System.Windows.Media;
using System.Windows.Media.Animation;
using KMTGuard.Licensing;
using Microsoft.Win32;

namespace KMTGuard.Updater;

public partial class MainWindow : Window
{
    private readonly bool _backgroundCheck;
    private readonly UpdateInstaller _installer = new();
    private readonly UpdaterSettings _settings = UpdaterSettings.Load();
    private UpdateCheckResponse? _check;
    private string? _preparedRoot;
    private bool _busy;

    public MainWindow(bool backgroundCheck)
    {
        InitializeComponent();
        _backgroundCheck = backgroundCheck;
        ClientPathBox.Text = _settings.ClientFolder;
        GameServerPathBox.Text = _settings.GameServerFolder;
        ShardManagerPathBox.Text = _settings.ShardManagerFolder;
        CurrentVersionText.Text = $"INSTALLED · v{CurrentVersion}";
    }

    private string CurrentVersion
    {
        get
        {
            var executableVersion =
                Assembly.GetExecutingAssembly().GetName().Version ?? new Version(2, 5, 0);
            return Version.TryParse(_settings.InstalledVersion, out var installedVersion) &&
                   installedVersion > executableVersion
                ? installedVersion.ToString(3)
                : executableVersion.ToString(3);
        }
    }

    private async void Window_Loaded(object sender, RoutedEventArgs e)
    {
        if (!_backgroundCheck)
            RevealWindow();
        await CheckAsync();
    }

    private void RevealWindow()
    {
        if (Opacity > 0.9)
            return;
        BeginAnimation(OpacityProperty, new DoubleAnimation(0, 1, TimeSpan.FromMilliseconds(180)));
    }

    private async Task CheckAsync()
    {
        if (_busy)
            return;
        SetBusy(true, "Checking the licensed release channel...");
        CheckStepText.Text = "Contacting License Server";
        try
        {
            using var client = new UpdateClient();
            _check = await client.CheckAsync(CurrentVersion, CancellationToken.None);
            if (!_check.UpdateAvailable || _check.Release is null)
            {
                if (_backgroundCheck)
                {
                    Close();
                    return;
                }
                ReleaseTitleText.Text = "KMTGuard is up to date";
                ReleaseNotesText.Text = $"Version {CurrentVersion} is the newest release available for this license.";
                ReleaseVersionText.Text = "CURRENT";
                SummaryText.Text = "Your licensed installation is current.";
                CheckStepText.Text = "Current";
                ProgressTitleText.Text = "No update required";
                SetState(true, "KMTGuard is up to date.");
                return;
            }

            RevealWindow();
            var release = _check.Release;
            ReleaseTitleText.Text = release.Title;
            ReleaseNotesText.Text = release.Notes;
            ReleaseVersionText.Text = $"v{release.Version}";
            SummaryText.Text = release.IsMandatory
                ? "A required licensed update is ready."
                : "A new licensed update is ready.";
            CheckStepText.Text = $"v{release.Version} available";
            ProgressTitleText.Text = "Ready to download";
            var filterFileCount = release.Files.Count(item => item.Kind == UpdateFileKind.Filter);
            var dllFileCount = CountDllFiles(release);
            FilterStatusText.Text = filterFileCount > 0
                ? $"{filterFileCount} protected Filter file(s)"
                : "No Filter files in this release";
            DllStatusText.Text = dllFileCount > 0
                ? $"{dllFileCount} DLL file(s) will stay manual"
                : "No DLL files in this release";
            InstallStepTitle.Text = filterFileCount > 0 ? "Install Filter" : "Complete update";
            InstallStepText.Text = filterFileCount > 0
                ? "Manual files stay available"
                : "Keep selected manual files";
            PrimaryActionButton.Content = "Download update";
            PrimaryActionButton.IsEnabled = true;
            SetState(true, $"KMTGuard {release.Version} is available.");
        }
        catch (Exception ex)
        {
            RevealWindow();
            CheckStepText.Text = "Check failed";
            ReleaseTitleText.Text = "Update check unavailable";
            ReleaseNotesText.Text = ex.Message;
            ReleaseVersionText.Text = "RETRY";
            SetState(false, ex.Message);
        }
        finally
        {
            SetBusy(false);
        }
    }

    private async void PrimaryActionButton_Click(object sender, RoutedEventArgs e)
    {
        if (_check?.Release is not { } release)
            return;
        if (_preparedRoot is null)
            await DownloadAndPrepareAsync(release);
        else
            await CompleteUpdateAsync(release);
    }

    private async Task DownloadAndPrepareAsync(UpdateReleaseInfo release)
    {
        if (string.IsNullOrWhiteSpace(_check?.PackageBindingToken))
        {
            SetState(false, "The update authorization did not include a package binding.");
            return;
        }
        SetBusy(true, "Downloading the signed update package...");
        DownloadStepCard.Background = (Brush)FindResource("PanelBrush");
        DownloadStepText.Text = "Downloading";
        var tempRoot = Path.Combine(AppContext.BaseDirectory, "Updates", ".downloads");
        Directory.CreateDirectory(tempRoot);
        var packagePath = Path.Combine(tempRoot, $"KMTGuard-{release.Version}.zip");
        try
        {
            using var client = new UpdateClient();
            var progress = new Progress<double>(value =>
            {
                DownloadProgress.Value = value * 100;
                ProgressPercentText.Text = $"{value:P0}";
                ProgressTitleText.Text = "Downloading and verifying";
            });
            await client.DownloadAsync(release.Version, packagePath, progress, CancellationToken.None);
            _preparedRoot = await _installer.PrepareAsync(
                packagePath,
                release,
                _check.PackageBindingToken!,
                CancellationToken.None);
            var hasFilter = HasFilterFiles(release);
            var dllFileCount = CountDllFiles(release);
            DownloadStepText.Text = "Verified and prepared";
            DllStatusText.Text = dllFileCount > 0
                ? "Personalized DLLs are ready inside New DLL."
                : "This release contains no DLL files.";
            FilterStatusText.Text = hasFilter
                ? "Filter update is staged and ready to install."
                : "No Filter installation is required.";
            PrimaryActionButton.Content = hasFilter ? "Install Filter" : "Complete update";
            PrimaryActionButton.IsEnabled = true;
            OpenDllButton.IsEnabled = dllFileCount > 0;
            VerifyDllButton.IsEnabled = dllFileCount > 0;
            InstallStepCard.Background = (Brush)FindResource("PanelBrush");
            ProgressTitleText.Text = "Update prepared successfully";
            SetState(
                true,
                hasFilter
                    ? "Download verified. Install Filter when ready; manual files will remain available."
                    : "Download verified. Complete the manual work, then mark the update complete.");
        }
        catch (Exception ex)
        {
            DownloadStepText.Text = "Failed";
            SetState(false, ex.Message);
        }
        finally
        {
            try { if (File.Exists(packagePath)) File.Delete(packagePath); } catch { }
            SetBusy(false);
        }
    }

    private async Task CompleteUpdateAsync(UpdateReleaseInfo release)
    {
        if (_preparedRoot is null)
            return;

        var hasFilter = HasFilterFiles(release);
        var confirmation = hasFilter
            ? "KMTGuard services and the Desktop Dashboard will close while Filter files are updated.\n\n" +
              "DLL, SQL and Media files will NOT be copied automatically. Continue?"
            : "No Filter files will be changed.\n\n" +
              "Complete the manual DLL, SQL or Media work first, then continue to mark this release complete.";
        if (MessageBox.Show(
                this,
                confirmation,
                hasFilter ? "Install Filter Update" : "Complete Manual Update",
                MessageBoxButton.YesNo,
                MessageBoxImage.Warning) != MessageBoxResult.Yes)
            return;

        SetBusy(
            true,
            hasFilter
                ? "Installing Filter files and creating a rollback copy..."
                : "Completing the manual update...");
        try
        {
            var filterInstalled = await _installer.ApplyFilterAsync(
                _preparedRoot,
                release,
                CancellationToken.None);
            _settings.InstalledVersion = release.Version;
            _settings.Save();
            var replacementScheduled = false;
            string? maintenanceWarning = null;
            try
            {
                replacementScheduled = UpdaterSelfReplace.Schedule(_preparedRoot);
            }
            catch (Exception ex)
            {
                maintenanceWarning = $"Updater replacement was deferred: {ex.Message}";
            }
            try
            {
                _installer.CleanupAfterSuccess(_preparedRoot);
            }
            catch (Exception ex)
            {
                maintenanceWarning = $"Update installed, but temporary cleanup was deferred: {ex.Message}";
            }

            InstallStepText.Text = filterInstalled ? "Filter installed" : "Update completed";
            FilterStatusText.Text = filterInstalled
                ? $"Filter {release.Version} installed successfully."
                : "No Filter files were changed.";
            PrimaryActionButton.IsEnabled = false;
            ProgressTitleText.Text = "Update complete";
            SetState(
                maintenanceWarning is null,
                maintenanceWarning ??
                (filterInstalled
                    ? "Filter updated. Only selected manual files were kept."
                    : "Manual update completed. Only selected files were kept."));

            if (replacementScheduled)
            {
                Close();
            }
            else
            {
                var dashboard = Path.Combine(AppContext.BaseDirectory, "KMTGuard.exe");
                if (File.Exists(dashboard))
                    Process.Start(new ProcessStartInfo(dashboard) { UseShellExecute = true });
            }
        }
        catch (Exception ex)
        {
            InstallStepText.Text = "Installation failed";
            SetState(
                false,
                hasFilter
                    ? $"Filter update failed and rollback was attempted: {ex.Message}"
                    : $"The update could not be completed: {ex.Message}");
        }
        finally
        {
            SetBusy(false);
        }
    }

    private async void CheckButton_Click(object sender, RoutedEventArgs e) => await CheckAsync();

    private void OpenDllButton_Click(object sender, RoutedEventArgs e)
    {
        if (_preparedRoot is null)
            return;
        var path = Path.Combine(_preparedRoot, "New DLL");
        if (Directory.Exists(path))
            Process.Start(new ProcessStartInfo("explorer.exe", $"\"{path}\"") { UseShellExecute = true });
    }

    private void VerifyDllButton_Click(object sender, RoutedEventArgs e)
    {
        if (_preparedRoot is null)
            return;
        SavePaths();
        var dlls = _installer.LoadPreparedDlls(_preparedRoot);
        SetVerifyState(
            ClientVerifyText,
            Verify(dlls, UpdateFileKind.ClientDll, ClientPathBox.Text, "KMTGuardKit.dll"));
        SetVerifyState(
            GameServerVerifyText,
            Verify(dlls, UpdateFileKind.GameServerDll, GameServerPathBox.Text, "KMTGuard_GameServer.dll"));
        SetVerifyState(
            ShardVerifyText,
            Verify(dlls, UpdateFileKind.ShardManagerDll, ShardManagerPathBox.Text, "KMTGuard_ShardManager.dll"));
        SetState(true, "DLL installation paths were checked.");
    }

    private static bool Verify(
        IReadOnlyList<UpdateInstaller.PreparedDll> dlls,
        UpdateFileKind kind,
        string folder,
        string fileName)
    {
        var expected = dlls.FirstOrDefault(item => item.Kind == kind);
        return expected is not null &&
               UpdateInstaller.VerifyInstalledDll(folder.Trim(), fileName, expected);
    }

    private static bool HasFilterFiles(UpdateReleaseInfo release) =>
        release.Files.Any(item =>
            item.Kind == UpdateFileKind.Filter &&
            item.RelativePath.StartsWith("Filter/", StringComparison.OrdinalIgnoreCase));

    private static int CountDllFiles(UpdateReleaseInfo release) =>
        release.Files.Count(item => item.Kind is
            UpdateFileKind.ClientDll or
            UpdateFileKind.GameServerDll or
            UpdateFileKind.ShardManagerDll);

    private void BrowseClientButton_Click(object sender, RoutedEventArgs e) => BrowseInto(ClientPathBox);
    private void BrowseGameServerButton_Click(object sender, RoutedEventArgs e) => BrowseInto(GameServerPathBox);
    private void BrowseShardButton_Click(object sender, RoutedEventArgs e) => BrowseInto(ShardManagerPathBox);

    private void BrowseInto(System.Windows.Controls.TextBox target)
    {
        var dialog = new OpenFolderDialog
        {
            Title = "Select the installed component folder",
            Multiselect = false,
            InitialDirectory = Directory.Exists(target.Text) ? target.Text : AppContext.BaseDirectory
        };
        if (dialog.ShowDialog(this) != true)
            return;
        target.Text = dialog.FolderName;
        SavePaths();
    }

    private void SavePaths()
    {
        _settings.ClientFolder = ClientPathBox.Text.Trim();
        _settings.GameServerFolder = GameServerPathBox.Text.Trim();
        _settings.ShardManagerFolder = ShardManagerPathBox.Text.Trim();
        _settings.Save();
    }

    private void SetBusy(bool busy, string message = "")
    {
        _busy = busy;
        CheckButton.IsEnabled = !busy;
        PrimaryActionButton.IsEnabled = !busy && _check?.UpdateAvailable == true;
        if (!string.IsNullOrWhiteSpace(message))
            GlobalStatusText.Text = message;
    }

    private void SetState(bool success, string message)
    {
        GlobalStatusDot.Fill = (Brush)FindResource(success ? "SuccessBrush" : "RoseBrush");
        GlobalStatusText.Text = message;
    }

    private void SetVerifyState(System.Windows.Controls.TextBlock target, bool installed)
    {
        target.Text = installed ? "Installed" : "Not installed";
        target.Foreground = (Brush)FindResource(installed ? "SuccessBrush" : "RoseBrush");
    }

    private void MinimizeButton_Click(object sender, RoutedEventArgs e) => WindowState = WindowState.Minimized;
    private void CloseButton_Click(object sender, RoutedEventArgs e) => Close();
}
