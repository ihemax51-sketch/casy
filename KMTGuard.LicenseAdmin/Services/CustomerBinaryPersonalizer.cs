using System.Net;
using KMTGuard.Licensing;

namespace KMTGuard.LicenseAdmin.Services;

public sealed class CustomerBinaryPersonalizer
{
    public PackageBindingClaims Personalize(
        string packageRoot,
        OwnerCustomerSummary customer,
        string bindingToken)
    {
        if (!PackageBindingTokenCodec.TryVerify(bindingToken, out var claims, out var error) || claims is null)
            throw new InvalidDataException(error);
        if (!claims.CustomerId.Equals(customer.CustomerId, StringComparison.Ordinal) ||
            !claims.LicenseId.Equals(customer.LicenseId, StringComparison.Ordinal) ||
            !claims.CustomerCode.Equals(customer.CustomerCode, StringComparison.Ordinal))
        {
            throw new InvalidDataException("The signed package binding does not belong to the selected customer.");
        }
        if (claims.Features != customer.Features)
            throw new InvalidDataException("The signed package features do not match the selected customer.");
        if (claims.BindingMode != customer.BindingMode)
            throw new InvalidDataException("The signed package binding mode does not match the selected customer.");
        if (customer.BindingMode == LicenseBindingMode.Ip &&
            !AddressesEqual(claims.ServerIp, customer.ServerIp))
            throw new InvalidDataException("The signed package IP does not match the selected customer.");
        if (customer.BindingMode == LicenseBindingMode.PlayerLimit &&
            !string.IsNullOrWhiteSpace(claims.ServerIp))
            throw new InvalidDataException("A player-limit-only package must not contain a licensed server IP.");

        foreach (var relativePath in new[]
                 {
                     @"DLL\KMTGuardKit.dll",
                     @"ServerAddons\GameServer\KMTGuard_GameServer.dll",
                     @"ServerAddons\ShardManager\KMTGuard_ShardManager.dll"
                 })
        {
            PackageBindingResource.Write(Path.Combine(packageRoot, relativePath), bindingToken);
        }

        return claims;
    }

    public PackageBindingClaims ReadAndVerify(string binaryPath)
    {
        var token = PackageBindingResource.Read(binaryPath);
        if (!PackageBindingTokenCodec.TryVerify(token, out var claims, out var error) || claims is null)
            throw new InvalidDataException(error);

        return claims;
    }

    private static bool AddressesEqual(string left, string right) =>
        ServerIpSet.SetEquals(left, right);
}
