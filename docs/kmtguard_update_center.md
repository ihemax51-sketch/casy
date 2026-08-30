# KMTGuard Update Center

KMTGuard 2 includes a licensed customer update channel. Existing customers need
the v2 package once; later Filter releases are discovered automatically when the
production Desktop Dashboard starts.

## Owner workflow

1. Run `scripts\Publish-KmtGuardDeveloper.ps1`.
2. Open KMTGuard License Center on the owner VPS.
3. Open **Updates**.
4. Enter a semantic version, customer-facing title and release notes.
5. Select only the components that changed: Filter, Client DLL, GameServer DLL,
   ShardManager DLL, database, or media.
6. Select **Publish update**.

Published content is read from
`D:\KMTGuard-build\CustomerProductionBase`. Customer settings, cached leases,
activation files, logs, symbols and updater preferences are excluded.

The update store defaults to:

```text
%ProgramData%\KMTGuardLicensing\Updates
```

The License Center and License Server `UpdateRoot` settings must resolve to the
same directory.

## Customer workflow

The production Desktop Dashboard starts `KMTGuard.Updater.exe
--background-check`. The Update Center stays closed when the installed version
is current and opens when a newer licensed release is available.

After download, it verifies the ZIP and every extracted file, then embeds the
signed identity of the customer's existing package in the new protected DLLs.
The customer does not receive a new activation key and does not reactivate.

When the release contains Filter files, installation:

- Stops the KMTGuard Desktop Dashboard and Filter workers.
- Creates a rollback copy and retains only the latest Filter backup.
- Replaces only delivered Filter files.
- Preserves `Settings.json`, `KMTGuard-License.txt`, logs, downloaded updates,
  and updater preferences.
- Restores backed-up files when replacement fails.
- Updates the Update Center itself through a temporary replacement process
  outside the Filter folder.
- Restarts the Desktop Dashboard.

After successful completion, extracted Filter and updater payloads are removed.
Only selected manual delivery folders remain under the release version. A
DLL-only release therefore contains no unchanged Filter files or unselected
DLLs.

DLL delivery is intentionally manual:

```text
Filter\Updates\<version>\New DLL\Client\KMTGuardKit.dll
Filter\Updates\<version>\New DLL\GameServer\KMTGuard_GameServer.dll
Filter\Updates\<version>\New DLL\ShardManager\KMTGuard_ShardManager.dll
```

Stop the related client/server process, copy the DLL to its installed folder,
then use **Verify DLLs**. The Update Center never replaces these DLLs
automatically.

Database and media payloads, when selected by the owner, are also delivery-only:

```text
Filter\Updates\<version>\New SQL
Filter\Updates\<version>\New Media
```

They are not applied automatically.

## Public routes

The public bridge exposes only:

```text
GET  /health
POST /api/v1/license/refresh
POST /api/v1/update/check
POST /api/v1/update/download/<semantic-version>
```

Owner APIs remain available only on the local admin port.
