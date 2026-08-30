using System.Windows;
using System.Windows.Input;

namespace KMTGuard.LicenseAdmin;

public partial class ChangeServerIpWindow : Window
{
    public string ServerIp { get; private set; } = string.Empty;

    public ChangeServerIpWindow(string customerName, string customerCode, string currentServerIp)
    {
        InitializeComponent();
        CustomerText.Text = $"{customerName}  |  {customerCode}";
        ServerIpBox.Text = currentServerIp;
        Loaded += (_, _) =>
        {
            ServerIpBox.Focus();
            ServerIpBox.SelectAll();
        };
    }

    private void SaveButton_Click(object sender, RoutedEventArgs e) => Save();

    private void ServerIpBox_KeyDown(object sender, KeyEventArgs e)
    {
        if (e.Key == Key.Enter)
            Save();
    }

    private void Save()
    {
        try
        {
            ServerIp = KMTGuard.Licensing.ServerIpSet.Normalize(ServerIpBox.Text);
        }
        catch (InvalidOperationException ex)
        {
            ErrorText.Text = ex.Message;
            ServerIpBox.Focus();
            ServerIpBox.SelectAll();
            return;
        }
        DialogResult = true;
    }
}
