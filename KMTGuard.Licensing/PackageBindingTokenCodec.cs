using System.Globalization;
using System.Security.Cryptography;
using System.Text;

namespace KMTGuard.Licensing;

public static class PackageBindingTokenCodec
{
    public const string TokenPrefix = "KMTP1";

    public static string Sign(PackageBindingClaims claims, RSA privateKey)
    {
        var payloadBytes = Encoding.UTF8.GetBytes(SerializeClaims(claims));
        var signature = privateKey.SignData(payloadBytes, HashAlgorithmName.SHA256, RSASignaturePadding.Pkcs1);
        return $"{TokenPrefix}.{Base64Url.Encode(payloadBytes)}.{Base64Url.Encode(signature)}";
    }

    public static bool TryVerify(string? token, out PackageBindingClaims? claims, out string error)
    {
        claims = null;
        error = string.Empty;
        try
        {
            if (string.IsNullOrWhiteSpace(token))
            {
                error = "Package binding is missing.";
                return false;
            }

            var parts = token.Trim().Split('.');
            if (parts.Length != 3 || !parts[0].Equals(TokenPrefix, StringComparison.Ordinal))
            {
                error = "Package binding format is invalid.";
                return false;
            }

            var payloadBytes = Base64Url.Decode(parts[1]);
            var signature = Base64Url.Decode(parts[2]);
            using var rsa = RSA.Create();
            rsa.ImportParameters(new RSAParameters
            {
                Modulus = Convert.FromBase64String(TrustedLicenseKey.ModulusBase64),
                Exponent = Convert.FromBase64String(TrustedLicenseKey.ExponentBase64)
            });
            if (!rsa.VerifyData(payloadBytes, signature, HashAlgorithmName.SHA256, RSASignaturePadding.Pkcs1))
            {
                error = "Package binding signature is invalid.";
                return false;
            }

            claims = DeserializeClaims(Encoding.UTF8.GetString(payloadBytes));
            if (!claims.KeyId.Equals(TrustedLicenseKey.KeyId, StringComparison.Ordinal))
            {
                error = "Package binding signing key is not trusted.";
                claims = null;
                return false;
            }

            return true;
        }
        catch (Exception ex) when (ex is FormatException or CryptographicException or InvalidDataException or OverflowException)
        {
            error = $"Package binding could not be read: {ex.Message}";
            claims = null;
            return false;
        }
    }

    private static string SerializeClaims(PackageBindingClaims claims) => string.Join('&', new[]
    {
        Pair("v", "1"),
        Pair("kid", claims.KeyId),
        Pair("lid", claims.LicenseId),
        Pair("cid", claims.CustomerId),
        Pair("ccode", claims.CustomerCode),
        Pair("pkg", claims.PackageId),
        Pair("ip", Base64Url.EncodeText(claims.ServerIp ?? string.Empty)),
        Pair("bind", claims.BindingMode.ToClaimValue()),
        Pair("features", claims.Features.ToClaimValue()),
        Pair("issued", claims.IssuedUtc.ToUnixTimeSeconds().ToString(CultureInfo.InvariantCulture)),
        Pair("mark", claims.Watermark)
    });

    private static PackageBindingClaims DeserializeClaims(string payload)
    {
        var values = payload.Split('&', StringSplitOptions.RemoveEmptyEntries)
            .Select(value => value.Split('=', 2))
            .Where(value => value.Length == 2)
            .ToDictionary(value => value[0], value => value[1], StringComparer.Ordinal);

        if (Required(values, "v") != "1")
            throw new InvalidDataException("Unsupported package binding version.");

        return new PackageBindingClaims
        {
            KeyId = Required(values, "kid"),
            LicenseId = Required(values, "lid"),
            CustomerId = Required(values, "cid"),
            CustomerCode = Required(values, "ccode"),
            PackageId = Required(values, "pkg"),
            ServerIp = Base64Url.DecodeText(Present(values, "ip")),
            BindingMode = LicenseBindingModeExtensions.Parse(Optional(values, "bind")),
            Features = LicenseFeatureExtensions.ParseClaimValue(Required(values, "features")),
            IssuedUtc = DateTimeOffset.FromUnixTimeSeconds(long.Parse(Required(values, "issued"), CultureInfo.InvariantCulture)),
            Watermark = Required(values, "mark")
        };
    }

    private static string Pair(string key, string value) => $"{key}={value}";

    private static string Required(IReadOnlyDictionary<string, string> values, string key) =>
        values.TryGetValue(key, out var value) && !string.IsNullOrWhiteSpace(value)
            ? value
            : throw new InvalidDataException($"Required package claim '{key}' is missing.");

    private static string Present(IReadOnlyDictionary<string, string> values, string key) =>
        values.TryGetValue(key, out var value)
            ? value
            : throw new InvalidDataException($"Required package claim '{key}' is missing.");

    private static string? Optional(IReadOnlyDictionary<string, string> values, string key) =>
        values.TryGetValue(key, out var value) ? value : null;
}
