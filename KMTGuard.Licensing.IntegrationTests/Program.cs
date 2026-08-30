using System.Net.Http.Json;
using System.Security.Cryptography;
using KMTGuard.Licensing;

var publicServerUrl = Environment.GetEnvironmentVariable("KMTGUARD_TEST_LICENSE_PUBLIC") ?? "http://127.0.0.1:5127";
var adminServerUrl = Environment.GetEnvironmentVariable("KMTGUARD_TEST_LICENSE_ADMIN") ?? "http://127.0.0.1:5128";
var adminTokenPath = Path.Combine(
    Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData),
    "KMTGuardLicensing",
    "Secrets",
    "admin-api-token.txt");
Require(File.Exists(adminTokenPath), "Owner API token was not found.");

var localWrongIp = await ServerIpVerifier.VerifyCurrentMachineAsync("203.0.113.24");
Require(!localWrongIp.IsMatch, "Local IP verification accepted an address that is not assigned to this server.");

using var adminHttp = new HttpClient { BaseAddress = new Uri(adminServerUrl), Timeout = TimeSpan.FromSeconds(10) };
adminHttp.DefaultRequestHeaders.Add("X-KMT-Admin-Token", File.ReadAllText(adminTokenPath).Trim());
adminHttp.DefaultRequestHeaders.Add("X-KMT-Client-IP", "203.0.113.24");
var suffix = DateTimeOffset.UtcNow.ToUnixTimeSeconds().ToString();
var created = await PostAsync<CreateCustomerRequest, CreateCustomerResponse>(
    adminHttp,
    "/api/v1/admin/customers",
    new CreateCustomerRequest(
        $"Integration Test {suffix}",
        1,
        LicenseFeature.Filter | LicenseFeature.GameServer | LicenseFeature.ShardManager | LicenseFeature.ClientDll,
        2,
        20,
        24,
        "203.0.113.24, 203.0.113.25",
        "Automated licensing integration test"));

Require(
    PackageBindingTokenCodec.TryVerify(created.PackageBindingToken, out var initialBinding, out var bindingError) &&
    initialBinding is not null,
    $"Initial package binding is invalid: {bindingError}");
var verifiedInitialBinding = initialBinding ?? throw new InvalidOperationException("Initial package binding was not returned.");
Require(
    verifiedInitialBinding.CustomerId == created.Customer.CustomerId &&
    verifiedInitialBinding.LicenseId == created.Customer.LicenseId &&
    verifiedInitialBinding.PackageId == created.CredentialId &&
    verifiedInitialBinding.ServerIp == "203.0.113.24,203.0.113.25",
    "Initial package binding claims do not match the customer.");

var temporaryRoot = Path.Combine(Path.GetTempPath(), "KMTGuard-LicenseTests", Guid.NewGuid().ToString("N"));
Directory.CreateDirectory(temporaryRoot);
var licensePath = Path.Combine(temporaryRoot, LicenseFileDocument.FileName);
var previousPath = Environment.GetEnvironmentVariable("KMTGUARD_LICENSE_PATH");
var machineLicensePath = LicenseFileDocument.GetMachinePath();
var machineLicenseBefore = File.Exists(machineLicensePath) ? File.ReadAllBytes(machineLicensePath) : null;
Environment.SetEnvironmentVariable("KMTGUARD_LICENSE_PATH", licensePath);
try
{
    new LicenseFileDocument
    {
        ServerUrl = publicServerUrl,
        ActivationKey = created.ActivationKey
    }.Save(licensePath);

    using var licenseHttp = new HttpClient { Timeout = TimeSpan.FromSeconds(10) };
    licenseHttp.DefaultRequestHeaders.Add("X-KMT-Client-IP", "203.0.113.24");
    var client = new LicenseClient(httpClient: licenseHttp);

    using (var wrongOriginClient = new HttpClient { Timeout = TimeSpan.FromSeconds(10) })
    {
        wrongOriginClient.DefaultRequestHeaders.Add("X-KMT-Client-IP", "203.0.113.99");
        using var wrongOriginResponse = await wrongOriginClient.PostAsJsonAsync(
            $"{publicServerUrl}/api/v1/license/refresh",
            new LicenseRefreshRequest(
                created.ActivationKey,
                MachineFingerprint.GetHash(),
                "203.0.113.24",
                "wrong-origin-test",
                "Filter"));
        Require(!wrongOriginResponse.IsSuccessStatusCode,
            "License Server trusted the configured IP instead of the observed caller IP.");
    }

    var activated = await client.EnsureValidAsync(
        LicenseFeature.Filter,
        "203.0.113.24",
        "integration-test",
        true,
        verifyCurrentServerIp: false);
    Require(activated.IsValid && activated.RefreshedOnline, $"Activation failed: {activated.Message}");
    var activatedClaims = activated.Claims ?? throw new InvalidOperationException("Activation did not return signed claims.");
    Require(activatedClaims.CustomerName == created.Customer.CustomerName, "Customer claim does not match.");
    Require(activatedClaims.MaximumPlayers == 20, "Signed maximum-player claim does not match.");
    Require(activatedClaims.MaximumInstances == 2, "Signed server-slot count does not match.");

    using (var updateCheckResponse = await licenseHttp.PostAsJsonAsync(
               $"{publicServerUrl}/api/v1/update/check",
               new UpdateCheckRequest(
                   created.ActivationKey,
                   MachineFingerprint.GetHash(),
                   "203.0.113.24",
                   "99.0.0")))
    {
        var updateCheck = await updateCheckResponse.Content.ReadFromJsonAsync<UpdateCheckResponse>();
        Require(
            updateCheckResponse.IsSuccessStatusCode &&
            updateCheck is { Success: true, UpdateAvailable: false },
            "A valid licensed installation could not access the update channel.");
    }
    UpdateReleaseInfo licensedRelease;
    using (var updateAvailableResponse = await licenseHttp.PostAsJsonAsync(
               $"{publicServerUrl}/api/v1/update/check",
               new UpdateCheckRequest(
                   created.ActivationKey,
                   MachineFingerprint.GetHash(),
                   "203.0.113.24",
                   "1.9.1")))
    {
        var updateAvailable =
            await updateAvailableResponse.Content.ReadFromJsonAsync<UpdateCheckResponse>()
            ?? throw new InvalidDataException("The update check returned an empty response.");
        var returnedRelease = updateAvailable.Release;
        Require(
            updateAvailableResponse.IsSuccessStatusCode &&
            updateAvailable is { Success: true, UpdateAvailable: true } &&
            Version.TryParse(returnedRelease?.Version, out var returnedVersion) &&
            returnedVersion > new Version(1, 9, 1),
            "The latest published release was not returned to an older licensed installation.");
        Require(
            PackageBindingTokenCodec.TryVerify(
                updateAvailable.PackageBindingToken,
                out var updateBinding,
                out var updateBindingError) &&
            updateBinding?.PackageId == created.CredentialId,
            $"The update channel did not preserve the customer's package identity: {updateBindingError}");
        licensedRelease = returnedRelease
                          ?? throw new InvalidDataException("The available update did not include release metadata.");
    }
    using (var updateDownloadResponse = await licenseHttp.PostAsJsonAsync(
               $"{publicServerUrl}/api/v1/update/download/{Uri.EscapeDataString(licensedRelease.Version)}",
               new UpdateDownloadRequest(
                   created.ActivationKey,
                   MachineFingerprint.GetHash(),
                   "203.0.113.24",
                   "1.9.1")))
    {
        Require(updateDownloadResponse.IsSuccessStatusCode, "The licensed update ZIP could not be downloaded.");
        var updateBytes = await updateDownloadResponse.Content.ReadAsByteArrayAsync();
        Require(
            updateBytes.LongLength == licensedRelease.PackageSize &&
            Convert.ToHexString(SHA256.HashData(updateBytes))
                .Equals(licensedRelease.PackageSha256, StringComparison.OrdinalIgnoreCase),
            "The downloaded licensed update ZIP failed its catalog hash check.");
    }

    var saved = LicenseFileDocument.Load(licensePath);
    Require(!string.IsNullOrWhiteSpace(saved.LeaseToken), "Signed lease was not persisted.");
    Require(
        machineLicenseBefore is null
            ? !File.Exists(machineLicensePath)
            : File.Exists(machineLicensePath) && File.ReadAllBytes(machineLicensePath).SequenceEqual(machineLicenseBefore),
        "An isolated license path modified the machine-wide license file.");
    Require(LicenseValidationResult.Validate(saved.LeaseToken, LicenseFeature.GameServer, MachineFingerprint.GetHash()).IsValid,
        "GameServer feature validation failed.");
    Require(LicenseValidationResult.Validate(saved.LeaseToken, LicenseFeature.ShardManager, MachineFingerprint.GetHash()).IsValid,
        "ShardManager feature validation failed.");
    Require(LicenseValidationResult.Validate(
            saved.LeaseToken,
            LicenseFeature.Filter,
            MachineFingerprint.GetHash(),
            expectedServerIp: "203.0.113.24").IsValid,
        "The signed lease was rejected for its licensed server IP.");
    Require(!LicenseValidationResult.Validate(
            saved.LeaseToken,
            LicenseFeature.Filter,
            MachineFingerprint.GetHash(),
            expectedServerIp: "203.0.113.99").IsValid,
        "A signed lease was accepted for a different configured server IP.");

    var tampered = saved.LeaseToken[..^1] + (saved.LeaseToken[^1] == 'A' ? 'B' : 'A');
    Require(!LicenseValidationResult.Validate(tampered, LicenseFeature.Filter, MachineFingerprint.GetHash()).IsValid,
        "Tampered signature was accepted.");
    Require(!LicenseValidationResult.Validate(saved.LeaseToken, LicenseFeature.Filter, new string('0', 64)).IsValid,
        "A signed lease copied from another Windows server was accepted.");

    using (var overLimitClient = new HttpClient { Timeout = TimeSpan.FromSeconds(10) })
    {
        overLimitClient.DefaultRequestHeaders.Add("X-KMT-Client-IP", "203.0.113.24");
        using var overLimitResponse = await overLimitClient.PostAsJsonAsync(
               $"{publicServerUrl}/api/v1/license/refresh",
               new LicenseRefreshRequest(
                   created.ActivationKey,
                   MachineFingerprint.GetHash(),
                   "203.0.113.24",
                   "integration-test",
                   "Filter",
                   21,
                   "Agent"));
        Require(!overLimitResponse.IsSuccessStatusCode,
            "License Server accepted a heartbeat above the signed player limit.");
    }

    using (var firstServerHeartbeat = new HttpClient { Timeout = TimeSpan.FromSeconds(10) })
    {
        firstServerHeartbeat.DefaultRequestHeaders.Add("X-KMT-Client-IP", "203.0.113.24");
        using var response = await firstServerHeartbeat.PostAsJsonAsync(
            $"{publicServerUrl}/api/v1/license/refresh",
            new LicenseRefreshRequest(
                created.ActivationKey,
                MachineFingerprint.GetHash(),
                "203.0.113.24",
                "multi-server-a",
                "Filter",
                12,
                "Agent"));
        Require(response.IsSuccessStatusCode, "The first licensed GameServer slot heartbeat was rejected.");
    }

    using (var secondServerHeartbeat = new HttpClient { Timeout = TimeSpan.FromSeconds(10) })
    {
        secondServerHeartbeat.DefaultRequestHeaders.Add("X-KMT-Client-IP", "203.0.113.25");
        using var aggregateOverflow = await secondServerHeartbeat.PostAsJsonAsync(
            $"{publicServerUrl}/api/v1/license/refresh",
            new LicenseRefreshRequest(
                created.ActivationKey,
                MachineFingerprint.GetHash(),
                "203.0.113.25",
                "multi-server-b",
                "Filter",
                9,
                "Agent"));
        Require(!aggregateOverflow.IsSuccessStatusCode,
            "The combined player total across two licensed server IPs exceeded the license.");

        using var accepted = await secondServerHeartbeat.PostAsJsonAsync(
            $"{publicServerUrl}/api/v1/license/refresh",
            new LicenseRefreshRequest(
                created.ActivationKey,
                MachineFingerprint.GetHash(),
                "203.0.113.25",
                "multi-server-b",
                "Filter",
                8,
                "Agent"));
        Require(accepted.IsSuccessStatusCode,
            "The second licensed GameServer slot was rejected below the combined player limit.");
    }

    var suspended = await PostAsync<ChangeLicenseStatusRequest, OwnerOperationResponse>(
        adminHttp, $"/api/v1/admin/licenses/{created.Customer.LicenseId}/status", new ChangeLicenseStatusRequest("Suspended"));
    Require(suspended.Success, "Could not suspend test license.");
    var denied = await client.EnsureValidAsync(
        LicenseFeature.Filter,
        "203.0.113.24",
        "integration-test",
        true,
        verifyCurrentServerIp: false);
    Require(!denied.IsValid, "Suspended license was accepted by online refresh.");

    var activatedAgain = await PostAsync<ChangeLicenseStatusRequest, OwnerOperationResponse>(
        adminHttp, $"/api/v1/admin/licenses/{created.Customer.LicenseId}/status", new ChangeLicenseStatusRequest("Active"));
    Require(activatedAgain.Success, "Could not reactivate test license.");
    var renewed = await PostAsync<RenewLicenseRequest, OwnerOperationResponse>(
        adminHttp, $"/api/v1/admin/licenses/{created.Customer.LicenseId}/renew", new RenewLicenseRequest(1));
    Require(renewed.Success, "Could not renew test license.");
    var refreshed = await client.EnsureValidAsync(
        LicenseFeature.Filter,
        "203.0.113.24",
        "integration-test",
        true,
        verifyCurrentServerIp: false);
    Require(refreshed.IsValid, $"Reactivated license did not refresh: {refreshed.Message}");

    var secondCredential = await PostAsync<object, NewCredentialResponse>(
        adminHttp, $"/api/v1/admin/licenses/{created.Customer.LicenseId}/credentials", new { });
    Require(secondCredential.ActivationKey.StartsWith("KMT-", StringComparison.Ordinal), "Package credential was not generated.");
    Require(
        PackageBindingTokenCodec.TryVerify(secondCredential.PackageBindingToken, out var secondBinding, out _) &&
        secondBinding?.PackageId == secondCredential.CredentialId &&
        secondBinding.ServerIp == "203.0.113.24,203.0.113.25",
        "Additional package credential did not include a valid binding.");

    var replacementCredential = await PostAsync<ReissuePackageCredentialRequest, ReissuePackageCredentialResponse>(
        adminHttp,
        $"/api/v1/admin/licenses/{created.Customer.LicenseId}/credentials/reissue",
        new ReissuePackageCredentialRequest(created.CredentialId));
    Require(
        replacementCredential.PackageId == created.CredentialId &&
        replacementCredential.CredentialId != created.CredentialId,
        "Replacement license credential did not preserve the existing package ID.");
    using (var replacementRefreshResponse = await adminHttp.PostAsJsonAsync(
               $"{publicServerUrl}/api/v1/license/refresh",
               new LicenseRefreshRequest(
                   replacementCredential.ActivationKey,
                   new string('F', 64),
                   "203.0.113.24",
                   "replacement-file-test",
                   "GameServer")))
    {
        var replacementRefresh =
            await replacementRefreshResponse.Content.ReadFromJsonAsync<LicenseRefreshResponse>();
        Require(
            replacementRefreshResponse.IsSuccessStatusCode &&
            replacementRefresh is { Success: true } &&
            LicenseTokenCodec.TryVerify(replacementRefresh.LeaseToken, out var replacementClaims, out _) &&
            replacementClaims?.PackageId == created.CredentialId,
            "Replacement license file was not accepted by the existing personalized package.");
    }

    using var migratedResponse = await adminHttp.PostAsJsonAsync(
        $"{publicServerUrl}/api/v1/license/refresh",
        new LicenseRefreshRequest(secondCredential.ActivationKey, new string('F', 64), "203.0.113.24", "migration-test", "Filter"));
    var migrated = await migratedResponse.Content.ReadFromJsonAsync<LicenseRefreshResponse>();
    Require(migratedResponse.IsSuccessStatusCode && migrated is { Success: true },
        "IP-only license incorrectly rejected a different machine fingerprint.");

    var changedIp = await PostAsync<ChangeServerIpRequest, OwnerOperationResponse>(
        adminHttp,
        $"/api/v1/admin/licenses/{created.Customer.LicenseId}/server-ip",
        new ChangeServerIpRequest("203.0.113.26"));
    Require(changedIp.Success, "Could not change the licensed server IP.");

    using var oldIpResponse = await adminHttp.PostAsJsonAsync(
        $"{publicServerUrl}/api/v1/license/refresh",
        new LicenseRefreshRequest(secondCredential.ActivationKey, new string('F', 64), "203.0.113.24", "old-ip-test", "Filter"));
    Require(!oldIpResponse.IsSuccessStatusCode, "The previous server IP was still accepted after the change.");

    adminHttp.DefaultRequestHeaders.Remove("X-KMT-Client-IP");
    adminHttp.DefaultRequestHeaders.Add("X-KMT-Client-IP", "203.0.113.26");
    using var newIpResponse = await adminHttp.PostAsJsonAsync(
        $"{publicServerUrl}/api/v1/license/refresh",
        new LicenseRefreshRequest(secondCredential.ActivationKey, new string('F', 64), "203.0.113.26", "new-ip-test", "Filter"));
    var changedIpRefresh = await newIpResponse.Content.ReadFromJsonAsync<LicenseRefreshResponse>();
    Require(newIpResponse.IsSuccessStatusCode && changedIpRefresh is { Success: true },
        "The changed licensed public IP was not accepted.");
    Require(
        LicenseTokenCodec.TryVerify(changedIpRefresh!.LeaseToken, out var changedClaims, out _) &&
        changedClaims?.ServerIp == "203.0.113.26" &&
        changedClaims.MachineHash == new string('F', 64),
        "The refreshed lease was not bound to the requesting Windows server.");

    var changedCredential = await PostAsync<object, NewCredentialResponse>(
        adminHttp, $"/api/v1/admin/licenses/{created.Customer.LicenseId}/credentials", new { });
    Require(
        PackageBindingTokenCodec.TryVerify(changedCredential.PackageBindingToken, out var changedBinding, out _) &&
        changedBinding?.ServerIp == "203.0.113.26",
        "A package generated after changing IP retained the previous address.");

    var deleted = await PostAsync<object, OwnerOperationResponse>(
        adminHttp,
        $"/api/v1/admin/customers/{created.Customer.CustomerId}/delete",
        new { });
    Require(deleted.Success, "Could not permanently delete the integration-test customer.");

    using var deletedCredentialResponse = await adminHttp.PostAsJsonAsync(
        $"{publicServerUrl}/api/v1/license/refresh",
        new LicenseRefreshRequest(changedCredential.ActivationKey, new string('F', 64), "203.0.113.26", "deleted-test", "Filter"));
    Require(!deletedCredentialResponse.IsSuccessStatusCode, "A deleted customer's credential was still accepted.");

    var remainingCustomers = await adminHttp.GetFromJsonAsync<IReadOnlyList<OwnerCustomerSummary>>(
        $"/api/v1/admin/customers?search={Uri.EscapeDataString(created.Customer.CustomerCode)}");
    Require(remainingCustomers is not null && remainingCustomers.Count == 0, "Deleted customer still appears in the owner API.");

    var limitCustomer = await PostAsync<CreateCustomerRequest, CreateCustomerResponse>(
        adminHttp,
        "/api/v1/admin/customers",
        new CreateCustomerRequest(
            $"Player Limit Integration Test {suffix}",
            1,
            LicenseFeature.Filter,
            1,
            20,
            24,
            string.Empty,
            "Automated player-limit-only licensing integration test",
            LicenseBindingMode.PlayerLimit));
    Require(
        limitCustomer.Customer.BindingMode == LicenseBindingMode.PlayerLimit &&
        string.IsNullOrEmpty(limitCustomer.Customer.ServerIp),
        "Player-limit-only customer unexpectedly retained an IP binding.");
    Require(
        PackageBindingTokenCodec.TryVerify(limitCustomer.PackageBindingToken, out var limitBinding, out _) &&
        limitBinding?.BindingMode == LicenseBindingMode.PlayerLimit &&
        string.IsNullOrEmpty(limitBinding.ServerIp),
        "Player-limit-only package metadata is invalid.");

    adminHttp.DefaultRequestHeaders.Remove("X-KMT-Client-IP");
    adminHttp.DefaultRequestHeaders.Add("X-KMT-Client-IP", "2001:db8::41");
    using var limitActivationResponse = await adminHttp.PostAsJsonAsync(
        $"{publicServerUrl}/api/v1/license/refresh",
        new LicenseRefreshRequest(
            limitCustomer.ActivationKey,
            new string('B', 64),
            "not-an-ip",
            "limit-first-activation",
            "Filter"));
    var limitActivation = await limitActivationResponse.Content.ReadFromJsonAsync<LicenseRefreshResponse>();
    Require(
        limitActivationResponse.IsSuccessStatusCode &&
        limitActivation is { Success: true } &&
        LicenseTokenCodec.TryVerify(limitActivation.LeaseToken, out var limitClaims, out _) &&
        limitClaims?.BindingMode == LicenseBindingMode.PlayerLimit &&
        string.IsNullOrEmpty(limitClaims.ServerIp) &&
        limitClaims.MachineHash == new string('B', 64),
        "Player-limit-only activation did not protect its short-lived lease against copying.");

    adminHttp.DefaultRequestHeaders.Remove("X-KMT-Client-IP");
    adminHttp.DefaultRequestHeaders.Add("X-KMT-Client-IP", "198.51.100.77");
    using var changedNetworkResponse = await adminHttp.PostAsJsonAsync(
        $"{publicServerUrl}/api/v1/license/refresh",
        new LicenseRefreshRequest(
            limitCustomer.ActivationKey,
            new string('C', 64),
            "192.168.90.15",
            "limit-network-change",
            "Filter"));
    Require(
        changedNetworkResponse.IsSuccessStatusCode,
        "Player-limit-only license was rejected after its public IP and machine changed.");

    using var overLimitModeResponse = await adminHttp.PostAsJsonAsync(
        $"{publicServerUrl}/api/v1/license/refresh",
        new LicenseRefreshRequest(
            limitCustomer.ActivationKey,
            new string('D', 64),
            string.Empty,
            "limit-overflow-test",
            "Filter",
            21,
            "Agent"));
    Require(!overLimitModeResponse.IsSuccessStatusCode,
        "Player-limit-only license accepted a heartbeat above its signed maximum.");

    var deletedLimit = await PostAsync<object, OwnerOperationResponse>(
        adminHttp,
        $"/api/v1/admin/customers/{limitCustomer.Customer.CustomerId}/delete",
        new { });
    Require(deletedLimit.Success, "Could not delete the player-limit integration-test customer.");

    var publicUpdateUrl = Environment.GetEnvironmentVariable("KMTGUARD_TEST_UPDATE_PUBLIC");
    if (!string.IsNullOrWhiteSpace(publicUpdateUrl))
    {
        var publicCustomer = await PostAsync<CreateCustomerRequest, CreateCustomerResponse>(
            adminHttp,
            "/api/v1/admin/customers",
            new CreateCustomerRequest(
                $"Public Update Integration Test {suffix}",
                1,
                LicenseFeature.Filter,
                1,
                10,
                24,
                string.Empty,
                "Temporary public HTTPS update-streaming test",
                LicenseBindingMode.PlayerLimit));
        try
        {
            using var publicHttp = new HttpClient { Timeout = TimeSpan.FromMinutes(3) };
            var publicMachine = new string('E', 64);
            using var publicCheckResponse = await publicHttp.PostAsJsonAsync(
                $"{publicUpdateUrl.TrimEnd('/')}/api/v1/update/check",
                new UpdateCheckRequest(
                    publicCustomer.ActivationKey,
                    publicMachine,
                    string.Empty,
                    "1.9.1"));
            var publicCheck =
                await publicCheckResponse.Content.ReadFromJsonAsync<UpdateCheckResponse>()
                ?? throw new InvalidDataException("The public update check returned an empty response.");
            var publicRelease = publicCheck.Release
                                ?? throw new InvalidDataException("The public update check did not return a release.");
            Require(
                publicCheckResponse.IsSuccessStatusCode &&
                publicCheck is { Success: true, UpdateAvailable: true } &&
                Version.TryParse(publicRelease.Version, out var publicVersion) &&
                publicVersion > new Version(1, 9, 1),
                "The public HTTPS bridge did not return the latest published release.");

            using var publicDownloadResponse = await publicHttp.PostAsJsonAsync(
                $"{publicUpdateUrl.TrimEnd('/')}/api/v1/update/download/{Uri.EscapeDataString(publicRelease.Version)}",
                new UpdateDownloadRequest(
                    publicCustomer.ActivationKey,
                    publicMachine,
                    string.Empty,
                    "1.9.1"));
            Require(
                publicDownloadResponse.IsSuccessStatusCode,
                "The public HTTPS bridge did not stream the licensed update.");
            var publicBytes = await publicDownloadResponse.Content.ReadAsByteArrayAsync();
            Require(
                publicBytes.LongLength == publicRelease.PackageSize &&
                Convert.ToHexString(SHA256.HashData(publicBytes))
                    .Equals(publicRelease.PackageSha256, StringComparison.OrdinalIgnoreCase),
                "The public HTTPS update stream failed its size or hash check.");
        }
        finally
        {
            var publicDeleted = await PostAsync<object, OwnerOperationResponse>(
                adminHttp,
                $"/api/v1/admin/customers/{publicCustomer.Customer.CustomerId}/delete",
                new { });
            Require(publicDeleted.Success, "Could not delete the public updater integration-test customer.");
        }
    }

    Console.WriteLine("PASS: activation and signed lease");
    Console.WriteLine("PASS: licensed update-channel authorization");
    Console.WriteLine("PASS: KMTGuard 2 release discovery, package identity and verified ZIP download");
    Console.WriteLine("PASS: signed per-customer package binding");
    Console.WriteLine("PASS: Filter, GameServer and ShardManager feature claims");
    Console.WriteLine("PASS: signed-token tamper rejection and per-lease machine binding");
    Console.WriteLine("PASS: configured, signed and observed server IP enforcement");
    Console.WriteLine("PASS: local NIC/public IP enforcement");
    Console.WriteLine("PASS: License Server rejects over-limit Agent heartbeat");
    Console.WriteLine("PASS: multiple IP-bound server slots share one total player limit");
    Console.WriteLine("PASS: isolated test license does not touch machine-wide lease");
    Console.WriteLine("PASS: suspend, reactivate, renew and additional package credential");
    Console.WriteLine("PASS: replacement license file preserves an existing package ID");
    Console.WriteLine("PASS: IP-only licensing ignores machine fingerprint changes");
    Console.WriteLine("PASS: independent licensed public IP change");
    Console.WriteLine("PASS: player-limit-only mode ignores IP/HWID and rejects over-limit heartbeat");
    Console.WriteLine("PASS: permanent customer deletion and credential invalidation");
    if (!string.IsNullOrWhiteSpace(publicUpdateUrl))
        Console.WriteLine("PASS: public HTTPS update discovery and verified streaming download");
}
finally
{
    Environment.SetEnvironmentVariable("KMTGUARD_LICENSE_PATH", previousPath);
    if (Directory.Exists(temporaryRoot))
        Directory.Delete(temporaryRoot, recursive: true);
}

static async Task<TResponse> PostAsync<TRequest, TResponse>(HttpClient client, string path, TRequest request)
{
    using var response = await client.PostAsJsonAsync(path, request);
    if (!response.IsSuccessStatusCode)
        throw new InvalidOperationException(await response.Content.ReadAsStringAsync());
    return await response.Content.ReadFromJsonAsync<TResponse>()
           ?? throw new InvalidDataException("License Server returned an empty response.");
}

static void Require(bool condition, string message)
{
    if (!condition)
        throw new InvalidOperationException(message);
}
