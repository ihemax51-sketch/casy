using System.Globalization;
using System.Net;
using System.Security.Cryptography;
using System.Text;
using KMTGuard.Licensing;
using Microsoft.Data.Sqlite;
using Microsoft.Extensions.Options;

namespace KMTGuard.LicenseServer;

public sealed class LicenseDatabase
{
    private readonly string _connectionString;
    private readonly LicenseSigner _signer;
    private readonly int _defaultOfflineHours;
    private readonly SemaphoreSlim _writeLock = new(1, 1);

    public LicenseDatabase(IOptions<LicenseServerOptions> options, LicenseSigner signer)
    {
        var databasePath = LicenseServerOptions.ExpandPath(options.Value.DatabasePath);
        Directory.CreateDirectory(Path.GetDirectoryName(databasePath)!);
        _connectionString = new SqliteConnectionStringBuilder
        {
            DataSource = databasePath,
            Mode = SqliteOpenMode.ReadWriteCreate,
            Cache = SqliteCacheMode.Shared,
            Pooling = true
        }.ToString();
        _signer = signer;
        _defaultOfflineHours = Math.Clamp(options.Value.DefaultOfflineHours, 1, 168);
    }

    public async Task InitializeAsync(CancellationToken cancellationToken = default)
    {
        await using var connection = await OpenAsync(cancellationToken);
        await using var command = connection.CreateCommand();
        command.CommandText = """
            PRAGMA journal_mode=WAL;
            PRAGMA foreign_keys=ON;
            CREATE TABLE IF NOT EXISTS Customers (
                Id TEXT NOT NULL PRIMARY KEY,
                Code TEXT NOT NULL UNIQUE,
                Name TEXT NOT NULL,
                Notes TEXT NOT NULL DEFAULT '',
                CreatedUtc TEXT NOT NULL,
                UpdatedUtc TEXT NOT NULL
            );
            CREATE TABLE IF NOT EXISTS Licenses (
                Id TEXT NOT NULL PRIMARY KEY,
                CustomerId TEXT NOT NULL UNIQUE REFERENCES Customers(Id) ON DELETE CASCADE,
                Status TEXT NOT NULL,
                StartsUtc TEXT NOT NULL,
                ExpiresUtc TEXT NOT NULL,
                Features INTEGER NOT NULL,
                MaximumInstances INTEGER NOT NULL,
                MaximumPlayers INTEGER NOT NULL DEFAULT 3000,
                OfflineHours INTEGER NOT NULL,
                BoundMachineHash TEXT NULL,
                BoundServerIp TEXT NULL,
                BindingMode TEXT NOT NULL DEFAULT 'IP',
                LastSeenUtc TEXT NULL,
                LastVersion TEXT NOT NULL DEFAULT '',
                RevokedUtc TEXT NULL,
                CreatedUtc TEXT NOT NULL,
                UpdatedUtc TEXT NOT NULL
            );
            CREATE TABLE IF NOT EXISTS LicenseCredentials (
                Id TEXT NOT NULL PRIMARY KEY,
                LicenseId TEXT NOT NULL REFERENCES Licenses(Id) ON DELETE CASCADE,
                KeyHash TEXT NOT NULL UNIQUE,
                KeyHint TEXT NOT NULL,
                CreatedUtc TEXT NOT NULL,
                LastUsedUtc TEXT NULL,
                RevokedUtc TEXT NULL
            );
            CREATE INDEX IF NOT EXISTS IX_LicenseCredentials_LicenseId ON LicenseCredentials(LicenseId);
            CREATE TABLE IF NOT EXISTS CredentialPackageBindings (
                CredentialId TEXT NOT NULL PRIMARY KEY REFERENCES LicenseCredentials(Id) ON DELETE CASCADE,
                PackageId TEXT NOT NULL
            );
            CREATE INDEX IF NOT EXISTS IX_CredentialPackageBindings_PackageId
                ON CredentialPackageBindings(PackageId);
            CREATE TABLE IF NOT EXISTS AuditLog (
                Id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                LicenseId TEXT NULL,
                Action TEXT NOT NULL,
                Details TEXT NOT NULL,
                CreatedUtc TEXT NOT NULL
            );
            CREATE TABLE IF NOT EXISTS RuntimeHeartbeats (
                LicenseId TEXT NOT NULL REFERENCES Licenses(Id) ON DELETE CASCADE,
                ServerIp TEXT NOT NULL,
                RuntimeRole TEXT NOT NULL,
                CurrentPlayers INTEGER NOT NULL,
                LastSeenUtc TEXT NOT NULL,
                PRIMARY KEY (LicenseId, ServerIp, RuntimeRole)
            );
            CREATE INDEX IF NOT EXISTS IX_RuntimeHeartbeats_LicenseSeen
                ON RuntimeHeartbeats(LicenseId, LastSeenUtc);
            CREATE TABLE IF NOT EXISTS SystemCounters (
                Name TEXT NOT NULL PRIMARY KEY,
                Value INTEGER NOT NULL
            );
            INSERT INTO SystemCounters(Name, Value)
            VALUES(
                'NextCustomerNumber',
                (SELECT COALESCE(MAX(CAST(SUBSTR(Code, 5) AS INTEGER)), 0) + 1
                 FROM Customers
                 WHERE Code LIKE 'KMT-%'))
            ON CONFLICT(Name) DO UPDATE SET
                Value = CASE
                    WHEN SystemCounters.Value < excluded.Value THEN excluded.Value
                    ELSE SystemCounters.Value
                END;
            """;
        await command.ExecuteNonQueryAsync(cancellationToken);
        await EnsureMaximumPlayersColumnAsync(connection, cancellationToken);
        await EnsureBindingModeColumnAsync(connection, cancellationToken);
        await ExecuteAsync(
            connection,
            null,
            "UPDATE Licenses SET Features = Features | $client WHERE (Features & $filter) != 0;",
            cancellationToken,
            ("$client", (int)LicenseFeature.ClientDll),
            ("$filter", (int)LicenseFeature.Filter));
    }

    public async Task<OwnerDashboardResponse> GetDashboardAsync(CancellationToken cancellationToken)
    {
        await using var connection = await OpenAsync(cancellationToken);
        await using var command = connection.CreateCommand();
        command.CommandText = """
            SELECT
                (SELECT COUNT(*) FROM Customers),
                (SELECT COUNT(*) FROM Licenses WHERE Status = 'Active' AND ExpiresUtc > $now),
                (SELECT COUNT(*) FROM Licenses WHERE Status = 'Active' AND ExpiresUtc > $now AND ExpiresUtc <= $soon),
                (SELECT COUNT(*) FROM Licenses WHERE Status IN ('Suspended', 'Revoked'));
            """;
        var now = DateTimeOffset.UtcNow;
        command.Parameters.AddWithValue("$now", ToDb(now));
        command.Parameters.AddWithValue("$soon", ToDb(now.AddDays(7)));
        await using var reader = await command.ExecuteReaderAsync(cancellationToken);
        await reader.ReadAsync(cancellationToken);
        return new OwnerDashboardResponse(reader.GetInt32(0), reader.GetInt32(1), reader.GetInt32(2), reader.GetInt32(3), now);
    }

    public async Task<IReadOnlyList<OwnerCustomerSummary>> GetCustomersAsync(string? search, CancellationToken cancellationToken)
    {
        await using var connection = await OpenAsync(cancellationToken);
        await using var command = connection.CreateCommand();
        command.CommandText = """
            SELECT c.Id, c.Code, c.Name, c.Notes,
                   l.Id, l.Status, l.StartsUtc, l.ExpiresUtc, l.Features, l.MaximumInstances, l.MaximumPlayers, l.OfflineHours,
                   COALESCE(l.BoundMachineHash, ''), COALESCE(l.BoundServerIp, ''), l.BindingMode, l.LastSeenUtc, l.LastVersion
            FROM Customers c
            INNER JOIN Licenses l ON l.CustomerId = c.Id
            WHERE $search = '' OR c.Name LIKE $like OR c.Code LIKE $like
            ORDER BY c.CreatedUtc DESC;
            """;
        var normalized = (search ?? string.Empty).Trim();
        command.Parameters.AddWithValue("$search", normalized);
        command.Parameters.AddWithValue("$like", $"%{normalized}%");
        var result = new List<OwnerCustomerSummary>();
        await using var reader = await command.ExecuteReaderAsync(cancellationToken);
        while (await reader.ReadAsync(cancellationToken))
            result.Add(ReadSummary(reader));
        return result;
    }

    public async Task<CreateCustomerResponse> CreateCustomerAsync(CreateCustomerRequest request, CancellationToken cancellationToken)
    {
        var name = request.CustomerName.Trim();
        if (name.Length is < 2 or > 120)
            throw new InvalidOperationException("Customer name must contain 2 to 120 characters.");
        if (request.SubscriptionMonths is < 1 or > 120)
            throw new InvalidOperationException("Subscription months must be between 1 and 120.");
        if (request.Features == LicenseFeature.None)
            throw new InvalidOperationException("Select at least one licensed component.");
        if (request.MaximumPlayers is < 1 or > 10000)
            throw new InvalidOperationException("Maximum players must be between 1 and 10,000.");
        var bindingMode = request.BindingMode is LicenseBindingMode.Ip or LicenseBindingMode.PlayerLimit
            ? request.BindingMode
            : throw new InvalidOperationException("Select IP-only or player-limit-only licensing.");
        var maximumInstances = Math.Clamp(request.MaximumInstances, 1, 64);
        var serverIp = bindingMode == LicenseBindingMode.Ip
            ? NormalizeServerIps(request.ServerIp, maximumInstances)
            : string.Empty;

        await _writeLock.WaitAsync(cancellationToken);
        try
        {
            await using var connection = await OpenAsync(cancellationToken);
            await using var transaction = (SqliteTransaction)await connection.BeginTransactionAsync(cancellationToken);
            var customerId = Guid.NewGuid().ToString("N");
            var licenseId = Guid.NewGuid().ToString("N");
            var credentialId = Guid.NewGuid().ToString("N");
            var activationKey = GenerateActivationKey();
            var now = DateTimeOffset.UtcNow;
            var code = await NextCustomerCodeAsync(connection, transaction, cancellationToken);
            var expires = now.AddMonths(request.SubscriptionMonths);
            var offlineHours = Math.Clamp(request.OfflineHours <= 0 ? _defaultOfflineHours : request.OfflineHours, 1, 168);

            await ExecuteAsync(connection, transaction, """
                INSERT INTO Customers(Id, Code, Name, Notes, CreatedUtc, UpdatedUtc)
                VALUES($id, $code, $name, $notes, $now, $now);
                """, cancellationToken,
                ("$id", customerId), ("$code", code), ("$name", name),
                ("$notes", request.Notes?.Trim() ?? string.Empty), ("$now", ToDb(now)));
            await ExecuteAsync(connection, transaction, """
                INSERT INTO Licenses(
                    Id, CustomerId, Status, StartsUtc, ExpiresUtc, Features, MaximumInstances, MaximumPlayers, OfflineHours,
                    BoundServerIp, BindingMode, CreatedUtc, UpdatedUtc)
                VALUES($id, $customer, 'Active', $starts, $expires, $features, $maximum, $players, $offline, NULLIF($ip, ''), $binding, $now, $now);
                """, cancellationToken,
                ("$id", licenseId), ("$customer", customerId), ("$starts", ToDb(now)), ("$expires", ToDb(expires)),
                ("$features", (int)request.Features), ("$maximum", maximumInstances),
                ("$players", request.MaximumPlayers),
                ("$offline", offlineHours), ("$ip", serverIp),
                ("$binding", bindingMode.ToClaimValue()), ("$now", ToDb(now)));
            await InsertCredentialAsync(connection, transaction, credentialId, licenseId, activationKey, now, cancellationToken);
            await InsertAuditAsync(connection, transaction, licenseId, "CustomerCreated", $"Customer {code} created.", now, cancellationToken);
            await transaction.CommitAsync(cancellationToken);

            var summary = new OwnerCustomerSummary(
                customerId, code, name, licenseId, "Active", now, expires, request.Features,
                maximumInstances, request.MaximumPlayers, offlineHours, string.Empty,
                serverIp, bindingMode, null, string.Empty, request.Notes?.Trim() ?? string.Empty);
            var bindingToken = CreatePackageBindingToken(
                licenseId,
                customerId,
                code,
                credentialId,
                serverIp,
                bindingMode,
                request.Features,
                now);
            return new CreateCustomerResponse(summary, activationKey, credentialId, bindingToken);
        }
        finally
        {
            _writeLock.Release();
        }
    }

    public async Task<NewCredentialResponse> CreateCredentialAsync(string licenseId, CancellationToken cancellationToken)
    {
        await _writeLock.WaitAsync(cancellationToken);
        try
        {
            await using var connection = await OpenAsync(cancellationToken);
            var package = await GetPackageBindingRecordAsync(connection, licenseId, cancellationToken);
            if (package.Status.Equals("Revoked", StringComparison.OrdinalIgnoreCase))
                throw new InvalidOperationException("A package cannot be generated for a revoked license.");
            await using var transaction = (SqliteTransaction)await connection.BeginTransactionAsync(cancellationToken);
            var key = GenerateActivationKey();
            var credentialId = Guid.NewGuid().ToString("N");
            var now = DateTimeOffset.UtcNow;
            await InsertCredentialAsync(connection, transaction, credentialId, licenseId, key, now, cancellationToken);
            await InsertAuditAsync(connection, transaction, licenseId, "PackageCredentialCreated", "A new customer package credential was issued.", now, cancellationToken);
            await transaction.CommitAsync(cancellationToken);
            return new NewCredentialResponse(
                licenseId,
                credentialId,
                key,
                CreatePackageBindingToken(
                    licenseId,
                    package.CustomerId,
                    package.CustomerCode,
                    credentialId,
                    package.ServerIp,
                    package.BindingMode,
                    package.Features,
                    now));
        }
        finally
        {
            _writeLock.Release();
        }
    }

    public async Task<ReissuePackageCredentialResponse> ReissuePackageCredentialAsync(
        string licenseId,
        string packageId,
        CancellationToken cancellationToken)
    {
        var normalizedPackageId = (packageId ?? string.Empty).Trim();
        if (normalizedPackageId.Length == 0)
            throw new InvalidOperationException("The existing customer package ID is required.");

        await _writeLock.WaitAsync(cancellationToken);
        try
        {
            await using var connection = await OpenAsync(cancellationToken);
            var package = await GetPackageBindingRecordAsync(connection, licenseId, cancellationToken);
            if (package.Status.Equals("Revoked", StringComparison.OrdinalIgnoreCase))
                throw new InvalidOperationException("A license file cannot be reissued for a revoked license.");

            await using (var lookup = connection.CreateCommand())
            {
                lookup.CommandText = """
                    SELECT COUNT(*)
                    FROM LicenseCredentials credential
                    LEFT JOIN CredentialPackageBindings binding
                        ON binding.CredentialId = credential.Id
                    WHERE credential.LicenseId = $license
                      AND COALESCE(binding.PackageId, credential.Id) = $package
                      AND credential.RevokedUtc IS NULL;
                    """;
                lookup.Parameters.AddWithValue("$license", licenseId);
                lookup.Parameters.AddWithValue("$package", normalizedPackageId);
                var exists = Convert.ToInt32(
                    await lookup.ExecuteScalarAsync(cancellationToken),
                    CultureInfo.InvariantCulture) > 0;
                if (!exists)
                {
                    throw new InvalidOperationException(
                        "The selected binary does not belong to an active package for this customer.");
                }
            }

            await using var transaction = (SqliteTransaction)await connection.BeginTransactionAsync(cancellationToken);
            var credentialId = Guid.NewGuid().ToString("N");
            var activationKey = GenerateActivationKey();
            var now = DateTimeOffset.UtcNow;
            await InsertCredentialAsync(
                connection,
                transaction,
                credentialId,
                licenseId,
                activationKey,
                now,
                cancellationToken);
            await ExecuteAsync(
                connection,
                transaction,
                """
                INSERT INTO CredentialPackageBindings(CredentialId, PackageId)
                VALUES($credential, $package);
                """,
                cancellationToken,
                ("$credential", credentialId),
                ("$package", normalizedPackageId));
            await InsertAuditAsync(
                connection,
                transaction,
                licenseId,
                "ExistingPackageLicenseReissued",
                $"A replacement activation file was issued for existing package {normalizedPackageId}.",
                now,
                cancellationToken);
            await transaction.CommitAsync(cancellationToken);

            return new ReissuePackageCredentialResponse(
                licenseId,
                credentialId,
                normalizedPackageId,
                activationKey);
        }
        finally
        {
            _writeLock.Release();
        }
    }

    public async Task<OwnerOperationResponse> DeleteCustomerAsync(
        string customerId,
        CancellationToken cancellationToken)
    {
        if (string.IsNullOrWhiteSpace(customerId))
            throw new InvalidOperationException("Customer identifier is required.");

        await _writeLock.WaitAsync(cancellationToken);
        try
        {
            await using var connection = await OpenAsync(cancellationToken);
            await using var transaction = (SqliteTransaction)await connection.BeginTransactionAsync(cancellationToken);
            await using var lookup = connection.CreateCommand();
            lookup.Transaction = transaction;
            lookup.CommandText = """
                SELECT c.Code, c.Name, l.Id
                FROM Customers c
                INNER JOIN Licenses l ON l.CustomerId = c.Id
                WHERE c.Id = $customer
                LIMIT 1;
                """;
            lookup.Parameters.AddWithValue("$customer", customerId);

            string customerCode;
            string customerName;
            string licenseId;
            await using (var reader = await lookup.ExecuteReaderAsync(cancellationToken))
            {
                if (!await reader.ReadAsync(cancellationToken))
                    throw new KeyNotFoundException("Customer was not found.");
                customerCode = reader.GetString(0);
                customerName = reader.GetString(1);
                licenseId = reader.GetString(2);
            }

            var now = DateTimeOffset.UtcNow;
            var affected = await ExecuteAsync(
                connection,
                transaction,
                "DELETE FROM Customers WHERE Id=$customer;",
                cancellationToken,
                ("$customer", customerId));
            if (affected != 1)
                throw new InvalidOperationException("Customer could not be deleted.");

            await InsertAuditAsync(
                connection,
                transaction,
                licenseId,
                "CustomerDeleted",
                $"Customer {customerCode} ({customerName}) and all related license credentials were permanently deleted.",
                now,
                cancellationToken);
            await transaction.CommitAsync(cancellationToken);
            return new OwnerOperationResponse(true, $"Customer {customerCode} was permanently deleted.");
        }
        finally
        {
            _writeLock.Release();
        }
    }

    public async Task<OwnerOperationResponse> RenewAsync(string licenseId, int months, CancellationToken cancellationToken)
    {
        if (months is < 1 or > 120)
            throw new InvalidOperationException("Renewal months must be between 1 and 120.");
        await _writeLock.WaitAsync(cancellationToken);
        try
        {
            await using var connection = await OpenAsync(cancellationToken);
            var status = await GetLicenseStatusAsync(connection, licenseId, cancellationToken);
            if (status.Equals("Revoked", StringComparison.OrdinalIgnoreCase))
                throw new InvalidOperationException("A revoked license cannot be renewed or reactivated.");
            var current = await GetLicenseExpiryAsync(connection, licenseId, cancellationToken);
            var now = DateTimeOffset.UtcNow;
            var next = (current > now ? current : now).AddMonths(months);
            await ExecuteAsync(connection, null, """
                UPDATE Licenses SET ExpiresUtc=$expires, UpdatedUtc=$now WHERE Id=$id;
                """, cancellationToken, ("$expires", ToDb(next)), ("$now", ToDb(now)), ("$id", licenseId));
            await InsertAuditAsync(connection, null, licenseId, "Renewed", $"Subscription renewed for {months} month(s).", now, cancellationToken);
            return new OwnerOperationResponse(true, $"Subscription renewed until {next:yyyy-MM-dd}.");
        }
        finally { _writeLock.Release(); }
    }

    public async Task<OwnerOperationResponse> SetStatusAsync(string licenseId, string status, CancellationToken cancellationToken)
    {
        var normalized = status.Trim().ToUpperInvariant() switch
        {
            "ACTIVE" => "Active",
            "SUSPENDED" => "Suspended",
            "REVOKED" => "Revoked",
            _ => throw new InvalidOperationException("Status must be Active, Suspended, or Revoked.")
        };
        await _writeLock.WaitAsync(cancellationToken);
        try
        {
            await using var connection = await OpenAsync(cancellationToken);
            var currentStatus = await GetLicenseStatusAsync(connection, licenseId, cancellationToken);
            if (currentStatus.Equals("Revoked", StringComparison.OrdinalIgnoreCase) &&
                !normalized.Equals("Revoked", StringComparison.OrdinalIgnoreCase))
            {
                throw new InvalidOperationException("A revoked license cannot be reactivated. Create a new license instead.");
            }
            var now = DateTimeOffset.UtcNow;
            var affected = await ExecuteAsync(connection, null, """
                UPDATE Licenses SET Status=$status, RevokedUtc=CASE WHEN $status='Revoked' THEN $now ELSE NULL END, UpdatedUtc=$now WHERE Id=$id;
                """, cancellationToken, ("$status", normalized), ("$now", ToDb(now)), ("$id", licenseId));
            if (affected == 0)
                throw new KeyNotFoundException("License was not found.");
            if (!normalized.Equals("Active", StringComparison.OrdinalIgnoreCase))
            {
                await ExecuteAsync(connection, null,
                    "DELETE FROM RuntimeHeartbeats WHERE LicenseId=$id;",
                    cancellationToken,
                    ("$id", licenseId));
            }
            await InsertAuditAsync(connection, null, licenseId, "StatusChanged", $"Status changed to {normalized}.", now, cancellationToken);
            return new OwnerOperationResponse(true, $"License is now {normalized}.");
        }
        finally { _writeLock.Release(); }
    }

    public async Task<OwnerOperationResponse> ResetMachineAsync(string licenseId, CancellationToken cancellationToken)
    {
        await _writeLock.WaitAsync(cancellationToken);
        try
        {
            await using var connection = await OpenAsync(cancellationToken);
            var now = DateTimeOffset.UtcNow;
            var affected = await ExecuteAsync(connection, null, """
                UPDATE Licenses
                SET BoundMachineHash=NULL, LastSeenUtc=NULL, UpdatedUtc=$now
                WHERE Id=$id;
                """, cancellationToken, ("$now", ToDb(now)), ("$id", licenseId));
            if (affected == 0)
                throw new KeyNotFoundException("License was not found.");
            await InsertAuditAsync(connection, null, licenseId, "MachineReset", "Machine binding was cleared while preserving the license binding mode.", now, cancellationToken);
            return new OwnerOperationResponse(true, "The next successful activation will bind a new Windows machine. The license binding mode was preserved.");
        }
        finally { _writeLock.Release(); }
    }

    public async Task<OwnerOperationResponse> ChangeServerIpAsync(
        string licenseId,
        string serverIp,
        CancellationToken cancellationToken)
    {
        await _writeLock.WaitAsync(cancellationToken);
        try
        {
            await using var connection = await OpenAsync(cancellationToken);
            await using var lookup = connection.CreateCommand();
            lookup.CommandText = "SELECT COALESCE(BoundServerIp, ''), BindingMode, MaximumInstances FROM Licenses WHERE Id=$id LIMIT 1;";
            lookup.Parameters.AddWithValue("$id", licenseId);
            await using var reader = await lookup.ExecuteReaderAsync(cancellationToken);
            if (!await reader.ReadAsync(cancellationToken))
                throw new KeyNotFoundException("License was not found.");
            if (LicenseBindingModeExtensions.Parse(reader.GetString(1)) == LicenseBindingMode.PlayerLimit)
                throw new InvalidOperationException("Player-limit-only licenses do not use a licensed server IP.");

            var currentIp = reader.GetString(0);
            var normalizedIp = NormalizeServerIps(serverIp, reader.GetInt32(2));
            await reader.DisposeAsync();
            if (currentIp.Equals(normalizedIp, StringComparison.OrdinalIgnoreCase))
                return new OwnerOperationResponse(true, $"Server IP is already {normalizedIp}.");

            var now = DateTimeOffset.UtcNow;
            await ExecuteAsync(connection, null, """
                UPDATE Licenses
                SET BoundServerIp=$ip, UpdatedUtc=$now
                WHERE Id=$id;
                """, cancellationToken,
                ("$ip", normalizedIp), ("$now", ToDb(now)), ("$id", licenseId));
            await ExecuteAsync(connection, null,
                "DELETE FROM RuntimeHeartbeats WHERE LicenseId=$id;",
                cancellationToken,
                ("$id", licenseId));
            await InsertAuditAsync(
                connection,
                null,
                licenseId,
                "ServerIpChanged",
                $"Server IP changed from {(string.IsNullOrWhiteSpace(currentIp) ? "unbound" : currentIp)} to {normalizedIp}. HWID binding was preserved.",
                now,
                cancellationToken);

            return new OwnerOperationResponse(
                true,
                $"Licensed server IP list changed to {normalizedIp}.");
        }
        finally { _writeLock.Release(); }
    }

    public async Task<LicenseRefreshResponse> RefreshAsync(
        LicenseRefreshRequest request,
        string observedClientIp,
        CancellationToken cancellationToken)
    {
        var now = DateTimeOffset.UtcNow;

        var keyHash = HashActivationKey(request.ActivationKey);
        await _writeLock.WaitAsync(cancellationToken);
        try
        {
            await using var connection = await OpenAsync(cancellationToken);
            var record = await FindByCredentialAsync(connection, keyHash, cancellationToken);
            if (record is null)
                return Failure("Activation key is invalid.", now);
            if (!record.Status.Equals("Active", StringComparison.OrdinalIgnoreCase))
                return Failure($"This license is {record.Status.ToLowerInvariant()}.", now);
            if (record.ExpiresUtc <= now)
                return Failure("The subscription has expired.", now);
            var machineHash = NormalizeMachineHash(request.MachineHash);
            if (machineHash is null)
                return Failure("The server machine fingerprint is invalid.", now);

            var required = LicenseFeatureExtensions.ParseClaimValue(request.RequiredFeature);
            if (required == LicenseFeature.None || !record.Features.HasFlag(required))
                return Failure("The requested KMTGuard component is not included in this license.", now);
            if (request.CurrentPlayers < 0)
                return Failure("The reported online-player count is invalid.", now);
            if (request.CurrentPlayers > record.MaximumPlayers)
                return Failure(
                    $"Licensed player limit exceeded ({request.CurrentPlayers}/{record.MaximumPlayers}).",
                    now);
            var boundIp = string.Empty;
            if (record.BindingMode == LicenseBindingMode.Ip)
            {
                string requestedServerIp;
                string observedServerIp;
                try
                {
                    requestedServerIp = NormalizeSingleServerIp(request.ServerIp);
                    observedServerIp = NormalizeSingleServerIp(observedClientIp);
                }
                catch
                {
                    return Failure("The server IP could not be verified.", now);
                }

                var licensedServerIps = ServerIpSet.Parse(record.ServerIp, maximum: record.MaximumInstances);
                if (!licensedServerIps.Contains(requestedServerIp, StringComparer.OrdinalIgnoreCase))
                    return Failure("The configured server IP is not one of this license's server slots.", now);
                if (!requestedServerIp.Equals(observedServerIp, StringComparison.OrdinalIgnoreCase))
                    return Failure("The license request did not originate from the licensed server IP.", now);
                boundIp = requestedServerIp;
            }

            var heartbeatIp = string.IsNullOrWhiteSpace(boundIp)
                ? NormalizeHeartbeatIdentity(observedClientIp)
                : boundIp;
            var runtimeRole = NormalizeRuntimeRole(request.RuntimeRole);
            var countedPlayers = runtimeRole is "AGENT" or "ALL" ? request.CurrentPlayers : 0;
            await ExecuteAsync(connection, null,
                "DELETE FROM RuntimeHeartbeats WHERE LicenseId=$id AND LastSeenUtc < $stale;",
                cancellationToken,
                ("$id", record.LicenseId), ("$stale", ToDb(now.AddMinutes(-5))));
            if (runtimeRole.Length > 0)
            {
                if (!await HasHeartbeatForServerAsync(connection, record.LicenseId, heartbeatIp, cancellationToken) &&
                    await CountHeartbeatServersAsync(connection, record.LicenseId, cancellationToken) >= record.MaximumInstances)
                {
                    return Failure(
                        $"Licensed server instance limit exceeded ({record.MaximumInstances}).",
                        now);
                }
                var otherPlayers = await ReadOtherPlayerHeartbeatsAsync(
                    connection, record.LicenseId, heartbeatIp, runtimeRole, cancellationToken);
                if (otherPlayers + countedPlayers > record.MaximumPlayers)
                {
                    return Failure(
                        $"Licensed total player limit exceeded ({otherPlayers + countedPlayers}/{record.MaximumPlayers}).",
                        now);
                }
                await ExecuteAsync(connection, null, """
                    INSERT INTO RuntimeHeartbeats(LicenseId, ServerIp, RuntimeRole, CurrentPlayers, LastSeenUtc)
                    VALUES($license, $ip, $role, $players, $now)
                    ON CONFLICT(LicenseId, ServerIp, RuntimeRole) DO UPDATE SET
                        CurrentPlayers=excluded.CurrentPlayers,
                        LastSeenUtc=excluded.LastSeenUtc;
                    """, cancellationToken,
                    ("$license", record.LicenseId), ("$ip", heartbeatIp), ("$role", runtimeRole),
                    ("$players", countedPlayers), ("$now", ToDb(now)));
            }

            await ExecuteAsync(connection, null, """
                UPDATE Licenses
                SET BoundMachineHash=NULL,
                    BoundServerIp=COALESCE(NULLIF(BoundServerIp, ''), NULLIF($ip, '')),
                    LastSeenUtc=$now, LastVersion=$version, UpdatedUtc=$now
                WHERE Id=$id;
                """, cancellationToken,
                ("$ip", boundIp), ("$now", ToDb(now)),
                ("$version", request.AppVersion?.Trim() ?? string.Empty), ("$id", record.LicenseId));
            await ExecuteAsync(connection, null,
                "UPDATE LicenseCredentials SET LastUsedUtc=$now WHERE Id=$id;", cancellationToken,
                ("$now", ToDb(now)), ("$id", record.CredentialId));

            var leaseExpires = new[] { record.ExpiresUtc, now.AddHours(record.OfflineHours) }.Min();
            var claims = new LicenseClaims
            {
                KeyId = LicenseTokenCodec.TrustedKeyId,
                LicenseId = record.LicenseId,
                CustomerId = record.CustomerId,
                CustomerName = record.CustomerName,
                MachineHash = machineHash,
                IssuedUtc = now,
                NotBeforeUtc = now.AddMinutes(-2),
                SubscriptionExpiresUtc = record.ExpiresUtc,
                LeaseExpiresUtc = leaseExpires,
                Features = record.Features,
                MaximumInstances = record.MaximumInstances,
                MaximumPlayers = record.MaximumPlayers,
                PackageId = record.PackageId,
                ServerIp = boundIp,
                BindingMode = record.BindingMode
            };
            var token = _signer.Sign(claims);
            return new LicenseRefreshResponse(true, "KMTGuard subscription verified.", token, leaseExpires, record.ExpiresUtc, record.CustomerName, now);
        }
        finally { _writeLock.Release(); }
    }

    private async Task<SqliteConnection> OpenAsync(CancellationToken cancellationToken)
    {
        var connection = new SqliteConnection(_connectionString);
        await connection.OpenAsync(cancellationToken);
        return connection;
    }

    private static async Task<string> NextCustomerCodeAsync(SqliteConnection connection, SqliteTransaction transaction, CancellationToken cancellationToken)
    {
        await using var command = connection.CreateCommand();
        command.Transaction = transaction;
        command.CommandText = "SELECT Value FROM SystemCounters WHERE Name='NextCustomerNumber';";
        var next = Convert.ToInt32(await command.ExecuteScalarAsync(cancellationToken), CultureInfo.InvariantCulture);
        await ExecuteAsync(
            connection,
            transaction,
            "UPDATE SystemCounters SET Value=Value+1 WHERE Name='NextCustomerNumber';",
            cancellationToken);
        return $"KMT-{next:0000}";
    }

    private static async Task InsertCredentialAsync(SqliteConnection connection, SqliteTransaction transaction, string id, string licenseId, string key, DateTimeOffset now, CancellationToken cancellationToken)
    {
        await ExecuteAsync(connection, transaction, """
            INSERT INTO LicenseCredentials(Id, LicenseId, KeyHash, KeyHint, CreatedUtc)
            VALUES($id, $license, $hash, $hint, $now);
            """, cancellationToken,
            ("$id", id), ("$license", licenseId), ("$hash", HashActivationKey(key)),
            ("$hint", key[^9..]), ("$now", ToDb(now)));
    }

    private async Task<PackageBindingRecord> GetPackageBindingRecordAsync(
        SqliteConnection connection,
        string licenseId,
        CancellationToken cancellationToken)
    {
        await using var command = connection.CreateCommand();
        command.CommandText = """
            SELECT c.Id, c.Code, l.Status, l.Features, COALESCE(l.BoundServerIp, ''), l.BindingMode
            FROM Licenses l
            INNER JOIN Customers c ON c.Id = l.CustomerId
            WHERE l.Id = $license
            LIMIT 1;
            """;
        command.Parameters.AddWithValue("$license", licenseId);
        await using var reader = await command.ExecuteReaderAsync(cancellationToken);
        if (!await reader.ReadAsync(cancellationToken))
            throw new KeyNotFoundException("License was not found.");

        var bindingMode = LicenseBindingModeExtensions.Parse(reader.GetString(5));
        var serverIp = bindingMode == LicenseBindingMode.Ip
            ? NormalizeServerIps(reader.GetString(4), 64)
            : string.Empty;
        return new PackageBindingRecord(
            reader.GetString(0),
            reader.GetString(1),
            reader.GetString(2),
            (LicenseFeature)reader.GetInt32(3),
            serverIp,
            bindingMode);
    }

    private string CreatePackageBindingToken(
        string licenseId,
        string customerId,
        string customerCode,
        string packageId,
        string serverIp,
        LicenseBindingMode bindingMode,
        LicenseFeature features,
        DateTimeOffset issuedUtc) =>
        _signer.Sign(new PackageBindingClaims
        {
            KeyId = LicenseTokenCodec.TrustedKeyId,
            LicenseId = licenseId,
            CustomerId = customerId,
            CustomerCode = customerCode,
            PackageId = packageId,
            ServerIp = bindingMode == LicenseBindingMode.Ip
                ? NormalizeServerIps(serverIp, 64)
                : string.Empty,
            BindingMode = bindingMode,
            Features = features,
            IssuedUtc = issuedUtc,
            Watermark = Convert.ToHexString(RandomNumberGenerator.GetBytes(16))
        });

    private static async Task<ActivationRecord?> FindByCredentialAsync(SqliteConnection connection, string keyHash, CancellationToken cancellationToken)
    {
        await using var command = connection.CreateCommand();
        command.CommandText = """
            SELECT cr.Id, COALESCE(binding.PackageId, cr.Id), l.Id, c.Id, c.Name, l.Status,
                   l.ExpiresUtc, l.Features, l.MaximumInstances,
                   l.MaximumPlayers, l.OfflineHours, COALESCE(l.BoundMachineHash, ''), COALESCE(l.BoundServerIp, ''),
                   l.BindingMode
            FROM LicenseCredentials cr
            LEFT JOIN CredentialPackageBindings binding ON binding.CredentialId = cr.Id
            INNER JOIN Licenses l ON l.Id=cr.LicenseId
            INNER JOIN Customers c ON c.Id=l.CustomerId
            WHERE cr.KeyHash=$hash AND cr.RevokedUtc IS NULL
            LIMIT 1;
            """;
        command.Parameters.AddWithValue("$hash", keyHash);
        await using var reader = await command.ExecuteReaderAsync(cancellationToken);
        if (!await reader.ReadAsync(cancellationToken))
            return null;
        return new ActivationRecord(
            reader.GetString(0), reader.GetString(1), reader.GetString(2), reader.GetString(3),
            reader.GetString(4), reader.GetString(5), FromDb(reader.GetString(6)),
            (LicenseFeature)reader.GetInt32(7), reader.GetInt32(8), reader.GetInt32(9),
            reader.GetInt32(10), reader.GetString(11), reader.GetString(12),
            LicenseBindingModeExtensions.Parse(reader.GetString(13)));
    }

    public async Task<UpdateAuthorizationResult> AuthorizeUpdateAsync(
        UpdateCheckRequest request,
        string observedClientIp,
        CancellationToken cancellationToken)
    {
        var refresh = await RefreshAsync(
            new LicenseRefreshRequest(
                request.ActivationKey,
                request.MachineHash,
                request.ServerIp,
                request.CurrentVersion,
                LicenseFeature.Filter.ToClaimValue(),
                0,
                "UPDATER"),
            observedClientIp,
            cancellationToken);
        if (!refresh.Success)
        {
            return new UpdateAuthorizationResult(
                false,
                refresh.Message,
                LicenseFeature.None,
                null);
        }
        if (!LicenseTokenCodec.TryVerify(refresh.LeaseToken, out var claims, out var error) ||
            claims is null)
        {
            return new UpdateAuthorizationResult(
                false,
                error,
                LicenseFeature.None,
                null);
        }

        await using var connection = await OpenAsync(cancellationToken);
        await using var command = connection.CreateCommand();
        command.CommandText = """
            SELECT Code
            FROM Customers
            WHERE Id=$customer
            LIMIT 1;
            """;
        command.Parameters.AddWithValue("$customer", claims.CustomerId);
        var customerCode = Convert.ToString(
            await command.ExecuteScalarAsync(cancellationToken),
            CultureInfo.InvariantCulture);
        if (string.IsNullOrWhiteSpace(customerCode))
        {
            return new UpdateAuthorizationResult(
                false,
                "The licensed customer record was not found.",
                LicenseFeature.None,
                null);
        }

        var binding = CreatePackageBindingToken(
            claims.LicenseId,
            claims.CustomerId,
            customerCode,
            claims.PackageId,
            claims.ServerIp,
            claims.BindingMode,
            claims.Features,
            DateTimeOffset.UtcNow);
        return new UpdateAuthorizationResult(
            true,
            "Update access authorized.",
            claims.Features,
            binding);
    }

    private static OwnerCustomerSummary ReadSummary(SqliteDataReader reader)
    {
        var machine = reader.GetString(12);
        var display = machine.Length >= 16
            ? $"{machine[..4]}-{machine.Substring(4, 4)}-{machine.Substring(8, 4)}-{machine.Substring(12, 4)}"
            : machine;
        return new OwnerCustomerSummary(
            reader.GetString(0), reader.GetString(1), reader.GetString(2), reader.GetString(4), reader.GetString(5),
            FromDb(reader.GetString(6)), FromDb(reader.GetString(7)), (LicenseFeature)reader.GetInt32(8),
            reader.GetInt32(9), reader.GetInt32(10), reader.GetInt32(11), display, reader.GetString(13),
            LicenseBindingModeExtensions.Parse(reader.GetString(14)),
            reader.IsDBNull(15) ? null : FromDb(reader.GetString(15)), reader.GetString(16), reader.GetString(3));
    }

    private static async Task<int> ExecuteAsync(SqliteConnection connection, SqliteTransaction? transaction, string sql, CancellationToken cancellationToken, params (string Name, object? Value)[] parameters)
    {
        await using var command = connection.CreateCommand();
        command.Transaction = transaction;
        command.CommandText = sql;
        foreach (var (name, value) in parameters)
            command.Parameters.AddWithValue(name, value ?? DBNull.Value);
        return await command.ExecuteNonQueryAsync(cancellationToken);
    }

    private static async Task EnsureMaximumPlayersColumnAsync(SqliteConnection connection, CancellationToken cancellationToken)
    {
        await using var lookup = connection.CreateCommand();
        lookup.CommandText = "PRAGMA table_info(Licenses);";
        await using var reader = await lookup.ExecuteReaderAsync(cancellationToken);
        var exists = false;
        while (await reader.ReadAsync(cancellationToken))
        {
            if (reader.GetString(1).Equals("MaximumPlayers", StringComparison.OrdinalIgnoreCase))
            {
                exists = true;
                break;
        }
    }

        await reader.DisposeAsync();
        if (!exists)
            await ExecuteAsync(connection, null,
                "ALTER TABLE Licenses ADD COLUMN MaximumPlayers INTEGER NOT NULL DEFAULT 3000;",
                cancellationToken);
    }

    private static async Task EnsureBindingModeColumnAsync(
        SqliteConnection connection,
        CancellationToken cancellationToken)
    {
        await using var lookup = connection.CreateCommand();
        lookup.CommandText = "PRAGMA table_info(Licenses);";
        await using var reader = await lookup.ExecuteReaderAsync(cancellationToken);
        var exists = false;
        while (await reader.ReadAsync(cancellationToken))
        {
            if (reader.GetString(1).Equals("BindingMode", StringComparison.OrdinalIgnoreCase))
            {
                exists = true;
                break;
            }
        }

        await reader.DisposeAsync();
        if (!exists)
            await ExecuteAsync(connection, null,
                "ALTER TABLE Licenses ADD COLUMN BindingMode TEXT NOT NULL DEFAULT 'IP';",
                cancellationToken);
    }

    private static async Task InsertAuditAsync(SqliteConnection connection, SqliteTransaction? transaction, string licenseId, string action, string details, DateTimeOffset now, CancellationToken cancellationToken) =>
        _ = await ExecuteAsync(connection, transaction,
            "INSERT INTO AuditLog(LicenseId, Action, Details, CreatedUtc) VALUES($license, $action, $details, $now);",
            cancellationToken, ("$license", licenseId), ("$action", action), ("$details", details), ("$now", ToDb(now)));

    private static async Task EnsureLicenseExistsAsync(SqliteConnection connection, string licenseId, CancellationToken cancellationToken)
    {
        await using var command = connection.CreateCommand();
        command.CommandText = "SELECT COUNT(*) FROM Licenses WHERE Id=$id;";
        command.Parameters.AddWithValue("$id", licenseId);
        if (Convert.ToInt32(await command.ExecuteScalarAsync(cancellationToken), CultureInfo.InvariantCulture) == 0)
            throw new KeyNotFoundException("License was not found.");
    }

    private static async Task<DateTimeOffset> GetLicenseExpiryAsync(SqliteConnection connection, string licenseId, CancellationToken cancellationToken)
    {
        await using var command = connection.CreateCommand();
        command.CommandText = "SELECT ExpiresUtc FROM Licenses WHERE Id=$id;";
        command.Parameters.AddWithValue("$id", licenseId);
        var value = await command.ExecuteScalarAsync(cancellationToken) as string;
        return value is null ? throw new KeyNotFoundException("License was not found.") : FromDb(value);
    }

    private static async Task<string> GetLicenseStatusAsync(SqliteConnection connection, string licenseId, CancellationToken cancellationToken)
    {
        await using var command = connection.CreateCommand();
        command.CommandText = "SELECT Status FROM Licenses WHERE Id=$id;";
        command.Parameters.AddWithValue("$id", licenseId);
        return await command.ExecuteScalarAsync(cancellationToken) as string
               ?? throw new KeyNotFoundException("License was not found.");
    }

    private static string GenerateActivationKey()
    {
        var hex = Convert.ToHexString(RandomNumberGenerator.GetBytes(20));
        return "KMT-" + string.Join('-', Enumerable.Range(0, 8).Select(index => hex.Substring(index * 5, 5)));
    }

    private static string HashActivationKey(string key) =>
        Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes(key.Trim().ToUpperInvariant())));

    private static string NormalizeServerIps(string? value, int maximumInstances)
    {
        var addresses = ServerIpSet.Parse(value, maximum: maximumInstances);
        if (addresses.Count > maximumInstances)
            throw new InvalidOperationException("The number of server IPs exceeds the licensed server instance count.");
        return string.Join(',', addresses);
    }

    private static string NormalizeSingleServerIp(string? value) =>
        ServerIpSet.Parse(value, maximum: 1).Single();

    private static string NormalizeHeartbeatIdentity(string? value)
    {
        if (IPAddress.TryParse(value?.Trim(), out var address))
            return address.ToString();
        return "UNBOUND";
    }

    private static string NormalizeRuntimeRole(string? value)
    {
        var role = (value ?? string.Empty).Trim().ToUpperInvariant();
        return role is "AGENT" or "GATEWAY" or "DOWNLOAD" or "DESKTOP" or "ALL"
            ? role
            : string.Empty;
    }

    private static string? NormalizeMachineHash(string? value)
    {
        var normalized = (value ?? string.Empty).Trim().ToUpperInvariant();
        return normalized.Length == 64 && normalized.All(Uri.IsHexDigit)
            ? normalized
            : null;
    }

    private static async Task<int> ReadOtherPlayerHeartbeatsAsync(
        SqliteConnection connection,
        string licenseId,
        string serverIp,
        string runtimeRole,
        CancellationToken cancellationToken)
    {
        await using var command = connection.CreateCommand();
        command.CommandText = """
            SELECT COALESCE(SUM(CurrentPlayers), 0)
            FROM RuntimeHeartbeats
            WHERE LicenseId=$license
              AND RuntimeRole IN ('AGENT', 'ALL')
              AND NOT (ServerIp=$ip AND RuntimeRole=$role);
            """;
        command.Parameters.AddWithValue("$license", licenseId);
        command.Parameters.AddWithValue("$ip", serverIp);
        command.Parameters.AddWithValue("$role", runtimeRole);
        return Convert.ToInt32(await command.ExecuteScalarAsync(cancellationToken), CultureInfo.InvariantCulture);
    }

    private static async Task<bool> HasHeartbeatForServerAsync(
        SqliteConnection connection,
        string licenseId,
        string serverIp,
        CancellationToken cancellationToken)
    {
        await using var command = connection.CreateCommand();
        command.CommandText = """
            SELECT COUNT(*)
            FROM RuntimeHeartbeats
            WHERE LicenseId=$license AND ServerIp=$ip;
            """;
        command.Parameters.AddWithValue("$license", licenseId);
        command.Parameters.AddWithValue("$ip", serverIp);
        return Convert.ToInt32(await command.ExecuteScalarAsync(cancellationToken), CultureInfo.InvariantCulture) > 0;
    }

    private static async Task<int> CountHeartbeatServersAsync(
        SqliteConnection connection,
        string licenseId,
        CancellationToken cancellationToken)
    {
        await using var command = connection.CreateCommand();
        command.CommandText = "SELECT COUNT(DISTINCT ServerIp) FROM RuntimeHeartbeats WHERE LicenseId=$license;";
        command.Parameters.AddWithValue("$license", licenseId);
        return Convert.ToInt32(await command.ExecuteScalarAsync(cancellationToken), CultureInfo.InvariantCulture);
    }

    private static string ToDb(DateTimeOffset value) => value.UtcDateTime.ToString("O", CultureInfo.InvariantCulture);
    private static DateTimeOffset FromDb(string value) => DateTimeOffset.Parse(value, CultureInfo.InvariantCulture, DateTimeStyles.AssumeUniversal);
    private static LicenseRefreshResponse Failure(string message, DateTimeOffset now) => new(false, message, null, null, null, null, now);

    private sealed record ActivationRecord(
        string CredentialId,
        string PackageId,
        string LicenseId,
        string CustomerId,
        string CustomerName,
        string Status,
        DateTimeOffset ExpiresUtc,
        LicenseFeature Features,
        int MaximumInstances,
        int MaximumPlayers,
        int OfflineHours,
        string MachineHash,
        string ServerIp,
        LicenseBindingMode BindingMode);

    private sealed record PackageBindingRecord(
        string CustomerId,
        string CustomerCode,
        string Status,
        LicenseFeature Features,
        string ServerIp,
        LicenseBindingMode BindingMode);

    public sealed record UpdateAuthorizationResult(
        bool Success,
        string Message,
        LicenseFeature Features,
        string? PackageBindingToken);
}
