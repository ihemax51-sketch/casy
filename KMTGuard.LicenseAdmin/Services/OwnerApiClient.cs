using System.Net.Http;
using System.Net.Http.Json;
using KMTGuard.LicenseAdmin.Models;
using KMTGuard.Licensing;

namespace KMTGuard.LicenseAdmin.Services;

public sealed class OwnerApiClient : IDisposable
{
    private readonly HttpClient _httpClient = new() { Timeout = TimeSpan.FromSeconds(12) };
    private readonly OwnerSettings _settings;

    public OwnerApiClient(OwnerSettings settings)
    {
        _settings = settings;
        var tokenPath = Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData),
            "KMTGuardLicensing",
            "Secrets",
            "admin-api-token.txt");
        if (!File.Exists(tokenPath))
            throw new FileNotFoundException("Owner API token is missing. Run the licensing key setup first.", tokenPath);
        _httpClient.DefaultRequestHeaders.Add("X-KMT-Admin-Token", File.ReadAllText(tokenPath).Trim());
    }

    public Task<OwnerDashboardResponse> GetDashboardAsync(CancellationToken ct = default) =>
        GetAsync<OwnerDashboardResponse>("/api/v1/admin/dashboard", ct);

    public Task<IReadOnlyList<OwnerCustomerSummary>> GetCustomersAsync(string? search, CancellationToken ct = default) =>
        GetAsync<IReadOnlyList<OwnerCustomerSummary>>($"/api/v1/admin/customers?search={Uri.EscapeDataString(search ?? string.Empty)}", ct);

    public Task<CreateCustomerResponse> CreateCustomerAsync(CreateCustomerRequest request, CancellationToken ct = default) =>
        PostAsync<CreateCustomerRequest, CreateCustomerResponse>("/api/v1/admin/customers", request, ct);

    public Task<NewCredentialResponse> CreateCredentialAsync(string licenseId, CancellationToken ct = default) =>
        PostAsync<object, NewCredentialResponse>($"/api/v1/admin/licenses/{licenseId}/credentials", new { }, ct);

    public Task<ReissuePackageCredentialResponse> ReissuePackageCredentialAsync(
        string licenseId,
        string packageId,
        CancellationToken ct = default) =>
        PostAsync<ReissuePackageCredentialRequest, ReissuePackageCredentialResponse>(
            $"/api/v1/admin/licenses/{licenseId}/credentials/reissue",
            new ReissuePackageCredentialRequest(packageId),
            ct);

    public Task<OwnerOperationResponse> RenewAsync(string licenseId, int months, CancellationToken ct = default) =>
        PostAsync<RenewLicenseRequest, OwnerOperationResponse>($"/api/v1/admin/licenses/{licenseId}/renew", new RenewLicenseRequest(months), ct);

    public Task<OwnerOperationResponse> SetStatusAsync(string licenseId, string status, CancellationToken ct = default) =>
        PostAsync<ChangeLicenseStatusRequest, OwnerOperationResponse>($"/api/v1/admin/licenses/{licenseId}/status", new ChangeLicenseStatusRequest(status), ct);

    public Task<OwnerOperationResponse> ChangeServerIpAsync(string licenseId, string serverIp, CancellationToken ct = default) =>
        PostAsync<ChangeServerIpRequest, OwnerOperationResponse>(
            $"/api/v1/admin/licenses/{licenseId}/server-ip",
            new ChangeServerIpRequest(serverIp),
            ct);

    public Task<OwnerOperationResponse> DeleteCustomerAsync(
        string customerId,
        CancellationToken ct = default) =>
        PostAsync<object, OwnerOperationResponse>(
            $"/api/v1/admin/customers/{customerId}/delete",
            new { },
            ct);

    public Task<OwnerOperationResponse> ResetMachineAsync(string licenseId, CancellationToken ct = default) =>
        PostAsync<object, OwnerOperationResponse>($"/api/v1/admin/licenses/{licenseId}/reset-machine", new { }, ct);

    public async Task<bool> IsHealthyAsync(CancellationToken ct = default)
    {
        try
        {
            using var response = await _httpClient.GetAsync(BuildUrl("/health"), ct);
            return response.IsSuccessStatusCode;
        }
        catch
        {
            return false;
        }
    }

    private async Task<T> GetAsync<T>(string path, CancellationToken ct)
    {
        using var response = await _httpClient.GetAsync(BuildUrl(path), ct);
        return await ReadResponseAsync<T>(response, ct);
    }

    private async Task<TResponse> PostAsync<TRequest, TResponse>(string path, TRequest request, CancellationToken ct)
    {
        using var response = await _httpClient.PostAsJsonAsync(BuildUrl(path), request, ct);
        return await ReadResponseAsync<TResponse>(response, ct);
    }

    private static async Task<T> ReadResponseAsync<T>(HttpResponseMessage response, CancellationToken ct)
    {
        if (response.IsSuccessStatusCode)
            return await response.Content.ReadFromJsonAsync<T>(cancellationToken: ct)
                   ?? throw new InvalidDataException("License Server returned an empty response.");

        var error = await response.Content.ReadFromJsonAsync<OwnerOperationResponse>(cancellationToken: ct);
        throw new InvalidOperationException(error?.Message ?? $"License Server returned HTTP {(int)response.StatusCode}.");
    }

    private string BuildUrl(string path) => $"{_settings.AdminApiUrl.Trim().TrimEnd('/')}{path}";

    public void Dispose() => _httpClient.Dispose();
}
