using System.Net;
using System.Net.Http.Json;
using System.Net.Security;
using System.Net.Sockets;
using System.Security.Cryptography;
using System.Security.Cryptography.X509Certificates;
using System.Text.Json;

namespace KMTGuard.Licensing;

public sealed class LicenseClient
{
    private static readonly SemaphoreSlim FileLock = new(1, 1);
    private readonly HttpClient? _providedHttpClient;

    public LicenseClient(HttpClient? httpClient = null)
    {
        _providedHttpClient = httpClient;
    }

    public async Task<LicenseClientResult> EnsureValidAsync(
        LicenseFeature requiredFeature,
        string serverIp,
        string appVersion,
        bool forceOnline,
        CancellationToken cancellationToken = default,
        int currentPlayers = 0,
        string runtimeRole = "",
        bool verifyCurrentServerIp = true)
    {
        var documents = LoadAvailableDocuments();
        var bindingMode = ResolveBindingMode(documents);
        if (verifyCurrentServerIp && bindingMode == LicenseBindingMode.Ip)
        {
            var serverIpVerification =
                await ServerIpVerifier.VerifyCurrentMachineAsync(serverIp, cancellationToken);
            if (!serverIpVerification.IsMatch)
                return new LicenseClientResult(false, serverIpVerification.Message, null, false);
        }

        var machineHash = MachineFingerprint.GetHash();
        var best = SelectBestValidation(documents, requiredFeature, machineHash, serverIp);
        var shouldRefresh = forceOnline || !best.Validation.IsValid ||
                            best.Validation.Claims?.LeaseExpiresUtc <= DateTimeOffset.UtcNow.AddHours(2);

        if (shouldRefresh)
        {
            var bootstrap = documents.FirstOrDefault(item =>
                !string.IsNullOrWhiteSpace(item.Document.ActivationKey) &&
                !string.IsNullOrWhiteSpace(item.Document.ServerUrl));
            if (bootstrap.Document is not null)
            {
                try
                {
                    using var ownedClient = _providedHttpClient is null
                        ? CreatePinnedHttpClient(
                            bootstrap.Document.CertificateSha256,
                            bindingMode == LicenseBindingMode.Ip)
                        : null;
                    var httpClient = _providedHttpClient ?? ownedClient!;
                    using var response = await httpClient.PostAsJsonAsync(
                        $"{bootstrap.Document.ServerUrl.TrimEnd('/')}/api/v1/license/refresh",
                        new LicenseRefreshRequest(
                            bootstrap.Document.ActivationKey,
                            machineHash,
                            serverIp ?? string.Empty,
                            appVersion,
                            requiredFeature.ToClaimValue(),
                            Math.Max(0, currentPlayers),
                            runtimeRole?.Trim() ?? string.Empty),
                        cancellationToken);
                    var payload = await response.Content.ReadFromJsonAsync<LicenseRefreshResponse>(cancellationToken);
                    if (response.IsSuccessStatusCode && payload is { Success: true, LeaseToken.Length: > 0 })
                    {
                        var refreshed = LicenseValidationResult.Validate(
                            payload.LeaseToken,
                            requiredFeature,
                            machineHash,
                            expectedServerIp: serverIp);
                        if (!refreshed.IsValid)
                            return new LicenseClientResult(false, refreshed.Message, refreshed.Claims, true);

                        bootstrap.Document.LeaseToken = payload.LeaseToken;
                        bootstrap.Document.BindingMode =
                            refreshed.Claims?.BindingMode ?? bootstrap.Document.BindingMode;
                        await SaveEverywhereAsync(bootstrap.Document, cancellationToken);
                        return new LicenseClientResult(true, payload.Message, refreshed.Claims, true);
                    }

                    if (payload is { Success: false })
                        return new LicenseClientResult(false, payload.Message, best.Validation.Claims, true);
                    if (!best.Validation.IsValid)
                        return new LicenseClientResult(false, $"License server returned HTTP {(int)response.StatusCode}.", null, true);
                }
                catch (Exception ex) when (ex is HttpRequestException or TaskCanceledException or IOException or JsonException)
                {
                    if (!best.Validation.IsValid)
                        return new LicenseClientResult(false, $"License server is unavailable: {ex.Message}", null, false);
                }
            }
        }

        return new LicenseClientResult(
            best.Validation.IsValid,
            best.Validation.IsValid ? "Using the current signed license lease." : best.Validation.Message,
            best.Validation.Claims,
            false);
    }

    private static HttpClient CreatePinnedHttpClient(
        string certificateSha256,
        bool forceIpv4)
    {
        var expected = certificateSha256.Trim().Replace(":", string.Empty).ToUpperInvariant();
        if (!forceIpv4)
        {
            var defaultHandler = new HttpClientHandler();
            if (expected.Length > 0)
            {
                defaultHandler.ServerCertificateCustomValidationCallback = (_, certificate, _, _) =>
                    certificate is not null && CertificateMatches(certificate, expected);
            }

            return new HttpClient(defaultHandler) { Timeout = TimeSpan.FromSeconds(8) };
        }

        var handler = new SocketsHttpHandler
        {
            ConnectTimeout = TimeSpan.FromSeconds(5),
            ConnectCallback = ConnectOverIpv4Async
        };
        if (expected.Length > 0)
        {
            handler.SslOptions.RemoteCertificateValidationCallback = (_, certificate, _, _) =>
                certificate is not null &&
                CertificateMatches(new X509Certificate2(certificate), expected);
        }

        return new HttpClient(handler) { Timeout = TimeSpan.FromSeconds(8) };
    }

    private static async ValueTask<Stream> ConnectOverIpv4Async(
        SocketsHttpConnectionContext context,
        CancellationToken cancellationToken)
    {
        var addresses = await Dns.GetHostAddressesAsync(
            context.DnsEndPoint.Host,
            AddressFamily.InterNetwork,
            cancellationToken);
        Exception? lastError = null;
        foreach (var address in addresses)
        {
            var socket = new Socket(AddressFamily.InterNetwork, SocketType.Stream, ProtocolType.Tcp)
            {
                NoDelay = true
            };
            try
            {
                await socket.ConnectAsync(
                    new IPEndPoint(address, context.DnsEndPoint.Port),
                    cancellationToken);
                return new NetworkStream(socket, ownsSocket: true);
            }
            catch (Exception ex) when (ex is SocketException or OperationCanceledException)
            {
                socket.Dispose();
                lastError = ex;
                if (cancellationToken.IsCancellationRequested)
                    throw;
            }
        }

        throw new HttpRequestException(
            $"No IPv4 address could be reached for {context.DnsEndPoint.Host}.",
            lastError);
    }

    private static bool CertificateMatches(X509Certificate2 certificate, string expectedHex)
    {
        try
        {
            var expected = Convert.FromHexString(expectedHex);
            var actual = SHA256.HashData(certificate.RawData);
            return expected.Length == actual.Length && CryptographicOperations.FixedTimeEquals(expected, actual);
        }
        catch (FormatException)
        {
            return false;
        }
    }

    private static List<(string Path, LicenseFileDocument Document)> LoadAvailableDocuments()
    {
        var result = new List<(string Path, LicenseFileDocument Document)>();
        var localPath = LicenseFileDocument.GetLocalPath();
        LicenseFileDocument? localDocument = null;
        try
        {
            if (File.Exists(localPath))
            {
                localDocument = LicenseFileDocument.Load(localPath);
                result.Add((localPath, localDocument));
            }
        }
        catch
        {
        }

        // An explicitly configured path is isolated by design (tests, tools and
        // dedicated services must never read or overwrite the machine lease).
        if (!string.IsNullOrWhiteSpace(Environment.GetEnvironmentVariable("KMTGUARD_LICENSE_PATH")))
            return result;

        var machinePath = LicenseFileDocument.GetMachinePath();
        if (machinePath.Equals(localPath, StringComparison.OrdinalIgnoreCase))
            return result;

        try
        {
            if (File.Exists(machinePath))
            {
                var machineDocument = LicenseFileDocument.Load(machinePath);
                // A personalized local package is authoritative. Never borrow a
                // valid lease belonging to another activation key on this machine.
                if (localDocument is null ||
                    string.IsNullOrWhiteSpace(localDocument.ActivationKey) ||
                    HasSameBootstrap(localDocument, machineDocument))
                {
                    result.Add((machinePath, machineDocument));
                }
            }
        }
        catch
        {
        }

        return result;
    }

    private static bool HasSameBootstrap(LicenseFileDocument left, LicenseFileDocument right) =>
        left.ActivationKey.Trim().Equals(right.ActivationKey.Trim(), StringComparison.Ordinal) &&
        left.ServerUrl.Trim().TrimEnd('/').Equals(
            right.ServerUrl.Trim().TrimEnd('/'),
            StringComparison.OrdinalIgnoreCase);

    private static LicenseBindingMode ResolveBindingMode(
        IEnumerable<(string Path, LicenseFileDocument Document)> documents)
    {
        foreach (var (_, document) in documents)
        {
            if (LicenseTokenCodec.TryVerify(document.LeaseToken, out var claims, out _) &&
                claims is not null)
            {
                return claims.BindingMode;
            }
        }

        return documents.FirstOrDefault().Document?.BindingMode ?? LicenseBindingMode.Ip;
    }

    private static (LicenseValidationResult Validation, string? Path) SelectBestValidation(
        IEnumerable<(string Path, LicenseFileDocument Document)> documents,
        LicenseFeature requiredFeature,
        string machineHash,
        string serverIp)
    {
        var candidates = documents
            .Select(item => (
                Validation: LicenseValidationResult.Validate(
                    item.Document.LeaseToken,
                    requiredFeature,
                    machineHash,
                    expectedServerIp: serverIp),
                item.Path))
            .OrderByDescending(item => item.Validation.IsValid)
            .ThenByDescending(item => item.Validation.Claims?.LeaseExpiresUtc ?? DateTimeOffset.MinValue)
            .ToArray();
        return candidates.FirstOrDefault() is { } best && best.Validation is not null
            ? best
            : (new LicenseValidationResult(false, "KMTGuard-License.txt was not found or has not been activated.", null), null);
    }

    private static async Task SaveEverywhereAsync(LicenseFileDocument document, CancellationToken cancellationToken)
    {
        await FileLock.WaitAsync(cancellationToken);
        try
        {
            var configuredPath = Environment.GetEnvironmentVariable("KMTGUARD_LICENSE_PATH");
            var paths = !string.IsNullOrWhiteSpace(configuredPath)
                ? new[] { LicenseFileDocument.GetLocalPath() }
                : new[] { LicenseFileDocument.GetLocalPath(), LicenseFileDocument.GetMachinePath() }
                    .Distinct(StringComparer.OrdinalIgnoreCase);
            foreach (var path in paths)
            {
                try { document.Save(path); } catch when (!path.Equals(LicenseFileDocument.GetLocalPath(), StringComparison.OrdinalIgnoreCase)) { }
            }
        }
        finally
        {
            FileLock.Release();
        }
    }
}
