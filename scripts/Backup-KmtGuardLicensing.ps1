param(
    [string]$DestinationRoot = "D:\KMTGuard-Licensing-Backups",
    [string]$ServiceName = "KMTGuardLicenseServer"
)

$ErrorActionPreference = "Stop"
Set-StrictMode -Version Latest

$sourceRoot = Join-Path $env:ProgramData "KMTGuardLicensing"
if (-not (Test-Path -LiteralPath $sourceRoot -PathType Container)) {
    throw "KMTGuard licensing data was not found: $sourceRoot"
}

$backup = Join-Path $DestinationRoot (Get-Date -Format "yyyyMMdd-HHmmss")
New-Item -ItemType Directory -Path $backup -Force | Out-Null
$service = Get-Service -Name $ServiceName -ErrorAction SilentlyContinue
$restart = $null -ne $service -and $service.Status -eq 'Running'
try {
    if ($restart) {
        Stop-Service -Name $ServiceName -Force
        $service.WaitForStatus('Stopped', [TimeSpan]::FromSeconds(30))
    }
    Copy-Item -LiteralPath $sourceRoot -Destination $backup -Recurse -Force
}
finally {
    if ($restart) {
        Start-Service -Name $ServiceName
    }
}

& icacls.exe $backup /inheritance:r /grant:r "SYSTEM:(OI)(CI)F" "Administrators:(OI)(CI)F" "$($env:USERDOMAIN)\$($env:USERNAME):(OI)(CI)F" | Out-Null
Write-Host "Licensing backup created: $backup" -ForegroundColor Green
Write-Warning "This backup contains the private signing key. Keep it offline and never send it with a customer package."
