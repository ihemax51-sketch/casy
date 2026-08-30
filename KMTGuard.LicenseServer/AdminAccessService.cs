using System.Net;
using System.Security.Cryptography;
using System.Text;
using Microsoft.Extensions.Options;

namespace KMTGuard.LicenseServer;

public sealed class AdminAccessService
{
    private readonly byte[] _expectedToken;
    private readonly int _adminPort;

    public AdminAccessService(IOptions<LicenseServerOptions> options)
    {
        _adminPort = options.Value.AdminPort;
        var path = LicenseServerOptions.ExpandPath(options.Value.AdminTokenPath);
        if (!File.Exists(path))
            throw new FileNotFoundException("The owner API token is missing. Run KMTGuard.LicenseKeyTool once.", path);
        _expectedToken = Encoding.UTF8.GetBytes(File.ReadAllText(path).Trim());
    }

    public bool IsAuthorized(HttpContext context)
    {
        if (context.Connection.LocalPort != _adminPort)
            return false;
        var address = context.Connection.RemoteIpAddress;
        if (address is null || !IPAddress.IsLoopback(address))
            return false;
        if (!context.Request.Headers.TryGetValue("X-KMT-Admin-Token", out var supplied))
            return false;

        var suppliedBytes = Encoding.UTF8.GetBytes(supplied.ToString().Trim());
        return suppliedBytes.Length == _expectedToken.Length &&
               CryptographicOperations.FixedTimeEquals(suppliedBytes, _expectedToken);
    }
}
