using System.Security.Cryptography;
using KMTGuard.Licensing;
using Microsoft.Extensions.Options;

namespace KMTGuard.LicenseServer;

public sealed class LicenseSigner : IDisposable
{
    private readonly RSA _privateKey;
    private readonly object _sync = new();

    public LicenseSigner(IOptions<LicenseServerOptions> options)
    {
        var path = LicenseServerOptions.ExpandPath(options.Value.PrivateKeyPath);
        if (!File.Exists(path))
            throw new FileNotFoundException(
                "The KMTGuard signing key is missing. Run KMTGuard.LicenseKeyTool once on the owner VPS.", path);

        _privateKey = RSA.Create();
        _privateKey.ImportPkcs8PrivateKey(File.ReadAllBytes(path), out _);
        if (!LicenseTokenCodec.MatchesTrustedPublicKey(_privateKey))
            throw new CryptographicException("The server private key does not match the public key embedded in KMTGuard builds.");
    }

    public string Sign(LicenseClaims claims)
    {
        lock (_sync)
            return LicenseTokenCodec.Sign(claims, _privateKey);
    }

    public string Sign(PackageBindingClaims claims)
    {
        lock (_sync)
            return PackageBindingTokenCodec.Sign(claims, _privateKey);
    }

    public void Dispose() => _privateKey.Dispose();
}
