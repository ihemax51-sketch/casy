using System.Collections.ObjectModel;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Input;
using System.Windows.Media;
using KMTGuard.LicenseAdmin.Models;
using KMTGuard.LicenseAdmin.Services;
using KMTGuard.Licensing;
using Microsoft.Win32;

namespace KMTGuard.LicenseAdmin;

public partial class MainWindow : Window
{
    private readonly OwnerSettingsService _settingsService = new();
    private readonly CustomerProductionRefresher _productionRefresher = new();
    private readonly CustomerPackageBuilder _packageBuilder = new();
    private readonly CustomerBinaryPersonalizer _personalizer = new();
    private readonly ObservableCollection<OwnerCustomerSummary> _customers = new();
    private OwnerSettings _settings;
    private OwnerApiClient? _api;
    private bool _busy;

    public MainWindow()
    {
        InitializeComponent();
        _settings = _settingsService.Load();
        CustomersGrid.ItemsSource = _customers;
    }

    private async void Window_Loaded(object sender, RoutedEventArgs e) => await ReconnectAndLoadAsync();

    private async Task ReconnectAndLoadAsync()
    {
        SetBusy(true, "Connecting to License Server...");
        try
        {
            _api?.Dispose();
            _api = new OwnerApiClient(_settings);
            await LoadDataAsync();
            SetServerState(true, "License Server connected.");
        }
        catch (Exception ex)
        {
            SetServerState(false, ex.Message);
        }
        finally { SetBusy(false); }
    }

    private async Task LoadDataAsync()
    {
        if (_api is null)
            return;
        var selectedId = SelectedCustomer?.CustomerId;
        var dashboard = await _api.GetDashboardAsync();
        var customers = await _api.GetCustomersAsync(SearchBox.Text);
        TotalCustomersText.Text = dashboard.TotalCustomers.ToString();
        ActiveLicensesText.Text = dashboard.ActiveLicenses.ToString();
        ExpiringText.Text = dashboard.ExpiringSoon.ToString();
        SuspendedText.Text = dashboard.SuspendedLicenses.ToString();
        ServerTimeText.Text = $"Server UTC {dashboard.ServerUtc:yyyy-MM-dd HH:mm}";
        _customers.Clear();
        foreach (var customer in customers)
            _customers.Add(customer);
        CustomersGrid.SelectedItem = _customers.FirstOrDefault(item => item.CustomerId == selectedId);
        if (CustomersGrid.SelectedItem is null && _customers.Count > 0)
            CustomersGrid.SelectedIndex = 0;
        UpdateDetails();
    }

    private async void NewCustomerButton_Click(object sender, RoutedEventArgs e)
    {
        var dialog = new NewCustomerWindow { Owner = this };
        if (dialog.ShowDialog() != true || dialog.Request is null || _api is null)
            return;
        await RunActionAsync(async () =>
        {
            await RefreshAndValidatePackageSourceAsync();
            var created = await _api.CreateCustomerAsync(dialog.Request);
            CustomerPackageResult package;
            try
            {
                package = _packageBuilder.Build(
                    _settings,
                    created.Customer,
                    created.ActivationKey,
                    created.PackageBindingToken);
            }
            catch (Exception packageError)
            {
                try
                {
                    await _api.DeleteCustomerAsync(created.Customer.CustomerId);
                }
                catch (Exception rollbackError)
                {
                    throw new InvalidOperationException(
                        $"Package generation failed and customer {created.Customer.CustomerCode} could not be rolled back. " +
                        "Delete that customer manually before retrying.",
                        new AggregateException(packageError, rollbackError));
                }
                throw;
            }
            await LoadDataAsync();
            CustomerPackageBuilder.OpenInExplorer(package.ZipPath);
            MessageBox.Show(this, $"Customer and package created successfully.\n\n{package.ZipPath}", "KMTGuard License Center", MessageBoxButton.OK, MessageBoxImage.Information);
        }, "Creating customer and release package...");
    }

    private async void GenerateButton_Click(object sender, RoutedEventArgs e)
    {
        if (SelectedCustomer is not { } customer || _api is null)
            return;
        await RunActionAsync(async () =>
        {
            await RefreshAndValidatePackageSourceAsync();
            var credential = await _api.CreateCredentialAsync(customer.LicenseId);
            var package = _packageBuilder.Build(
                _settings,
                customer,
                credential.ActivationKey,
                credential.PackageBindingToken);
            CustomerPackageBuilder.OpenInExplorer(package.ZipPath);
            MessageBox.Show(this, $"A new package was generated without disabling previous installations.\n\n{package.ZipPath}", "Package Ready", MessageBoxButton.OK, MessageBoxImage.Information);
        }, "Generating customer package...");
    }

    private async void ReissueLicenseButton_Click(object sender, RoutedEventArgs e)
    {
        if (SelectedCustomer is not { } customer || _api is null)
            return;

        var sourceDialog = new OpenFileDialog
        {
            Title = "Select one personalized DLL from the customer's existing files",
            Filter =
                "KMTGuard personalized DLL|KMTGuardKit.dll;KMTGuard_GameServer.dll;KMTGuard_ShardManager.dll|" +
                "DLL files (*.dll)|*.dll",
            CheckFileExists = true,
            Multiselect = false
        };
        if (sourceDialog.ShowDialog(this) != true)
            return;

        await RunActionAsync(async () =>
        {
            var binding = _personalizer.ReadAndVerify(sourceDialog.FileName);
            ValidateExistingPackage(customer, binding);

            var credential = await _api.ReissuePackageCredentialAsync(
                customer.LicenseId,
                binding.PackageId);
            var saveDialog = new SaveFileDialog
            {
                Title = "Save the replacement KMTGuard license file",
                FileName = LicenseFileDocument.FileName,
                DefaultExt = ".txt",
                AddExtension = true,
                OverwritePrompt = true,
                InitialDirectory = Path.GetDirectoryName(sourceDialog.FileName)
            };
            if (saveDialog.ShowDialog(this) != true)
                return;

            new LicenseFileDocument
            {
                ServerUrl = _settings.PublicApiUrl.Trim().TrimEnd('/'),
                CertificateSha256 = _settings.CertificateSha256,
                ActivationKey = credential.ActivationKey,
                BindingMode = customer.BindingMode,
                LeaseToken = string.Empty
            }.Save(saveDialog.FileName);

            MessageBox.Show(
                this,
                "The replacement license file is ready for the customer's existing package.\n\n" +
                $"{saveDialog.FileName}\n\n" +
                "Place it beside KMTGuard.exe and start the Filter once as Administrator. " +
                "The activated shared license will then be available to GameServer and ShardManager; " +
                "their DLL files do not need to be replaced.",
                "Replacement License Ready",
                MessageBoxButton.OK,
                MessageBoxImage.Information);
        }, "Reissuing a license file for the existing customer package...");
    }

    private static void ValidateExistingPackage(
        OwnerCustomerSummary customer,
        PackageBindingClaims binding)
    {
        if (!binding.LicenseId.Equals(customer.LicenseId, StringComparison.Ordinal) ||
            !binding.CustomerId.Equals(customer.CustomerId, StringComparison.Ordinal) ||
            !binding.CustomerCode.Equals(customer.CustomerCode, StringComparison.Ordinal))
        {
            throw new InvalidDataException(
                "The selected DLL does not belong to the selected customer.");
        }
        if (binding.Features != customer.Features ||
            binding.BindingMode != customer.BindingMode ||
            (binding.BindingMode == LicenseBindingMode.Ip &&
             !ServerIpSet.SetEquals(binding.ServerIp, customer.ServerIp)))
        {
            throw new InvalidDataException(
                "The selected DLL belongs to an older license configuration. " +
                "Generate a full customer package because its licensed features, binding mode, or server IP slots have changed.");
        }
    }

    private async void RenewButton_Click(object sender, RoutedEventArgs e)
    {
        if (SelectedCustomer is not { } customer || _api is null)
            return;
        var months = int.Parse(((ComboBoxItem)RenewMonthsCombo.SelectedItem).Tag.ToString()!);
        await RunActionAsync(async () =>
        {
            var response = await _api.RenewAsync(customer.LicenseId, months);
            await LoadDataAsync();
            SetServerState(true, response.Message);
        }, "Renewing subscription...");
    }

    private async void SuspendButton_Click(object sender, RoutedEventArgs e)
    {
        if (SelectedCustomer is not { } customer || _api is null)
            return;
        if (!customer.Status.Equals("Active", StringComparison.OrdinalIgnoreCase))
            return;
        if (MessageBox.Show(
                this,
                $"Stop {customer.CustomerName}'s subscription now?\n\nTheir Filter services will shut down at the next license check.",
                "Stop Subscription",
                MessageBoxButton.YesNo,
                MessageBoxImage.Warning) != MessageBoxResult.Yes)
            return;
        await RunActionAsync(async () =>
        {
            var response = await _api.SetStatusAsync(customer.LicenseId, "Suspended");
            await LoadDataAsync();
            SetServerState(true, response.Message);
        }, "Stopping customer subscription...");
    }

    private async void ActivateButton_Click(object sender, RoutedEventArgs e)
    {
        if (SelectedCustomer is not { } customer || _api is null ||
            !customer.Status.Equals("Suspended", StringComparison.OrdinalIgnoreCase))
            return;
        await RunActionAsync(async () =>
        {
            var response = await _api.SetStatusAsync(customer.LicenseId, "Active");
            await LoadDataAsync();
            SetServerState(true, response.Message);
        }, "Activating customer subscription...");
    }

    private async void ResetMachineButton_Click(object sender, RoutedEventArgs e)
    {
        if (SelectedCustomer is not { } customer || _api is null)
            return;
        if (MessageBox.Show(
                this,
                "Machine binding is no longer used by KMTGuard licensing.",
                "Reset Machine Binding",
                MessageBoxButton.YesNo,
                MessageBoxImage.Warning) != MessageBoxResult.Yes)
            return;
        await RunActionAsync(async () =>
        {
            var response = await _api.ResetMachineAsync(customer.LicenseId);
            await LoadDataAsync();
            SetServerState(true, response.Message);
        }, "Resetting machine binding...");
    }

    private async void ChangeServerIpButton_Click(object sender, RoutedEventArgs e)
    {
        if (SelectedCustomer is not { } customer || _api is null)
            return;

        var dialog = new ChangeServerIpWindow(customer.CustomerName, customer.CustomerCode, customer.ServerIp)
        {
            Owner = this
        };
        if (dialog.ShowDialog() != true || string.IsNullOrWhiteSpace(dialog.ServerIp))
            return;

        await RunActionAsync(async () =>
        {
            _packageBuilder.ValidateBuildSource(_settings);
            var response = await _api.ChangeServerIpAsync(customer.LicenseId, dialog.ServerIp);
            await LoadDataAsync();
            var updated = _customers.FirstOrDefault(item => item.CustomerId == customer.CustomerId)
                          ?? throw new InvalidOperationException("The updated customer record was not returned by License Server.");
            var credential = await _api.CreateCredentialAsync(updated.LicenseId);
            var package = _packageBuilder.Build(
                _settings,
                updated,
                credential.ActivationKey,
                credential.PackageBindingToken);
            CustomerPackageBuilder.OpenInExplorer(package.ZipPath);
            SetServerState(true, $"{response.Message} A new personalized package is ready.");
        }, "Changing licensed server IP...");
    }

    private async void RevokeButton_Click(object sender, RoutedEventArgs e)
    {
        if (SelectedCustomer is not { } customer || _api is null)
            return;
        if (MessageBox.Show(this, $"Permanently revoke {customer.CustomerName}'s license?", "Revoke License", MessageBoxButton.YesNo, MessageBoxImage.Warning) != MessageBoxResult.Yes)
            return;
        await RunActionAsync(async () =>
        {
            var response = await _api.SetStatusAsync(customer.LicenseId, "Revoked");
            await LoadDataAsync();
            SetServerState(true, response.Message);
        }, "Revoking license...");
    }

    private async void DeleteCustomerButton_Click(object sender, RoutedEventArgs e)
    {
        if (SelectedCustomer is not { } customer || _api is null)
            return;

        await RunActionAsync(async () =>
        {
            var response = await _api.DeleteCustomerAsync(customer.CustomerId);
            await LoadDataAsync();
            SetServerState(true, response.Message);
        }, "Deleting customer and subscription...");
    }

    private async void RefreshButton_Click(object sender, RoutedEventArgs e) => await ReconnectAndLoadAsync();

    private void SettingsButton_Click(object sender, RoutedEventArgs e)
    {
        var dialog = new SettingsWindow(_settings.Clone()) { Owner = this };
        if (dialog.ShowDialog() != true || dialog.Settings is null)
            return;
        _settings = dialog.Settings;
        _settingsService.Save(_settings);
        _ = ReconnectAndLoadAsync();
    }

    private void UpdatesButton_Click(object sender, RoutedEventArgs e)
    {
        var window = new UpdatesWindow(_settings) { Owner = this };
        window.ShowDialog();
    }

    private async void SearchButton_Click(object sender, RoutedEventArgs e) => await RunActionAsync(LoadDataAsync, "Searching...");

    private async void SearchBox_KeyDown(object sender, KeyEventArgs e)
    {
        if (e.Key == Key.Enter)
            await RunActionAsync(LoadDataAsync, "Searching...");
    }

    private void CustomersGrid_SelectionChanged(object sender, SelectionChangedEventArgs e) => UpdateDetails();

    private OwnerCustomerSummary? SelectedCustomer => CustomersGrid.SelectedItem as OwnerCustomerSummary;

    private async Task RefreshAndValidatePackageSourceAsync()
    {
        if (_productionRefresher.TryUseCurrentRelease(_settings, out var version))
        {
            StatusText.Text = $"Using the current licensed customer release v{version}...";
            _packageBuilder.ValidateBuildSource(_settings);
            return;
        }

        StatusText.Text = "Building the latest licensed customer release...";
        await _productionRefresher.RefreshAsync(_settings);
        StatusText.Text = "Validating the latest customer release...";
        _packageBuilder.ValidateBuildSource(_settings);
    }

    private void UpdateDetails()
    {
        var customer = SelectedCustomer;
        var enabled = customer is not null && !_busy;
        var revoked = enabled && customer!.Status.Equals("Revoked", StringComparison.OrdinalIgnoreCase);
        var active = enabled && customer!.Status.Equals("Active", StringComparison.OrdinalIgnoreCase);
        var suspended = enabled && customer!.Status.Equals("Suspended", StringComparison.OrdinalIgnoreCase);
        GenerateButton.IsEnabled = enabled && !revoked;
        ReissueLicenseButton.IsEnabled = enabled && !revoked;
        RenewButton.IsEnabled = enabled && !revoked;
        StopSelectedButton.IsEnabled = active;
        SuspendButton.IsEnabled = active;
        ActivateButton.IsEnabled = suspended;
        ActivateSelectedButton.IsEnabled = suspended;
        var usesIp = enabled && customer!.BindingMode == LicenseBindingMode.Ip;
        ChangeIpSelectedButton.IsEnabled = usesIp && !revoked;
        ChangeServerIpButton.IsEnabled = usesIp && !revoked;
        ResetMachineButton.IsEnabled = false;
        ResetMachineButton.Visibility = Visibility.Collapsed;
        RevokeButton.IsEnabled = enabled && !revoked;
        DeleteCustomerButton.IsEnabled = enabled;
        if (customer is null)
        {
            DetailNameText.Text = "Select a customer";
            DetailCodeText.Text = "-";
            DetailStatusText.Text = DetailExpiresText.Text = DetailFeaturesText.Text = DetailServerText.Text = DetailMaximumPlayersText.Text = "-";
            DetailMachineText.Text = "Not activated";
            DetailNotesText.Text = string.Empty;
            DeliveryBindingText.Text = "The package carries a signed binding mode and unique watermark.";
            return;
        }

        DetailNameText.Text = customer.CustomerName;
        DetailCodeText.Text = customer.CustomerCode;
        DetailStatusText.Text = customer.Status;
        DetailExpiresText.Text = customer.ExpiresUtc.ToLocalTime().ToString("yyyy-MM-dd");
        DetailMaximumPlayersText.Text = customer.MaximumPlayers.ToString("N0");
        DetailFeaturesText.Text = customer.Features.ToClaimValue().Replace(',', ' ');
        DetailMachineText.Text = "Not used";
        var binding = customer.BindingMode == LicenseBindingMode.PlayerLimit
            ? "Player limit only"
            : $"{customer.ServerIp.Replace(",", Environment.NewLine)}\n{customer.MaximumInstances} licensed slot(s)";
        DetailServerText.Text = $"{binding}\n{(string.IsNullOrWhiteSpace(customer.LastVersion) ? "No version yet" : customer.LastVersion)}";
        DeliveryBindingText.Text = customer.BindingMode == LicenseBindingMode.PlayerLimit
            ? "This package checks no IP or HWID and enforces only subscription status and maximum players."
            : "Each listed public IP is an independent server slot. The total player limit is shared across all active slots.";
        DetailNotesText.Text = customer.Notes;
    }

    private async Task RunActionAsync(Func<Task> action, string message)
    {
        if (_busy)
            return;
        SetBusy(true, message);
        try
        {
            await action();
            SetServerState(true, "Operation completed.");
        }
        catch (Exception ex)
        {
            SetServerState(false, ex.Message);
            MessageBox.Show(this, ex.Message, "KMTGuard License Center", MessageBoxButton.OK, MessageBoxImage.Error);
        }
        finally { SetBusy(false); }
    }

    private void SetBusy(bool busy, string? message = null)
    {
        _busy = busy;
        BusyOverlay.Visibility = busy ? Visibility.Visible : Visibility.Collapsed;
        if (!string.IsNullOrWhiteSpace(message))
            StatusText.Text = message;
        UpdateDetails();
    }

    private void SetServerState(bool connected, string message)
    {
        ServerIndicator.Fill = (Brush)FindResource(connected ? "AccentBrush" : "DangerBrush");
        StatusText.Text = message;
    }

    protected override void OnClosed(EventArgs e)
    {
        _api?.Dispose();
        base.OnClosed(e);
    }
}
