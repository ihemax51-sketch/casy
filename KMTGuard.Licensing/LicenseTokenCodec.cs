using System.Globalization;
using System.Security.Cryptography;
using System.Text;

namespace KMTGuard.Licensing;

public static class LicenseTokenCodec
{
    public const string TokenPrefix = "KMT1";
    public static string TrustedKeyId => TrustedLicenseKey.KeyId;

    public static bool MatchesTrustedPublicKey(RSA key)
    {
        if (string.IsNullOrWhiteSpace(TrustedLicenseKey.ModulusBase64))
            return false;
        var parameters = key.ExportParameters(false);
        return parameters.Modulus is not null && parameters.Exponent is not null &&
               CryptographicOperations.FixedTimeEquals(parameters.Modulus, Convert.FromBase64String(TrustedLicenseKey.ModulusBase64)) &&
               CryptographicOperations.FixedTimeEquals(parameters.Exponent, Convert.FromBase64String(TrustedLicenseKey.ExponentBase64));
    }

    public static string Sign(LicenseClaims claims, RSA privateKey)
    {
        var payload = SerializeClaims(claims);
        var payloadBytes = Encoding.UTF8.GetBytes(payload);
        var signature = privateKey.SignData(payloadBytes, HashAlgorithmName.SHA256, RSASignaturePadding.Pkcs1);
        return $"{TokenPrefix}.{Base64Url.Encode(payloadBytes)}.{Base64Url.Encode(signature)}";
    }

    public static bool TryVerify(string? token, out LicenseClaims? claims, out string error)
    {
        claims = null;
        error = string.Empty;
        try
        {
            if (string.IsNullOrWhiteSpace(token))
            {
                error = "License token is missing.";
                return false;
            }

            var parts = token.Trim().Split('.');
            if (parts.Length != 3 || !parts[0].Equals(TokenPrefix, StringComparison.Ordinal))
            {
                error = "License token format is invalid.";
                return false;
            }

            if (string.IsNullOrWhiteSpace(TrustedLicenseKey.ModulusBase64))
            {
                error = "The trusted licensing key has not been provisioned.";
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
                error = "License signature is invalid.";
                return false;
            }

            claims = DeserializeClaims(Encoding.UTF8.GetString(payloadBytes));
            if (!claims.KeyId.Equals(TrustedLicenseKey.KeyId, StringComparison.Ordinal))
            {
                error = "License signing key is not trusted.";
                claims = null;
                return false;
            }

            return true;
        }
        catch (Exception ex) when (ex is FormatException or CryptographicException or InvalidDataException or OverflowException)
        {
            error = $"License token could not be read: {ex.Message}";
            claims = null;
            return false;
        }
    }

    private static string SerializeClaims(LicenseClaims claims)
    {
        var values = new[]
        {
            Pair("v", "2"),
            Pair("kid", claims.KeyId),
            Pair("lid", claims.LicenseId),
            Pair("cid", claims.CustomerId),
            Pair("cname", Base64Url.EncodeText(claims.CustomerName)),
            Pair("machine", claims.MachineHash),
            Pair("issued", claims.IssuedUtc.ToUnixTimeSeconds().ToString(CultureInfo.InvariantCulture)),
            Pair("nbf", claims.NotBeforeUtc.ToUnixTimeSeconds().ToString(CultureInfo.InvariantCulture)),
            Pair("subexp", claims.SubscriptionExpiresUtc.ToUnixTimeSeconds().ToString(CultureInfo.InvariantCulture)),
            Pair("leaseexp", claims.LeaseExpiresUtc.ToUnixTimeSeconds().ToString(CultureInfo.InvariantCulture)),
            Pair("features", claims.Features.ToClaimValue()),
            Pair("max", claims.MaximumInstances.ToString(CultureInfo.InvariantCulture)),
            Pair("maxplayers", claims.MaximumPlayers.ToString(CultureInfo.InvariantCulture)),
            Pair("pkg", claims.PackageId),
            Pair("ip", Base64Url.EncodeText(claims.ServerIp ?? string.Empty)),
            Pair("bind", claims.BindingMode.ToClaimValue())
        };
        return string.Join('&', values);
    }

    private static LicenseClaims DeserializeClaims(string payload)
    {
        var values = payload.Split('&', StringSplitOptions.RemoveEmptyEntries)
            .Select(value => value.Split('=', 2))
            .Where(value => value.Length == 2)
            .ToDictionary(value => value[0], value => value[1], StringComparer.Ordinal);

        if (Required(values, "v") != "2")
            throw new InvalidDataException("Unsupported license version.");

        return new LicenseClaims
        {
            KeyId = Required(values, "kid"),
            LicenseId = Required(values, "lid"),
            CustomerId = Required(values, "cid"),
            CustomerName = Base64Url.DecodeText(Required(values, "cname")),
            MachineHash = Required(values, "machine"),
            IssuedUtc = FromUnix(values, "issued"),
            NotBeforeUtc = FromUnix(values, "nbf"),
            SubscriptionExpiresUtc = FromUnix(values, "subexp"),
            LeaseExpiresUtc = FromUnix(values, "leaseexp"),
            Features = LicenseFeatureExtensions.ParseClaimValue(Required(values, "features")),
            MaximumInstances = int.Parse(Required(values, "max"), CultureInfo.InvariantCulture),
            MaximumPlayers = int.Parse(Required(values, "maxplayers"), CultureInfo.InvariantCulture),
            PackageId = Required(values, "pkg"),
            ServerIp = Base64Url.DecodeText(Present(values, "ip")),
            BindingMode = LicenseBindingModeExtensions.Parse(Optional(values, "bind"))
        };
    }

    private static string Pair(string key, string value) => $"{key}={value}";

    private static string Required(IReadOnlyDictionary<string, string> values, string key) =>
        values.TryGetValue(key, out var value) && !string.IsNullOrWhiteSpace(value)
            ? value
            : throw new InvalidDataException($"Required claim '{key}' is missing.");

    private static string Present(IReadOnlyDictionary<string, string> values, string key) =>
        values.TryGetValue(key, out var value)
            ? value
            : throw new InvalidDataException($"Required claim '{key}' is missing.");

    private static string? Optional(IReadOnlyDictionary<string, string> values, string key) =>
        values.TryGetValue(key, out var value) ? value : null;

    private static DateTimeOffset FromUnix(IReadOnlyDictionary<string, string> values, string key) =>
        DateTimeOffset.FromUnixTimeSeconds(long.Parse(Required(values, key), CultureInfo.InvariantCulture));
}
