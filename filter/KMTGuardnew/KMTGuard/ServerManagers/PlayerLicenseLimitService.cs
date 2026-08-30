using System.Collections.Concurrent;
using KMTGuard.Database;
using KMTGuard.Database.Models;
using KMTGuard.Helpers;
using KMTGuard.LicensingRuntime;
using KMTGuard.SessionManager;
using Microsoft.Data.SqlClient;
using Serilog;

namespace KMTGuard.ServerManagers;

public static class PlayerLicenseLimitService
{
#if KMT_DEVELOPMENT_BUILD
    public static Task<bool> IsGatewayAdmissionAllowedAsync(
        Guid sessionId,
        CancellationToken cancellationToken = default) =>
        Task.FromResult(true);

    public static bool TryAcquireAgentAdmission(ISession session) => true;

    public static bool TryConfirmGameReady(ISession session) => true;

    public static void ReleaseAgentAdmission(Guid sessionId)
    {
    }

    public static void EnforceCurrentLimit()
    {
    }
#else
    private static readonly TimeSpan AgentAdmissionLifetime = TimeSpan.FromMinutes(2);
    private static readonly SemaphoreSlim GatewayLock = new(1, 1);
    private static readonly object AgentLock = new();
    private static readonly ConcurrentDictionary<Guid, DateTime> AgentAdmissions = new();

    public static async Task<bool> IsGatewayAdmissionAllowedAsync(Guid sessionId, CancellationToken cancellationToken = default)
    {
        if (!LicenseRuntime.IsAdmissionAuthorized)
        {
            Log.Error("Gateway admission denied closed because the signed player-limit heartbeat is stale or invalid.");
            return false;
        }

        await GatewayLock.WaitAsync(cancellationToken);
        try
        {
            var online = await ReadDatabaseOnlineCountAsync(cancellationToken);
            var maximum = LicenseRuntime.MaximumPlayers;
            // The LoginServer has already accepted this session when this check runs,
            // so _ShardCurrentUser may already include it. Reject only an actual excess;
            // the Agent admission lock remains the authoritative race-free gate.
            if (online > maximum)
            {
                Log.Warning("Licensed player limit rejected Gateway admission. Online={Online}, Limit={Limit}, Session={SessionId}",
                    online, maximum, sessionId);
                return false;
            }

            return true;
        }
        catch (Exception ex)
        {
            Log.Error(ex, "Could not verify the licensed player limit at Gateway; login denied closed.");
            return false;
        }
        finally
        {
            GatewayLock.Release();
        }
    }

    public static bool TryAcquireAgentAdmission(ISession session)
    {
        if (!LicenseRuntime.IsAdmissionAuthorized)
        {
            Log.Error("Agent admission denied closed because the signed player-limit heartbeat is stale or invalid.");
            return false;
        }

        lock (AgentLock)
        {
            var now = DateTime.UtcNow;
            RemoveExpired(AgentAdmissions, now);
            if (AgentAdmissions.ContainsKey(session.ClientGuid))
                return true;

            var online = ServerManager.AgentSessions.CountWhere(ServerManager.IsUpstreamOccupiedPlayer);
            var maximum = LicenseRuntime.MaximumPlayers;
            if (online + AgentAdmissions.Count >= maximum)
            {
                Log.Warning("Licensed player limit rejected Agent admission for {User}. Online={Online}, Pending={Pending}, Limit={Limit}",
                    session.PlayerUserID, online, AgentAdmissions.Count, maximum);
                return false;
            }

            AgentAdmissions[session.ClientGuid] = now.Add(AgentAdmissionLifetime);
            return true;
        }
    }

    public static bool TryConfirmGameReady(ISession session)
    {
        if (!LicenseRuntime.IsAdmissionAuthorized)
        {
            Log.Error("Game Ready denied closed because the signed player-limit heartbeat is stale or invalid.");
            return false;
        }

        lock (AgentLock)
        {
            AgentAdmissions.TryRemove(session.ClientGuid, out _);
            var onlineWithoutCurrent = ServerManager.AgentSessions.CountWhere(candidate =>
                candidate.ClientGuid != session.ClientGuid && ServerManager.IsUpstreamOccupiedPlayer(candidate));
            var maximum = LicenseRuntime.MaximumPlayers;
            if (onlineWithoutCurrent >= maximum)
            {
                Log.Warning("Licensed player limit rejected Game Ready for {User}. Online={Online}, Limit={Limit}",
                    session.PlayerUserID, onlineWithoutCurrent, maximum);
                return false;
            }

            return true;
        }
    }

    public static void ReleaseAgentAdmission(Guid sessionId) => AgentAdmissions.TryRemove(sessionId, out _);

    public static void EnforceCurrentLimit()
    {
        var maximum = LicenseRuntime.MaximumPlayers;
        var online = ServerManager.AgentSessions
            .Where(ServerManager.IsUpstreamOccupiedPlayer)
            .OrderBy(session => session.SessionData.CharacterReadyAtUtc)
            .ToArray();
        if (online.Length <= maximum)
            return;

        foreach (var session in online.Skip(maximum))
            session.Stop($"signed player limit reduced to {maximum}");

        Log.Warning("Signed player limit enforcement disconnected {Count} excess session(s). Limit={Limit}",
            online.Length - maximum, maximum);
    }

    private static async Task<int> ReadDatabaseOnlineCountAsync(CancellationToken cancellationToken)
    {
        var accountDatabase = SqlIdentifier.Quote(_serverSettings.AccountDB);
        await using var connection = new SqlConnection(Program.Connectionstring);
        await connection.OpenAsync(cancellationToken);
        await using var command = new SqlCommand(
            $"""
            ;WITH LatestShardCounts AS
            (
                SELECT
                    nShardID,
                    nUserCount,
                    dLogDate,
                    ROW_NUMBER() OVER
                    (
                        PARTITION BY nShardID
                        ORDER BY dLogDate DESC, nID DESC
                    ) AS RowNumber
                FROM {accountDatabase}.._ShardCurrentUser WITH (NOLOCK)
            )
            SELECT COALESCE(SUM(CONVERT(bigint, nUserCount)), 0)
            FROM LatestShardCounts
            WHERE RowNumber = 1
              AND dLogDate >= DATEADD(MINUTE, -10, GETDATE());
            """,
            connection);
        var value = await command.ExecuteScalarAsync(cancellationToken);
        return checked((int)Math.Max(0L, Convert.ToInt64(value)));
    }

    private static void RemoveExpired(ConcurrentDictionary<Guid, DateTime> entries, DateTime now)
    {
        foreach (var entry in entries)
        {
            if (entry.Value <= now)
                entries.TryRemove(entry.Key, out _);
        }
    }
#endif
}
