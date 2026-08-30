using System.ComponentModel;
using System.Windows;
using System.Windows.Media.Animation;
using KMTGuard.AdminDesktop.Services;

namespace KMTGuard.AdminDesktop;

public partial class LicenseActivationWindow : Window
{
    private readonly CustomerLicenseService _service;
    private bool _closeCommitted;
    private bool _windowPresented;

    public LicenseActivationWindow(CustomerLicenseService service, string initialMessage)
    {
        InitializeComponent();
        _service = service;
        var document = service.LoadBootstrap();
        ServerUrlBox.Text = document.ServerUrl;
        ActivationKeyBox.Text = document.ActivationKey;
        MachineIdText.Text = service.MachineDisplayId;
        StatusText.Text = initialMessage;
    }

    private void ActivationWindow_ContentRendered(object? sender, EventArgs e)
    {
        if (_windowPresented)
            return;

        _windowPresented = true;
        var easing = new CubicEase { EasingMode = EasingMode.EaseOut };
        BeginAnimation(OpacityProperty, new DoubleAnimation(0, 1, TimeSpan.FromMilliseconds(180)) { EasingFunction = easing });
        WindowScaleTransform.BeginAnimation(System.Windows.Media.ScaleTransform.ScaleXProperty,
            new DoubleAnimation(0.985, 1, TimeSpan.FromMilliseconds(180)) { EasingFunction = easing });
        WindowScaleTransform.BeginAnimation(System.Windows.Media.ScaleTransform.ScaleYProperty,
            new DoubleAnimation(0.985, 1, TimeSpan.FromMilliseconds(180)) { EasingFunction = easing });
    }

    private void ActivationWindow_Closing(object? sender, CancelEventArgs e)
    {
        if (_closeCommitted)
            return;

        e.Cancel = true;
        IsEnabled = false;
        var fade = new DoubleAnimation(Opacity, 0, TimeSpan.FromMilliseconds(120));
        fade.Completed += (_, _) =>
        {
            _closeCommitted = true;
            Dispatcher.BeginInvoke(new Action(Close));
        };
        BeginAnimation(OpacityProperty, fade);
    }

    private void CloseWindowButton_Click(object sender, RoutedEventArgs e) => Close();

    private void CopyMachineIdButton_Click(object sender, RoutedEventArgs e)
    {
        try
        {
            Clipboard.SetText(MachineIdText.Text);
            StatusText.Text = "Server ID copied to the clipboard.";
        }
        catch (Exception ex)
        {
            StatusText.Text = ex.Message;
        }
    }

    private async void ActivateButton_Click(object sender, RoutedEventArgs e)
    {
        if (!Uri.TryCreate(ServerUrlBox.Text.Trim(), UriKind.Absolute, out var server) ||
            (server.Scheme != Uri.UriSchemeHttp && server.Scheme != Uri.UriSchemeHttps))
        {
            StatusText.Text = "Enter a valid License Server HTTP or HTTPS address.";
            return;
        }
        if (ActivationKeyBox.Text.Trim().Length < 20)
        {
            StatusText.Text = "Activation key is missing or invalid.";
            return;
        }

        ActivateButton.IsEnabled = false;
        StatusText.Text = "Contacting KMTGuard License Server...";
        try
        {
            _service.SaveBootstrap(server.ToString(), ActivationKeyBox.Text);
            var result = await _service.EnsureValidAsync(forceOnline: true);
            if (!result.IsValid)
            {
                StatusText.Text = result.Message;
                return;
            }

            DialogResult = true;
        }
        catch (Exception ex)
        {
            StatusText.Text = ex.Message;
        }
        finally
        {
            ActivateButton.IsEnabled = true;
        }
    }
}
