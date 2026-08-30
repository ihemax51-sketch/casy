using System.Net;
using System.Threading.RateLimiting;
using KMTGuard.Licensing;
using KMTGuard.LicenseServer;
using Microsoft.AspNetCore.RateLimiting;

var builder = WebApplication.CreateBuilder(args);
builder.Host.UseWindowsService(options => options.ServiceName = "KMTGuard License Server");
builder.Services.Configure<LicenseServerOptions>(builder.Configuration.GetSection("LicenseServer"));
var configuredOptions = builder.Configuration.GetSection("LicenseServer").Get<LicenseServerOptions>() ?? new LicenseServerOptions();
if (configuredOptions.PublicPort == configuredOptions.AdminPort)
    throw new InvalidOperationException("The public and owner API ports must be different.");
builder.WebHost.ConfigureKestrel(options =>
{
    options.Listen(IPAddress.Loopback, configuredOptions.PublicPort);
    options.Listen(IPAddress.Loopback, configuredOptions.AdminPort);
});
builder.Services.AddSingleton<LicenseSigner>();
builder.Services.AddSingleton<AdminAccessService>();
builder.Services.AddSingleton<LicenseDatabase>();
builder.Services.AddSingleton<UpdateCatalogService>();
builder.Services.AddRateLimiter(options =>
{
    options.RejectionStatusCode = StatusCodes.Status429TooManyRequests;
    options.AddPolicy("license", context => RateLimitPartition.GetSlidingWindowLimiter(
        GetRateLimitKey(context),
        _ => new SlidingWindowRateLimiterOptions
        {
            PermitLimit = 60,
            Window = TimeSpan.FromMinutes(1),
            SegmentsPerWindow = 6,
            QueueLimit = 0,
            AutoReplenishment = true
        }));
});

var app = builder.Build();
var database = app.Services.GetRequiredService<LicenseDatabase>();
await database.InitializeAsync();

app.UseRateLimiter();
app.Use(async (context, next) =>
{
    if (context.Request.Path.StartsWithSegments("/api/v1/admin"))
    {
        var access = context.RequestServices.GetRequiredService<AdminAccessService>();
        if (!access.IsAuthorized(context))
        {
            context.Response.StatusCode = StatusCodes.Status401Unauthorized;
            await context.Response.WriteAsJsonAsync(new OwnerOperationResponse(false, "Owner access is restricted to this VPS."));
            return;
        }
    }

    await next();
});

app.MapGet("/health", () => Results.Ok(new
{
    status = "healthy",
    service = "KMTGuard License Server",
    keyId = LicenseTokenCodec.TrustedKeyId,
    utc = DateTimeOffset.UtcNow
}));

app.MapPost("/api/v1/license/refresh", async (
    HttpContext context,
    LicenseRefreshRequest request,
    LicenseDatabase db,
    CancellationToken ct) =>
{
    try
    {
        var response = await db.RefreshAsync(request, GetObservedClientIp(context), ct);
        return response.Success ? Results.Ok(response) : Results.BadRequest(response);
    }
    catch (Exception ex)
    {
        return Results.BadRequest(new LicenseRefreshResponse(false, ex.Message, null, null, null, null, DateTimeOffset.UtcNow));
    }
}).RequireRateLimiting("license");

app.MapPost("/api/v1/update/check", async (
    HttpContext context,
    UpdateCheckRequest request,
    LicenseDatabase db,
    UpdateCatalogService catalog,
    CancellationToken ct) =>
{
    try
    {
        var authorization = await db.AuthorizeUpdateAsync(
            request,
            GetObservedClientIp(context),
            ct);
        if (!authorization.Success)
        {
            return Results.BadRequest(new UpdateCheckResponse(
                false,
                false,
                authorization.Message,
                null,
                null,
                DateTimeOffset.UtcNow));
        }

        var release = catalog.FindLatestNewerThan(request.CurrentVersion);
        return Results.Ok(new UpdateCheckResponse(
            true,
            release is not null,
            release is null ? "KMTGuard is up to date." : $"KMTGuard {release.Version} is available.",
            release,
            release is null ? null : authorization.PackageBindingToken,
            DateTimeOffset.UtcNow));
    }
    catch (Exception ex)
    {
        return Results.BadRequest(new UpdateCheckResponse(
            false,
            false,
            ex.Message,
            null,
            null,
            DateTimeOffset.UtcNow));
    }
}).RequireRateLimiting("license");

app.MapPost("/api/v1/update/download/{version}", async (
    string version,
    HttpContext context,
    UpdateDownloadRequest request,
    LicenseDatabase db,
    UpdateCatalogService catalog,
    CancellationToken ct) =>
{
    try
    {
        var authorization = await db.AuthorizeUpdateAsync(
            new UpdateCheckRequest(
                request.ActivationKey,
                request.MachineHash,
                request.ServerIp,
                request.CurrentVersion),
            GetObservedClientIp(context),
            ct);
        if (!authorization.Success)
            return Results.BadRequest(new OwnerOperationResponse(false, authorization.Message));

        var stream = await catalog.OpenVerifiedPackageAsync(version, ct);
        return Results.File(
            stream,
            "application/zip",
            $"KMTGuard-{version}-Update.zip",
            enableRangeProcessing: false);
    }
    catch (FileNotFoundException ex)
    {
        return Results.NotFound(new OwnerOperationResponse(false, ex.Message));
    }
    catch (Exception ex)
    {
        return Results.BadRequest(new OwnerOperationResponse(false, ex.Message));
    }
}).RequireRateLimiting("license");

var admin = app.MapGroup("/api/v1/admin");
admin.MapGet("/dashboard", (LicenseDatabase db, CancellationToken ct) => db.GetDashboardAsync(ct));
admin.MapGet("/customers", (string? search, LicenseDatabase db, CancellationToken ct) => db.GetCustomersAsync(search, ct));
admin.MapPost("/customers", async (CreateCustomerRequest request, LicenseDatabase db, CancellationToken ct) =>
{
    try { return Results.Ok(await db.CreateCustomerAsync(request, ct)); }
    catch (Exception ex) { return Results.BadRequest(new OwnerOperationResponse(false, ex.Message)); }
});
admin.MapPost("/customers/{customerId}/delete", async (string customerId, LicenseDatabase db, CancellationToken ct) =>
{
    try { return Results.Ok(await db.DeleteCustomerAsync(customerId, ct)); }
    catch (KeyNotFoundException ex) { return Results.NotFound(new OwnerOperationResponse(false, ex.Message)); }
    catch (Exception ex) { return Results.BadRequest(new OwnerOperationResponse(false, ex.Message)); }
});
admin.MapPost("/licenses/{licenseId}/credentials", async (string licenseId, LicenseDatabase db, CancellationToken ct) =>
{
    try { return Results.Ok(await db.CreateCredentialAsync(licenseId, ct)); }
    catch (Exception ex) { return Results.BadRequest(new OwnerOperationResponse(false, ex.Message)); }
});
admin.MapPost("/licenses/{licenseId}/credentials/reissue", async (
    string licenseId,
    ReissuePackageCredentialRequest request,
    LicenseDatabase db,
    CancellationToken ct) =>
{
    try { return Results.Ok(await db.ReissuePackageCredentialAsync(licenseId, request.PackageId, ct)); }
    catch (Exception ex) { return Results.BadRequest(new OwnerOperationResponse(false, ex.Message)); }
});
admin.MapPost("/licenses/{licenseId}/renew", async (string licenseId, RenewLicenseRequest request, LicenseDatabase db, CancellationToken ct) =>
{
    try { return Results.Ok(await db.RenewAsync(licenseId, request.Months, ct)); }
    catch (Exception ex) { return Results.BadRequest(new OwnerOperationResponse(false, ex.Message)); }
});
admin.MapPost("/licenses/{licenseId}/status", async (string licenseId, ChangeLicenseStatusRequest request, LicenseDatabase db, CancellationToken ct) =>
{
    try { return Results.Ok(await db.SetStatusAsync(licenseId, request.Status, ct)); }
    catch (Exception ex) { return Results.BadRequest(new OwnerOperationResponse(false, ex.Message)); }
});
admin.MapPost("/licenses/{licenseId}/server-ip", async (string licenseId, ChangeServerIpRequest request, LicenseDatabase db, CancellationToken ct) =>
{
    try { return Results.Ok(await db.ChangeServerIpAsync(licenseId, request.ServerIp, ct)); }
    catch (Exception ex) { return Results.BadRequest(new OwnerOperationResponse(false, ex.Message)); }
});
admin.MapPost("/licenses/{licenseId}/reset-machine", async (string licenseId, LicenseDatabase db, CancellationToken ct) =>
{
    try { return Results.Ok(await db.ResetMachineAsync(licenseId, ct)); }
    catch (Exception ex) { return Results.BadRequest(new OwnerOperationResponse(false, ex.Message)); }
});

await app.RunAsync();

static string GetRateLimitKey(HttpContext context)
{
    return GetObservedClientIp(context);
}

static string GetObservedClientIp(HttpContext context)
{
    var remoteAddress = context.Connection.RemoteIpAddress;
    if (remoteAddress is not null && IPAddress.IsLoopback(remoteAddress) &&
        context.Request.Headers.TryGetValue("X-KMT-Client-IP", out var forwarded) &&
        IPAddress.TryParse(forwarded.ToString(), out var forwardedAddress))
    {
        return forwardedAddress.IsIPv4MappedToIPv6
            ? forwardedAddress.MapToIPv4().ToString()
            : forwardedAddress.ToString();
    }

    if (remoteAddress is null)
        return string.Empty;
    return remoteAddress.IsIPv4MappedToIPv6
        ? remoteAddress.MapToIPv4().ToString()
        : remoteAddress.ToString();
}
