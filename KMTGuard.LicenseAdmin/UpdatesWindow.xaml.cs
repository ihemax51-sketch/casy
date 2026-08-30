using System.Collections.ObjectModel;
using System.Windows;
using KMTGuard.LicenseAdmin.Models;
using KMTGuard.LicenseAdmin.Services;
using KMTGuard.Licensing;

namespace KMTGuard.LicenseAdmin;

public partial class UpdatesWindow : Window
{
    private readonly OwnerSettings _settings;
    private readonly UpdatePackageBuilder _builder = new();
    private readonly ObservableCollection<UpdateReleaseInfo> _releases = new();

    public UpdatesWindow(OwnerSettings settings)
    {
        InitializeComponent();
        _settings = settings;
        ReleasesList.ItemsSource = _releases;
        Reload();
    }

    private void Reload()
    {
        _releases.Clear();
        foreach (var release in _builder.ReadPublished(_settings))
            _releases.Add(release);

        VersionBox.Text = GetNextVersion();
    }

    private async void PublishButton_Click(object sender, RoutedEventArgs e)
    {
        // WPF controls are UI-thread owned, so capture their values before
        // moving the package work onto a background thread.
        var version = VersionBox.Text;
        var title = TitleBox.Text;
        var notes = NotesBox.Text;
        var mandatory = MandatoryBox.IsChecked == true;
        var selection = new UpdatePackageSelection(
            FilterBox.IsChecked == true,
            ClientDllBox.IsChecked == true,
            GameServerDllBox.IsChecked == true,
            ShardManagerDllBox.IsChecked == true,
            IncludeDatabaseBox.IsChecked == true,
            IncludeMediaBox.IsChecked == true);

        PublishButton.IsEnabled = false;
        PublishButton.Content = "Publishing...";
        ErrorText.Text = string.Empty;
        StatusText.Text = $"Preparing update v{version.Trim().TrimStart('v', 'V')}...";
        try
        {
            var release = await Task.Run(() => _builder.Publish(
                _settings,
                version,
                title,
                notes,
                mandatory,
                selection));
            Reload();
            StatusText.Text = $"Update v{release.Version} published successfully.";
            MessageBox.Show(
                this,
                $"KMTGuard {release.Version} was published successfully.\n\nActive licensed customers can now download it.",
                "Update Published",
                MessageBoxButton.OK,
                MessageBoxImage.Information);
        }
        catch (Exception ex)
        {
            ErrorText.Text = ex.Message;
            StatusText.Text = "The update was not published.";
            MessageBox.Show(
                this,
                ex.Message,
                "Could Not Publish Update",
                MessageBoxButton.OK,
                MessageBoxImage.Error);
        }
        finally
        {
            PublishButton.IsEnabled = true;
            PublishButton.Content = "Publish update";
        }
    }

    private string GetNextVersion()
    {
        var latest = _releases
            .Select(release => Version.TryParse(release.Version, out var version) ? version : null)
            .Where(version => version is not null)
            .OrderByDescending(version => version)
            .FirstOrDefault();

        return latest is null
            ? "2.5.7"
            : $"{latest.Major}.{latest.Minor}.{Math.Max(0, latest.Build) + 1}";
    }
}
