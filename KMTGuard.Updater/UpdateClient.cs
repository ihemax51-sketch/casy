using System.Net.Http;
using System.Net.Http.Json;
using System.Security.Cryptography;
using System.Security.Cryptography.X509Certificates;
using KMTGuard.Licensing;

namespace KMTGuard.Updater;

public sealed class UpdateClient : IDisposable
{
    private readonly HttpClient _httpClient;
    private readonly LicenseFileDocument _license;
    private readonly string _serverIp;
    private readonly string _machineHash;

    public UpdateClient()
    {
        var licensePath = LicenseFileDocument.GetLocalPath();
        if (!File.Exists(licensePath))
            throw new FileNotFoundException("KMTGuard-License.txt was not found beside the Updater.", licensePath);
        _license = LicenseFileDocument.Load(licensePath);
        if (string.IsNullOrWhiteSpace(_license.ActivationKey) ||
            string.IsNullOrWhiteSpace(_license.ServerUrl))
            throw new InvalidDataException("The KMTGuard license has not been activated or is incomplete.");

        _serverIp = LoadServerIp();
        _machineHash = MachineFingerprint.GetHash();
        var expectedPin = _license.CertificateSha256.Trim().Replace(":", string.Empty);
        var handler = new HttpClientHandler();
        if (expectedPin.Length > 0)
        {
            handler.ServerCertificateCustomValidationCallback = (_, certificate, _, _) =>
                certificate is not null && CertificateMatches(certificate, expectedPin);
        }
        _httpClient = new HttpClient(handler) { Timeout = TimeSpan.FromMinutes(10) };
    }

    public async Task<UpdateCheckResponse> CheckAsync(string currentVersion, CancellationToken cancellationToken)
    {
        using var response = await _httpClient.PostAsJsonAsync(
            BuildUrl("/api/v1/update/check"),
            new UpdateCheckRequest(
                _license.ActivationKey,
                _machineHash,
                _serverIp,
                currentVersion),
            cancellationToken);
        var payload = await response.Content.ReadFromJsonAsync<UpdateCheckResponse>(
            cancellationToken: cancellationToken);
        if (payload is null)
            throw new InvalidDataException("License Server returned an empty update response.");
        if (!response.IsSuccessStatusCode || !payload.Success)
            throw new InvalidOperationException(payload.Message);
        return payload;
    }

    public async Task DownloadAsync(
        string version,
        string destination,
        IProgress<double> progress,
        CancellationToken cancellationToken)
    {
        using var request = new HttpRequestMessage(
            HttpMethod.Post,
            BuildUrl($"/api/v1/update/download/{Uri.EscapeDataString(version)}"))
        {
            Content = JsonContent.Create(new UpdateDownloadRequest(
                _license.ActivationKey,
                _machineHash,
                _serverIp,
                        typeof(UpdateClient).Assembly.GetName().Version?.ToString(3) ?? "2.6.15"))
        };
        using var response = await _httpClient.SendAsync(
            request,
            HttpCompletionOption.ResponseHeadersRead,
            cancellationToken);
        if (!response.IsSuccessStatusCode)
        {
            var error = await response.Content.ReadFromJsonAsync<OwnerOperationResponse>(
                cancellationToken: cancellationToken);
            throw new InvalidOperationException(
                error?.Message ?? $"Update download failed with HTTP {(int)response.StatusCode}.");
        }

        var total = response.Content.Headers.ContentLength;
        await using var input = await response.Content.ReadAsStreamAsync(cancellationToken);
        await using var output = new FileStream(
            destination,
            FileMode.Create,
            FileAccess.Write,
            FileShare.None,
            1024 * 128,
            FileOptions.Asynchronous | FileOptions.SequentialScan);
        var buffer = new byte[1024 * 128];
        long readTotal = 0;
        while (true)
        {
            var read = await input.ReadAsync(buffer, cancellationToken);
            if (read == 0)
                break;
            await output.WriteAsync(buffer.AsMemory(0, read), cancellationToken);
            readTotal += read;
            if (total is > 0)
                progress.Report((double)readTotal / total.Value);
        }
        progress.Report(1);
    }

    private static string LoadServerIp()
    {
        try
        {
            var path = Path.Combine(AppContext.BaseDirectory, "Settings.json");
            if (!File.Exists(path))
                return string.Empty;
            using var document = System.Text.Json.JsonDocument.Parse(File.ReadAllText(path));
            return document.RootElement.TryGetProperty("ServerIP", out var value)
                ? value.GetString()?.Trim() ?? string.Empty
                : string.Empty;
        }
        catch
        {
            return string.Empty;
        }
    }

    private string BuildUrl(string path) => $"{_license.ServerUrl.Trim().TrimEnd('/')}{path}";

    private static bool CertificateMatches(X509Certificate2 certificate, string expectedHex)
    {
        try
        {
            var expected = Convert.FromHexString(expectedHex);
            var actual = SHA256.HashData(certificate.RawData);
            return expected.Length == actual.Length &&
                   CryptographicOperations.FixedTimeEquals(expected, actual);
        }
        catch (FormatException)
        {
            return false;
        }
    }

    public void Dispose() => _httpClient.Dispose();
}
