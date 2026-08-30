using System.Windows;
using KMTGuard.LicenseAdmin.Models;

namespace KMTGuard.LicenseAdmin;

public partial class SettingsWindow : Window
{
    public OwnerSettings? Settings { get; private set; }

    public SettingsWindow(OwnerSettings settings)
    {
        InitializeComponent();
        Settings = settings;
        AdminApiBox.Text = settings.AdminApiUrl;
        PublicApiBox.Text = settings.PublicApiUrl;
        CertificatePinBox.Text = settings.CertificateSha256;
        SourceRootBox.Text = settings.SourceRoot;
        BuildRootBox.Text = settings.BaseBuildRoot;
        OutputRootBox.Text = settings.CustomerOutputRoot;
        UpdateRootBox.Text = settings.UpdateRoot;
    }

    private void SaveButton_Click(object sender, RoutedEventArgs e)
    {
        if (!Uri.TryCreate(AdminApiBox.Text.Trim(), UriKind.Absolute, out var admin) ||
            !Uri.TryCreate(PublicApiBox.Text.Trim(), UriKind.Absolute, out var publicApi) ||
            (admin.Scheme != Uri.UriSchemeHttp && admin.Scheme != Uri.UriSchemeHttps) ||
            (publicApi.Scheme != Uri.UriSchemeHttp && publicApi.Scheme != Uri.UriSchemeHttps))
        {
            ErrorText.Text = "Enter valid HTTP or HTTPS License Server addresses.";
            return;
        }

        if (string.IsNullOrWhiteSpace(SourceRootBox.Text) ||
            string.IsNullOrWhiteSpace(BuildRootBox.Text) ||
            string.IsNullOrWhiteSpace(OutputRootBox.Text) ||
            string.IsNullOrWhiteSpace(UpdateRootBox.Text))
        {
            ErrorText.Text = "Source, build, and customer package paths are required.";
            return;
        }

        var certificatePin = CertificatePinBox.Text.Trim().Replace(":", string.Empty).ToUpperInvariant();
        if (certificatePin.Length > 0 &&
            (certificatePin.Length != 64 || certificatePin.Any(character => !Uri.IsHexDigit(character))))
        {
            ErrorText.Text = "The optional certificate pin must contain exactly 64 hexadecimal characters.";
            return;
        }

        if (publicApi.Scheme != Uri.UriSchemeHttps)
        {
            ErrorText.Text = "The public customer address must use HTTPS.";
            return;
        }

        Settings = new OwnerSettings
        {
            AdminApiUrl = admin.ToString().TrimEnd('/'),
            PublicApiUrl = publicApi.ToString().TrimEnd('/'),
            CertificateSha256 = certificatePin,
            SourceRoot = Path.GetFullPath(SourceRootBox.Text.Trim()),
            BaseBuildRoot = Path.GetFullPath(BuildRootBox.Text.Trim()),
            CustomerOutputRoot = Path.GetFullPath(OutputRootBox.Text.Trim()),
            UpdateRoot = Environment.ExpandEnvironmentVariables(UpdateRootBox.Text.Trim())
        };
        DialogResult = true;
    }
}
