namespace KMTGuard.Licensing;

public sealed record OwnerDashboardResponse(
    int TotalCustomers,
    int ActiveLicenses,
    int ExpiringSoon,
    int SuspendedLicenses,
    DateTimeOffset ServerUtc);

public sealed record OwnerCustomerSummary(
    string CustomerId,
    string CustomerCode,
    string CustomerName,
    string LicenseId,
    string Status,
    DateTimeOffset StartsUtc,
    DateTimeOffset ExpiresUtc,
    LicenseFeature Features,
    int MaximumInstances,
    int MaximumPlayers,
    int OfflineHours,
    string MachineDisplay,
    string ServerIp,
    LicenseBindingMode BindingMode,
    DateTimeOffset? LastSeenUtc,
    string LastVersion,
    string Notes);

public sealed record CreateCustomerRequest(
    string CustomerName,
    int SubscriptionMonths,
    LicenseFeature Features,
    int MaximumInstances,
    int MaximumPlayers,
    int OfflineHours,
    string ServerIp,
    string Notes,
    LicenseBindingMode BindingMode = LicenseBindingMode.Ip);

public sealed record CreateCustomerResponse(
    OwnerCustomerSummary Customer,
    string ActivationKey,
    string CredentialId,
    string PackageBindingToken);

public sealed record RenewLicenseRequest(int Months);

public sealed record ChangeLicenseStatusRequest(string Status);

public sealed record ChangeServerIpRequest(string ServerIp);

public sealed record NewCredentialResponse(
    string LicenseId,
    string CredentialId,
    string ActivationKey,
    string PackageBindingToken);

public sealed record ReissuePackageCredentialRequest(string PackageId);

public sealed record ReissuePackageCredentialResponse(
    string LicenseId,
    string CredentialId,
    string PackageId,
    string ActivationKey);

public sealed record OwnerOperationResponse(bool Success, string Message);
