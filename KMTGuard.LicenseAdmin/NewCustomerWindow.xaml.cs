using System.Windows;
using System.Windows.Controls;
using KMTGuard.Licensing;

namespace KMTGuard.LicenseAdmin;

public partial class NewCustomerWindow : Window
{
    private readonly List<TextBox> _serverIpBoxes = new();

    public CreateCustomerRequest? Request { get; private set; }

    public NewCustomerWindow()
    {
        InitializeComponent();
        RebuildServerIpFields();
        CustomerNameBox.Focus();
    }

    private void CreateButton_Click(object sender, RoutedEventArgs e)
    {
        var name = CustomerNameBox.Text.Trim();
        if (name.Length < 2)
        {
            ErrorText.Text = "Enter a valid customer name.";
            return;
        }

        var bindingMode = ReadBindingMode();
        var maximumInstances = ReadTag(InstancesCombo);
        var ip = string.Empty;
        try
        {
            if (bindingMode == LicenseBindingMode.Ip)
            {
                if (_serverIpBoxes.Count != maximumInstances ||
                    _serverIpBoxes.Any(box => string.IsNullOrWhiteSpace(box.Text)))
                {
                    ErrorText.Text = $"Enter a public IPv4 address for every GameServer ({maximumInstances} required).";
                    return;
                }

                ip = ServerIpSet.Normalize(
                    string.Join(',', _serverIpBoxes.Select(box => box.Text)),
                    maximum: maximumInstances);
                if (ServerIpSet.Parse(ip).Count != maximumInstances)
                {
                    ErrorText.Text = "Every GameServer must use a different public IPv4 address.";
                    return;
                }
            }
        }
        catch (InvalidOperationException ex)
        {
            ErrorText.Text = ex.Message;
            return;
        }

        if (!int.TryParse(MaximumPlayersBox.Text.Trim(), out var maximumPlayers) || maximumPlayers is < 1 or > 10000)
        {
            ErrorText.Text = "Maximum online players must be a whole number between 1 and 10,000.";
            return;
        }

        var features = LicenseFeature.None;
        if (FilterCheck.IsChecked == true) features |= LicenseFeature.Filter;
        if (GameServerCheck.IsChecked == true) features |= LicenseFeature.GameServer;
        if (ShardManagerCheck.IsChecked == true) features |= LicenseFeature.ShardManager;
        if (ClientDllCheck.IsChecked == true) features |= LicenseFeature.ClientDll;
        if (features == LicenseFeature.None)
        {
            ErrorText.Text = "Select at least one licensed component.";
            return;
        }

        Request = new CreateCustomerRequest(
            name,
            ReadTag(MonthsCombo),
            features,
            maximumInstances,
            maximumPlayers,
            ReadTag(OfflineCombo),
            ip,
            NotesBox.Text.Trim(),
            bindingMode);
        DialogResult = true;
    }

    private void BindingModeCombo_SelectionChanged(object sender, SelectionChangedEventArgs e)
        => UpdateServerIpFieldState();

    private void UpdateServerIpFieldState()
    {
        if (ServerIpFieldsPanel is null)
            return;
        var usesIp = ReadBindingMode() == LicenseBindingMode.Ip;
        ServerIpFieldsPanel.IsEnabled = usesIp;
        ServerIpSection.Visibility = usesIp ? Visibility.Visible : Visibility.Collapsed;
        ServerIpLabel.Opacity = usesIp ? 1 : 0.55;
        BindingModeHelp.Text = usesIp
            ? "A separate required IP field is shown for every selected GameServer."
            : "Player-limit mode does not check IP or HWID; it enforces the subscription and maximum players only.";
    }

    private void InstancesCombo_SelectionChanged(object sender, SelectionChangedEventArgs e)
    {
        if (ServerIpFieldsPanel is not null)
            RebuildServerIpFields();
    }

    private void RebuildServerIpFields()
    {
        var previousValues = _serverIpBoxes.Select(box => box.Text).ToArray();
        _serverIpBoxes.Clear();
        ServerIpFieldsPanel.Children.Clear();

        var count = ReadTag(InstancesCombo);
        for (var index = 0; index < count; index++)
        {
            var label = new TextBlock
            {
                Text = $"GAMESERVER {index + 1} PUBLIC IPV4",
                Margin = new Thickness(0, index == 0 ? 0 : 9, 0, 4),
                Style = (Style)FindResource("FieldLabel")
            };
            var input = new TextBox
            {
                MaxLength = 45,
                Text = index < previousValues.Length ? previousValues[index] : string.Empty,
                ToolTip = $"Public IPv4 address for GameServer {index + 1}"
            };
            _serverIpBoxes.Add(input);
            ServerIpFieldsPanel.Children.Add(label);
            ServerIpFieldsPanel.Children.Add(input);
        }

        if (BindingModeCombo?.SelectedItem is not null)
            UpdateServerIpFieldState();
    }

    private LicenseBindingMode ReadBindingMode() =>
        ((ComboBoxItem)BindingModeCombo.SelectedItem).Tag?.ToString() == "LIMIT"
            ? LicenseBindingMode.PlayerLimit
            : LicenseBindingMode.Ip;

    private static int ReadTag(ComboBox box) => int.Parse(((ComboBoxItem)box.SelectedItem).Tag.ToString()!);
}
