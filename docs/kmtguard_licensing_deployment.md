# KMTGuard Licensing Deployment

## Production layout

- Public customer URL: `https://license.play-casy.online`
- Local public backend: `http://127.0.0.1:5127`
- Local owner API: `http://127.0.0.1:5128`
- License data: `%ProgramData%\KMTGuardLicensing\Data\licenses.db`
- Private signing key: `%ProgramData%\KMTGuardLicensing\Secrets\license-signing-key.pk8`
- Owner API token: `%ProgramData%\KMTGuardLicensing\Secrets\admin-api-token.txt`

The signing key and owner token must never be copied into `D:\KMTGuard-build\Customers` or sent to a customer. Customer packages contain only an activation credential and the public RSA verification key embedded in the binaries.

## Build and install

Run from an elevated PowerShell window:

```powershell
Set-ExecutionPolicy -Scope Process Bypass
cd D:\Filter-Venom
.\scripts\Publish-KmtGuardRelease.ps1
.\scripts\Install-KmtGuardLicensing.ps1
.\scripts\Install-KmtGuardLicenseProxy.ps1
```

The installer creates the automatic Windows service `KMTGuardLicenseServer` and a public desktop shortcut named `KMTGuard License Center`.

## Cloudflare DNS

Create this record in the `play-casy.online` zone:

| Type | Name | Target | Proxy | TTL |
| --- | --- | --- | --- | --- |
| A | `license` | `54.37.205.126` | Proxied | Auto |

The existing `play-casy.online` website is not changed. Apache routes only the
`license.play-casy.online` host to the local license bridge. The bridge accepts
`GET /health`, `POST /api/v1/license/refresh`, and the public licensed updater
check/download routes. Owner endpoints remain restricted to local port `5128`.

Cloudflare SSL mode `Full` works with the current Laragon origin certificate. For the strongest origin validation, install a Cloudflare Origin CA or public certificate for `license.play-casy.online`, update the Apache virtual host certificate paths, and switch the zone to `Full (strict)`.

After DNS propagation, verify:

```powershell
Invoke-RestMethod https://license.play-casy.online/health
```

## Customer workflow

Before opening the License Center after source changes, publish both build flavors:

```powershell
cd D:\kmt-source
.\scripts\Publish-KmtGuardDeveloper.ps1
```

This single command refreshes the local Developer/Test delivery in `D:\KMTGuard-build`
and the licensed customer binaries in `D:\KMTGuard-build\CustomerProductionBase`.
The License Center never puts a Developer/Test binary in a customer package.

The complete customer media source is fixed at
`D:\KMTGuard-build\Media-KemtGuard`. Its `Client-import` and `Media` folders are
copied as-is into every customer package. The generated legacy
`D:\KMTGuard-build\Media` tree is not merged into customer packages.

1. Open `KMTGuard License Center` on the owner VPS.
2. Select **New Customer**, enter the customer name, subscription length, licensed components, total player limit, server-slot count, and one public IPv4 address per server.
3. The panel creates a versioned folder and ZIP under `D:\KMTGuard-build\Customers`.
4. Send only that generated customer ZIP.
5. On every licensed server, set `ServerIP` to that server's own listed public IP and open `Filter\KMTGuard.exe` once before starting GameServer or ShardManager.
6. Use the panel to renew, suspend, revoke, create a new package credential, or reset the machine binding.

The package generator removes the owner's database username, password, and database address from the customer's `Settings.json`. It writes the signed licensed server IP into `ServerIP` before creating checksums and the ZIP.

## Backup and recovery

Create a stopped-service snapshot with:

```powershell
.\scripts\Backup-KmtGuardLicensing.ps1
```

The backup contains the SQLite database and private signing key. Store it on an encrypted offline disk with access limited to the owner. Losing the private key prevents the server from issuing leases that existing customer binaries trust. Restoring a different key requires rebuilding and redistributing every licensed binary.

## Security boundaries

- The service listens on loopback only; Windows Firewall does not expose ports `5127` or `5128`.
- Owner operations require both local port `5128` and a 256-bit owner token.
- Customer leases are RSA-signed, bound to the requesting Windows server for their short lifetime, and cannot be copied to another machine.
- Every activation and refresh must match one configured server slot, the signed IP claim, and the source IP observed by the public license proxy.
- Active server slots are tracked centrally. Starting more distinct server IPs than the licensed count is rejected.
- Player heartbeats from all active Agent roles are aggregated centrally and checked against one shared customer player limit.
- Filter and License Center also verify that the licensed IPv4 address belongs to the current server through a local NIC or its detected public IP.
- Filter workers refresh periodically and stop after a definitive suspend or revoke response.
- GameServer and ShardManager validate the same signed lease and their signed customer-package binding before initialization, then continue monitoring it.
- The client DLL validates its signed customer-package binding before installing client hooks.
- A temporary network outage uses the signed offline lease until its configured lease expiry; it does not bypass subscription expiry.
