param(
    [string]$SourceRoot = "D:\KMTGuard-build\Licensing",
    [string]$InstallRoot = "C:\Program Files\KMTGuard\Licensing",
    [string]$ServiceName = "KMTGuardLicenseServer"
)

$ErrorActionPreference = "Stop"
Set-StrictMode -Version Latest

$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
$principal = [Security.Principal.WindowsPrincipal]::new($identity)
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw "Run this installer from an elevated PowerShell window."
}

$serverSource = Join-Path $SourceRoot "LicenseServer"
$centerSource = Join-Path $SourceRoot "LicenseCenter"
$serverExecutableSource = Join-Path $serverSource "KMTGuard.LicenseServer.exe"
$centerExecutableSource = Join-Path $centerSource "KMTGuard.LicenseAdmin.exe"
$privateKey = Join-Path $env:ProgramData "KMTGuardLicensing\Secrets\license-signing-key.pk8"
$adminToken = Join-Path $env:ProgramData "KMTGuardLicensing\Secrets\admin-api-token.txt"
foreach ($required in @($serverExecutableSource, $centerExecutableSource, $privateKey, $adminToken)) {
    if (-not (Test-Path -LiteralPath $required -PathType Leaf)) {
        throw "Required licensing file is missing: $required"
    }
}

$service = Get-Service -Name $ServiceName -ErrorAction SilentlyContinue
if ($null -ne $service -and $service.Status -ne 'Stopped') {
    Stop-Service -Name $ServiceName -Force
    $service.WaitForStatus('Stopped', [TimeSpan]::FromSeconds(30))
}

$serverInstall = Join-Path $InstallRoot "LicenseServer"
$centerInstall = Join-Path $InstallRoot "LicenseCenter"
New-Item -ItemType Directory -Path $serverInstall, $centerInstall -Force | Out-Null
Copy-Item -Path (Join-Path $serverSource '*') -Destination $serverInstall -Recurse -Force
Copy-Item -Path (Join-Path $centerSource '*') -Destination $centerInstall -Recurse -Force

$serverExecutable = Join-Path $serverInstall "KMTGuard.LicenseServer.exe"
$binaryPath = '"' + $serverExecutable + '"'
if ($null -eq $service) {
    New-Service -Name $ServiceName `
        -BinaryPathName $binaryPath `
        -DisplayName "KMTGuard License Server" `
        -Description "Issues and refreshes signed KMTGuard customer license leases." `
        -StartupType Automatic | Out-Null
}
else {
    $serviceInstance = Get-CimInstance Win32_Service -Filter "Name='$ServiceName'"
    $changeResult = Invoke-CimMethod -InputObject $serviceInstance -MethodName Change -Arguments @{
        PathName = $binaryPath
        StartMode = "Automatic"
    }
    if ($changeResult.ReturnValue -ne 0) {
        throw "Could not update the KMTGuard License Server service."
    }
}

& sc.exe failure $ServiceName "reset= 86400" "actions= restart/5000/restart/15000/restart/30000" | Out-Null
& sc.exe failureflag $ServiceName 1 | Out-Null

$shortcutPath = Join-Path ([Environment]::GetFolderPath('CommonDesktopDirectory')) "KMTGuard License Center.lnk"
$shell = New-Object -ComObject WScript.Shell
$shortcut = $shell.CreateShortcut($shortcutPath)
$shortcut.TargetPath = Join-Path $centerInstall "KMTGuard.LicenseAdmin.exe"
$shortcut.WorkingDirectory = $centerInstall
$shortcut.Description = "KMTGuard customer and subscription management"
$shortcut.Save()

Start-Service -Name $ServiceName
$deadline = (Get-Date).AddSeconds(30)
do {
    Start-Sleep -Milliseconds 500
    try { $health = Invoke-RestMethod -Uri "http://127.0.0.1:5127/health" -TimeoutSec 2 } catch { $health = $null }
} while ($null -eq $health -and (Get-Date) -lt $deadline)
if ($null -eq $health -or $health.status -ne 'healthy') {
    throw "The KMTGuard License Server service did not pass its health check."
}

Write-Host "KMTGuard License Server service and License Center are installed." -ForegroundColor Green
Write-Host "License Center shortcut: $shortcutPath"
