param(
    [string]$Configuration = "Release",
    [string]$DestinationRoot = "D:\KMTGuard-build\Licensing"
)

$ErrorActionPreference = "Stop"
Set-StrictMode -Version Latest

$repoRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot ".."))
$stagingRoot = [System.IO.Path]::GetFullPath((Join-Path $repoRoot ".artifacts\licensing-publish-$PID"))
$versionPath = Join-Path $repoRoot "VERSION.txt"
if (-not (Test-Path -LiteralPath $versionPath -PathType Leaf)) {
    throw "Required project file VERSION.txt is missing."
}
$productVersion = (Get-Content -LiteralPath $versionPath -Raw).Trim()
if ($productVersion -notmatch '^\d+\.\d+\.\d+$') {
    throw "VERSION.txt must contain one semantic product version."
}
if (-not $stagingRoot.StartsWith($repoRoot, [System.StringComparison]::OrdinalIgnoreCase)) {
    throw "The licensing staging directory resolved outside the repository."
}
if (Test-Path -LiteralPath $stagingRoot) {
    Remove-Item -LiteralPath $stagingRoot -Recurse -Force
}

function Publish-Project([string]$Project, [string]$Output) {
    dotnet publish (Join-Path $repoRoot $Project) `
        -c $Configuration `
        -r win-x64 `
        --self-contained true `
        -p:PublishSingleFile=true `
        -p:IncludeNativeLibrariesForSelfExtract=true `
        -p:DebugType=None `
        -o $Output
    if ($LASTEXITCODE -ne 0) {
        throw "Publish failed for $Project."
    }

    $executable = Get-ChildItem -LiteralPath $Output -Filter "KMTGuard*.exe" -File |
        Select-Object -First 1
    if ($null -eq $executable) {
        throw "Published executable is missing for $Project."
    }
    $reportedVersion = [System.Diagnostics.FileVersionInfo]::GetVersionInfo($executable.FullName).ProductVersion
    if ($reportedVersion -notmatch '^(\d+\.\d+\.\d+)' -or $Matches[1] -ne $productVersion) {
        throw "$Project reports product version '$reportedVersion'; expected $productVersion."
    }
}

try {
$serverStage = Join-Path $stagingRoot "LicenseServer"
$centerStage = Join-Path $stagingRoot "LicenseCenter"
Publish-Project "KMTGuard.LicenseServer\KMTGuard.LicenseServer.csproj" $serverStage
Publish-Project "KMTGuard.LicenseAdmin\KMTGuard.LicenseAdmin.csproj" $centerStage

New-Item -ItemType Directory -Path $DestinationRoot -Force | Out-Null
foreach ($name in @("LicenseServer", "LicenseCenter")) {
    $destination = Join-Path $DestinationRoot $name
    New-Item -ItemType Directory -Path $destination -Force | Out-Null
    Copy-Item -Path (Join-Path $stagingRoot "$name\*") -Destination $destination -Recurse -Force
    Copy-Item -LiteralPath $versionPath -Destination (Join-Path $destination "VERSION.txt") -Force
}
Copy-Item -LiteralPath $versionPath -Destination (Join-Path $DestinationRoot "VERSION.txt") -Force

$operations = Join-Path $DestinationRoot "Operations"
New-Item -ItemType Directory -Path $operations -Force | Out-Null
foreach ($script in @("Install-KmtGuardLicensing.ps1", "Install-KmtGuardLicenseProxy.ps1", "Backup-KmtGuardLicensing.ps1")) {
    Copy-Item -LiteralPath (Join-Path $PSScriptRoot $script) -Destination $operations -Force
}
Copy-Item -LiteralPath (Join-Path $repoRoot "docs\kmtguard_licensing_deployment.md") -Destination $operations -Force
Copy-Item -LiteralPath (Join-Path $repoRoot "deployment\license-proxy") -Destination $operations -Recurse -Force

Get-ChildItem -LiteralPath $DestinationRoot -Recurse -File |
    Sort-Object FullName |
    Select-Object FullName, Length, LastWriteTime
}
finally {
    if (Test-Path -LiteralPath $stagingRoot) {
        Remove-Item -LiteralPath $stagingRoot -Recurse -Force
    }
}
