param(
    [string]$Configuration = "Release",
    [string]$BuildRoot = "D:\KMTGuard-build",
    [switch]$SkipLicenseCenter,
    [switch]$CustomerProductionOnly,
    [switch]$DeveloperOnly,
    [switch]$FilterOnly,
    [switch]$GameServerOnly
)

$ErrorActionPreference = "Stop"
Set-StrictMode -Version Latest

if ($DeveloperOnly -and $CustomerProductionOnly) {
    throw "DeveloperOnly and CustomerProductionOnly cannot be used together."
}
if ($DeveloperOnly -and -not $FilterOnly) {
    throw "DeveloperOnly currently requires FilterOnly so the frozen customer delivery cannot be touched."
}
if ($GameServerOnly -and ($FilterOnly -or $DeveloperOnly -or $CustomerProductionOnly)) {
    throw "GameServerOnly publishes both Developer/Test and CustomerProductionBase and cannot be combined with the other scope switches."
}

$repoRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot ".."))
$buildRootFull = [System.IO.Path]::GetFullPath($BuildRoot).TrimEnd('\')
$expectedBuildRoot = [System.IO.Path]::GetFullPath("D:\KMTGuard-build").TrimEnd('\')
if (-not $buildRootFull.Equals($expectedBuildRoot, [System.StringComparison]::OrdinalIgnoreCase)) {
    throw "The developer delivery destination must be D:\KMTGuard-build."
}

$stamp = Get-Date -Format "yyyyMMdd-HHmmss"
$stageRoot = Join-Path $repoRoot ".artifacts\developer-release\$stamp"
$stageBuild = Join-Path $stageRoot "build"
$filterStage = Join-Path $stageBuild "Filter"
$clientOutput = Join-Path $stageRoot "native\Client"
$gameServerOutput = Join-Path $stageRoot "native\GameServer"
$shardOutput = Join-Path $stageRoot "native\ShardManager"
$productionCheck = Join-Path $stageRoot "production-flavor-check"
$productionClientOutput = Join-Path $stageRoot "native\Production\Client"
$productionGameServerOutput = Join-Path $stageRoot "native\Production\GameServer"
$productionShardOutput = Join-Path $stageRoot "native\Production\ShardManager"
$developerShardIntermediate = Join-Path $stageRoot "obj\ShardManager\Developer"
$productionShardIntermediate = Join-Path $stageRoot "obj\ShardManager\Production"
$customerProductionStage = Join-Path $stageBuild "CustomerProductionBase"
$licenseCenterStage = Join-Path $stageRoot "licensing\LicenseCenter"
$updaterStage = Join-Path $stageRoot "updater"
$customerMediaRoot = Join-Path $buildRootFull "Media-KemtGuard"
$versionPath = Join-Path $repoRoot "VERSION.txt"

if (-not (Test-Path -LiteralPath $versionPath -PathType Leaf)) {
    throw "Required project file VERSION.txt is missing."
}
$productVersion = (Get-Content -LiteralPath $versionPath -Raw).Trim()
if ($productVersion -notmatch '^\d+\.\d+\.\d+$') {
    throw "VERSION.txt must contain one semantic product version."
}

function Copy-Tree([string]$Source, [string]$Destination) {
    if (-not (Test-Path -LiteralPath $Source -PathType Container)) {
        throw "Source folder is missing: $Source"
    }

    New-Item -ItemType Directory -Path $Destination -Force | Out-Null
    Get-ChildItem -LiteralPath $Source -Force | Copy-Item -Destination $Destination -Recurse -Force
}

function Write-SafeRuntimeSettings([string]$Source, [string]$Destination) {
    $settings = Get-Content -LiteralPath $Source -Raw | ConvertFrom-Json
    $required = [ordered]@{
        Password = ""
        CredentialTarget = "KMTGuard/Sql"
        QuickLoginCredentialTarget = "KMTGuard/QuickLoginMasterKey"
        QuickLoginMasterKey = ""
        UpstreamConnectTimeoutSeconds = 10
        HandshakeTimeoutSeconds = 15
        LoginTimeoutSeconds = 60
        UnauthenticatedIdleTimeoutSeconds = 90
        AuthenticatedHeartbeatTimeoutSeconds = 180
        GatewaySessionCap = 10000
        AgentSessionCap = 10000
        DownloadSessionCap = 2000
    }
    foreach ($entry in $required.GetEnumerator()) {
        $property = $settings.PSObject.Properties[$entry.Key]
        if ($null -eq $property) {
            $settings | Add-Member -NotePropertyName $entry.Key -NotePropertyValue $entry.Value
        }
        else {
            $property.Value = $entry.Value
        }
    }
    $settings | ConvertTo-Json -Depth 10 |
        Set-Content -LiteralPath $Destination -Encoding UTF8
}

function Clear-GeneratedFilterPackageContent([string]$FilterRoot) {
    if (-not (Test-Path -LiteralPath $FilterRoot -PathType Container)) {
        return
    }

    $filterRootFull = [System.IO.Path]::GetFullPath($FilterRoot).TrimEnd('\')
    foreach ($folderName in @("database", "docs", "tools")) {
        $folderPath = [System.IO.Path]::GetFullPath((Join-Path $filterRootFull $folderName))
        if (-not $folderPath.StartsWith(
            $filterRootFull + '\',
            [System.StringComparison]::OrdinalIgnoreCase)) {
            throw "Refusing to clean a generated package folder outside Filter: $folderPath"
        }
        if (Test-Path -LiteralPath $folderPath) {
            Remove-Item -LiteralPath $folderPath -Recurse -Force
        }
    }

    Get-ChildItem -LiteralPath $filterRootFull -Filter "*.sql" -File -ErrorAction SilentlyContinue |
        Remove-Item -Force
    foreach ($legacyFile in @(
        "BotProtection-README.md",
        "HonorRankRefresh-README.md",
        "PlayerStyleSystem-README.md",
        "VipSystem-README.md",
        "DynamicRanking-README.md",
        "ItemChestIntegrity-README.md",
        "TelegramNotifications-README.md",
        "DiscordNotifications-README.md",
        "KMTGuard-Customer-Installation-Guide-AR.md",
        "KMTGuard-Database-Guide-AR.md",
        "Detect-Silkroad-Real-Ports.cmd",
        "Test-KMTGuard-Public-Ports.cmd"
    )) {
        $legacyPath = Join-Path $filterRootFull $legacyFile
        if (Test-Path -LiteralPath $legacyPath -PathType Leaf) {
            Remove-Item -LiteralPath $legacyPath -Force
        }
    }
}

function Assert-FilterPackageLayout([string]$FilterRoot) {
    $rootSql = @(Get-ChildItem -LiteralPath $FilterRoot -Filter "*.sql" -File)
    if ($rootSql.Count -gt 0) {
        throw "Filter package root contains SQL files: $($rootSql.Name -join ', ')"
    }

    $databaseRoot = Join-Path $FilterRoot "database"
    if (-not (Test-Path -LiteralPath $databaseRoot -PathType Container)) {
        throw "Versioned database package is missing: $databaseRoot"
    }
    if (Test-Path -LiteralPath (Join-Path $databaseRoot "migrations")) {
        throw "Legacy database\migrations layout is not allowed in generated packages."
    }

    $versionFolders = @(Get-ChildItem -LiteralPath $databaseRoot -Directory |
        Where-Object Name -Match '^v\d+\.\d+\.\d+$')
    if ($versionFolders.Count -eq 0) {
        throw "No version folders were found in the generated database package."
    }
}

function Find-MsBuild {
    $candidates = @(
        "C:\Program Files (x86)\Microsoft Visual Studio\2022\BuildTools\MSBuild\Current\Bin\MSBuild.exe",
        "C:\Program Files (x86)\Microsoft Visual Studio\2022\Community\MSBuild\Current\Bin\MSBuild.exe",
        "C:\Program Files (x86)\Microsoft Visual Studio\2022\Professional\MSBuild\Current\Bin\MSBuild.exe",
        "C:\Program Files (x86)\Microsoft Visual Studio\2022\Enterprise\MSBuild\Current\Bin\MSBuild.exe",
        "C:\Program Files\Microsoft Visual Studio\2022\BuildTools\MSBuild\Current\Bin\MSBuild.exe",
        "C:\Program Files\Microsoft Visual Studio\2022\Community\MSBuild\Current\Bin\MSBuild.exe",
        "C:\Program Files\Microsoft Visual Studio\2022\Professional\MSBuild\Current\Bin\MSBuild.exe",
        "C:\Program Files\Microsoft Visual Studio\2022\Enterprise\MSBuild\Current\Bin\MSBuild.exe"
    )
    foreach ($candidate in $candidates) {
        if (Test-Path -LiteralPath $candidate -PathType Leaf) {
            return $candidate
        }
    }
    throw "Visual Studio 2022 MSBuild was not found."
}

function Find-CMake {
    $candidates = @(
        "C:\Program Files\CMake\bin\cmake.exe",
        "C:\Program Files (x86)\CMake\bin\cmake.exe",
        "C:\Program Files\Microsoft Visual Studio\2022\BuildTools\Common7\IDE\CommonExtensions\Microsoft\CMake\CMake\bin\cmake.exe",
        "C:\Program Files (x86)\Microsoft Visual Studio\2022\BuildTools\Common7\IDE\CommonExtensions\Microsoft\CMake\CMake\bin\cmake.exe"
    )
    foreach ($candidate in $candidates) {
        if (Test-Path -LiteralPath $candidate -PathType Leaf) {
            return $candidate
        }
    }
    throw "CMake was not found."
}

function Test-BinaryContainsText([string]$Path, [string]$Text) {
    $bytes = [System.IO.File]::ReadAllBytes($Path)
    $ascii = [System.Text.Encoding]::ASCII.GetString($bytes)
    if ($ascii.IndexOf($Text, [System.StringComparison]::Ordinal) -ge 0) {
        return $true
    }
    $unicode = [System.Text.Encoding]::Unicode.GetString($bytes)
    return $unicode.IndexOf($Text, [System.StringComparison]::Ordinal) -ge 0
}

function Require-DeveloperMarker([string]$Path) {
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) {
        throw "Developer output is missing: $Path"
    }
    if (-not (Test-BinaryContainsText $Path "Developer/Test Build")) {
        throw "Developer marker is missing from: $Path"
    }
}

function Reject-DeveloperMarker([string]$Path) {
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) {
        throw "Production check output is missing: $Path"
    }
    if (Test-BinaryContainsText $Path "Developer/Test Build") {
        throw "Production build accidentally contains the Developer/Test marker: $Path"
    }
}

function Assert-ProductVersion([string]$Path) {
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) {
        throw "Version check output is missing: $Path"
    }

    $reportedVersion = [System.Diagnostics.FileVersionInfo]::GetVersionInfo($Path).ProductVersion
    if ($reportedVersion -notmatch '^(\d+\.\d+\.\d+)' -or $Matches[1] -ne $productVersion) {
        throw "$Path reports product version '$reportedVersion'; expected $productVersion."
    }
}

if (-not $FilterOnly -and -not $GameServerOnly) {
    if (-not (Test-Path -LiteralPath $customerMediaRoot -PathType Container)) {
        throw "The fixed full customer media source is missing: $customerMediaRoot"
    }
    foreach ($requiredCustomerMediaFolder in @("Client-import", "Media")) {
        $requiredCustomerMediaPath = Join-Path $customerMediaRoot $requiredCustomerMediaFolder
        if (-not (Test-Path -LiteralPath $requiredCustomerMediaPath -PathType Container)) {
            throw "The fixed full customer media source is incomplete. Required folder is missing: $requiredCustomerMediaPath"
        }
    }
}

New-Item -ItemType Directory -Path @(
    $stageBuild,
    $clientOutput,
    $gameServerOutput,
    $shardOutput,
    $productionCheck,
    $productionClientOutput,
    $productionGameServerOutput,
    $productionShardOutput,
    $developerShardIntermediate,
    $productionShardIntermediate,
    $customerProductionStage,
    $licenseCenterStage,
    $updaterStage
) -Force | Out-Null

if ($GameServerOnly) {
    Write-Host "Running GameServer contract checks..." -ForegroundColor Cyan
    foreach ($test in Get-ChildItem -LiteralPath (Join-Path $repoRoot "gameserver\tests") -Filter "*.Tests.ps1" | Sort-Object Name) {
        & $test.FullName
        if (-not $?) { throw "GameServer contract check failed: $($test.Name)" }
    }

    $gameServerBuildDirectory = Join-Path $repoRoot "gameserver\cmake-build-relwithdebinfo"

    Write-Host "Building Developer/Test GameServer add-on only..." -ForegroundColor Cyan
    & (Join-Path $PSScriptRoot "Build-KmtGuardGameServerFlavor.cmd") `
        (Join-Path $repoRoot "gameserver") `
        $gameServerBuildDirectory `
        $gameServerOutput `
        "ON"
    if ($LASTEXITCODE -ne 0) { throw "Developer GameServer build failed." }

    Write-Host "Building licensed Production GameServer add-on only..." -ForegroundColor Cyan
    & (Join-Path $PSScriptRoot "Build-KmtGuardGameServerFlavor.cmd") `
        (Join-Path $repoRoot "gameserver") `
        $gameServerBuildDirectory `
        $productionGameServerOutput `
        "OFF"
    if ($LASTEXITCODE -ne 0) { throw "Production GameServer build failed." }

    $developerGameServerDll = Join-Path $gameServerOutput "KMTGuard_GameServer.dll"
    $productionGameServerDll = Join-Path $productionGameServerOutput "KMTGuard_GameServer.dll"
    Reject-DeveloperMarker $developerGameServerDll
    Reject-DeveloperMarker $productionGameServerDll
    Assert-ProductVersion $developerGameServerDll
    Assert-ProductVersion $productionGameServerDll

    $developerGameServerDestination = Join-Path $buildRootFull "ServerAddons\GameServer"
    $customerProductionDestination = Join-Path $buildRootFull "CustomerProductionBase"
    if (-not (Test-Path -LiteralPath $customerProductionDestination -PathType Container)) {
        throw "The existing licensed CustomerProductionBase is required for a component-only refresh: $customerProductionDestination"
    }
    $productionGameServerDestination = Join-Path $customerProductionDestination "ServerAddons\GameServer"
    New-Item -ItemType Directory -Path $developerGameServerDestination, $productionGameServerDestination -Force | Out-Null

    Copy-Item -LiteralPath $developerGameServerDll `
        -Destination (Join-Path $developerGameServerDestination "KMTGuard_GameServer.dll") -Force
    Copy-Item -LiteralPath $productionGameServerDll `
        -Destination (Join-Path $productionGameServerDestination "KMTGuard_GameServer.dll") -Force
    Copy-Item -LiteralPath $versionPath -Destination (Join-Path $developerGameServerDestination "VERSION.txt") -Force
    Copy-Item -LiteralPath $versionPath -Destination (Join-Path $productionGameServerDestination "VERSION.txt") -Force
    Copy-Item -LiteralPath $versionPath -Destination (Join-Path $buildRootFull "VERSION.txt") -Force
    Copy-Item -LiteralPath $versionPath -Destination (Join-Path $customerProductionDestination "VERSION.txt") -Force
    Copy-Item -LiteralPath (Join-Path $repoRoot "CHANGELOG.md") -Destination (Join-Path $buildRootFull "CHANGELOG.md") -Force

    $developerHashTargets = @(
        "Filter\KMTGuard.exe",
        "Filter\KMTGuard.Agent.exe",
        "Filter\KMTGuard.Download.exe",
        "Filter\KMTGuard.Gateway.exe",
        "Filter\KMTGuard.Updater.exe",
        "DLL\KMTGuardKit.dll",
        "ServerAddons\GameServer\KMTGuard_GameServer.dll",
        "ServerAddons\ShardManager\KMTGuard_ShardManager.dll"
    )
    $developerHashLines = foreach ($relative in $developerHashTargets) {
        $path = Join-Path $buildRootFull $relative
        if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
            throw "Required developer delivery file is missing: $path"
        }
        $hash = Get-FileHash -LiteralPath $path -Algorithm SHA256
        "$($hash.Hash)  $($relative.Replace('\', '/'))"
    }
    [System.IO.File]::WriteAllLines(
        (Join-Path $buildRootFull "DEVELOPER-SHA256.txt"),
        $developerHashLines,
        (New-Object System.Text.UTF8Encoding($false)))

    $productionHashTargets = @(
        "CustomerProductionBase\Filter\KMTGuard.exe",
        "CustomerProductionBase\Filter\KMTGuard.Agent.exe",
        "CustomerProductionBase\Filter\KMTGuard.Download.exe",
        "CustomerProductionBase\Filter\KMTGuard.Gateway.exe",
        "CustomerProductionBase\Filter\KMTGuard.Updater.exe",
        "CustomerProductionBase\DLL\KMTGuardKit.dll",
        "CustomerProductionBase\ServerAddons\GameServer\KMTGuard_GameServer.dll",
        "CustomerProductionBase\ServerAddons\ShardManager\KMTGuard_ShardManager.dll"
    )
    $productionHashLines = foreach ($relative in $productionHashTargets) {
        $path = Join-Path $buildRootFull $relative
        Reject-DeveloperMarker $path
        $hash = Get-FileHash -LiteralPath $path -Algorithm SHA256
        "$($hash.Hash)  $($relative.Replace('\', '/'))"
    }
    [System.IO.File]::WriteAllLines(
        (Join-Path $buildRootFull "CUSTOMER-PRODUCTION-SHA256.txt"),
        $productionHashLines,
        (New-Object System.Text.UTF8Encoding($false)))

    Write-Host "GameServer-only v$productVersion is ready for Developer/Test and licensed customers." -ForegroundColor Green
    Get-Item `
        (Join-Path $developerGameServerDestination "KMTGuard_GameServer.dll"), `
        (Join-Path $productionGameServerDestination "KMTGuard_GameServer.dll") |
        Select-Object FullName, Length, LastWriteTime
    return
}

Write-Host "Building managed Developer/Test filter..." -ForegroundColor Cyan
& (Join-Path $PSScriptRoot "Publish-KmtGuardSplit.ps1") `
    -Configuration $Configuration `
    -Destination $filterStage `
    -DevelopmentBuild
if ($LASTEXITCODE -ne 0) { throw "Developer filter publish failed." }

$settingsSource = Join-Path $buildRootFull "Filter\Settings.json"
if (Test-Path -LiteralPath $settingsSource -PathType Leaf) {
    Copy-Item -LiteralPath $settingsSource `
        -Destination (Join-Path $filterStage "Settings.json") -Force
}
else {
    $settingsSource = Join-Path $repoRoot "filter\KMTGuardnew\KMTGuard\config\Settings.example.json"
    Write-SafeRuntimeSettings $settingsSource (Join-Path $filterStage "Settings.json")
}
Copy-Item -LiteralPath (Join-Path $repoRoot "deployment\DEVELOPER-TEST-BUILD.txt") `
    -Destination (Join-Path $filterStage "DEVELOPER-TEST-BUILD.txt") -Force

if ($FilterOnly) {
    Require-DeveloperMarker (Join-Path $filterStage "KMTGuard.Agent.exe")
    foreach ($developerFilterBinary in @(
        (Join-Path $filterStage "KMTGuard.exe"),
        (Join-Path $filterStage "KMTGuard.Agent.exe"),
        (Join-Path $filterStage "KMTGuard.Download.exe"),
        (Join-Path $filterStage "KMTGuard.Gateway.exe")
    )) {
        Assert-ProductVersion $developerFilterBinary
    }

    if ($DeveloperOnly) {
        Write-Host "Building KMTGuard Update Center v2..." -ForegroundColor Cyan
        dotnet publish (Join-Path $repoRoot "KMTGuard.Updater\KMTGuard.Updater.csproj") `
            -c $Configuration `
            -r win-x64 `
            --self-contained false `
            -p:PublishSingleFile=true `
            -p:DebugType=None `
            -o $updaterStage
        if ($LASTEXITCODE -ne 0) { throw "KMTGuard Update Center publish failed." }
        $updaterExecutable = Join-Path $updaterStage "KMTGuard.Updater.exe"
        Assert-ProductVersion $updaterExecutable
        Copy-Item -LiteralPath $updaterExecutable -Destination $filterStage -Force

        $developerFilterDestination = Join-Path $buildRootFull "Filter"
        Write-Host "Publishing Filter-only Developer/Test delivery; customer production remains frozen..." -ForegroundColor Cyan
        Clear-GeneratedFilterPackageContent $developerFilterDestination
        Copy-Tree $filterStage $developerFilterDestination
        Copy-Item -LiteralPath $versionPath -Destination (Join-Path $buildRootFull "VERSION.txt") -Force
        Assert-FilterPackageLayout $developerFilterDestination

        $developerHashTargets = @(
            "Filter\KMTGuard.exe",
            "Filter\KMTGuard.Agent.exe",
            "Filter\KMTGuard.Download.exe",
            "Filter\KMTGuard.Gateway.exe",
            "Filter\KMTGuard.Updater.exe"
        )
        $developerHashLines = foreach ($relative in $developerHashTargets) {
            $path = Join-Path $buildRootFull $relative
            $hash = Get-FileHash -LiteralPath $path -Algorithm SHA256
            "$($hash.Hash)  $($relative.Replace('\', '/'))"
        }
        [System.IO.File]::WriteAllLines(
            (Join-Path $buildRootFull "DEVELOPER-SHA256.txt"),
            $developerHashLines,
            (New-Object System.Text.UTF8Encoding($false)))

        Write-Host "Developer/Test Filter v$productVersion is ready. CustomerProductionBase was not read, rebuilt, or changed." -ForegroundColor Green
        Get-ChildItem -LiteralPath $developerFilterDestination -Filter "KMTGuard*.exe" |
            Select-Object Name, Length, LastWriteTime
        return
    }

    Write-Host "Building licensed Production Filter..." -ForegroundColor Cyan
    & (Join-Path $PSScriptRoot "Publish-KmtGuardSplit.ps1") `
        -Configuration $Configuration `
        -Destination $productionCheck
    if ($LASTEXITCODE -ne 0) { throw "Production filter publish failed." }
    Copy-Item -LiteralPath (Join-Path $repoRoot "filter\KMTGuardnew\KMTGuard\config\Settings.example.json") `
        -Destination (Join-Path $productionCheck "Settings.json") -Force

    foreach ($productionFilterBinary in @(
        (Join-Path $productionCheck "KMTGuard.exe"),
        (Join-Path $productionCheck "KMTGuard.Agent.exe"),
        (Join-Path $productionCheck "KMTGuard.Download.exe"),
        (Join-Path $productionCheck "KMTGuard.Gateway.exe")
    )) {
        Reject-DeveloperMarker $productionFilterBinary
        Assert-ProductVersion $productionFilterBinary
    }

    Write-Host "Building KMTGuard Update Center v2..." -ForegroundColor Cyan
    dotnet publish (Join-Path $repoRoot "KMTGuard.Updater\KMTGuard.Updater.csproj") `
        -c $Configuration `
        -r win-x64 `
        --self-contained false `
        -p:PublishSingleFile=true `
        -p:DebugType=None `
        -o $updaterStage
    if ($LASTEXITCODE -ne 0) { throw "KMTGuard Update Center publish failed." }
    $updaterExecutable = Join-Path $updaterStage "KMTGuard.Updater.exe"
    Assert-ProductVersion $updaterExecutable
    Copy-Item -LiteralPath $updaterExecutable -Destination $filterStage -Force
    Copy-Item -LiteralPath $updaterExecutable -Destination $productionCheck -Force

    $developerFilterDestination = Join-Path $buildRootFull "Filter"
    if (-not $CustomerProductionOnly) {
        Write-Host "Publishing Filter-only Developer/Test delivery..." -ForegroundColor Cyan
        Clear-GeneratedFilterPackageContent $developerFilterDestination
        Copy-Tree $filterStage $developerFilterDestination
        Copy-Item -LiteralPath $versionPath -Destination (Join-Path $buildRootFull "VERSION.txt") -Force
        Assert-FilterPackageLayout $developerFilterDestination

        $developerHashTargets = @(
            "Filter\KMTGuard.exe",
            "Filter\KMTGuard.Agent.exe",
            "Filter\KMTGuard.Download.exe",
            "Filter\KMTGuard.Gateway.exe",
            "Filter\KMTGuard.Updater.exe"
        )
        $developerHashLines = foreach ($relative in $developerHashTargets) {
            $path = Join-Path $buildRootFull $relative
            $hash = Get-FileHash -LiteralPath $path -Algorithm SHA256
            "$($hash.Hash)  $($relative.Replace('\', '/'))"
        }
        [System.IO.File]::WriteAllLines(
            (Join-Path $buildRootFull "DEVELOPER-SHA256.txt"),
            $developerHashLines,
            (New-Object System.Text.UTF8Encoding($false)))
    }

    Write-Host "Publishing Filter-only licensed customer production base..." -ForegroundColor Cyan
    $customerProductionDestination = Join-Path $buildRootFull "CustomerProductionBase"
    $customerFilterDestination = Join-Path $customerProductionDestination "Filter"
    Clear-GeneratedFilterPackageContent $customerFilterDestination
    Copy-Tree $productionCheck $customerFilterDestination
    Copy-Item -LiteralPath $versionPath -Destination (Join-Path $customerProductionDestination "VERSION.txt") -Force
    Assert-FilterPackageLayout $customerFilterDestination

    $productionHashTargets = @(
        "CustomerProductionBase\Filter\KMTGuard.exe",
        "CustomerProductionBase\Filter\KMTGuard.Agent.exe",
        "CustomerProductionBase\Filter\KMTGuard.Download.exe",
        "CustomerProductionBase\Filter\KMTGuard.Gateway.exe",
        "CustomerProductionBase\Filter\KMTGuard.Updater.exe"
    )
    $productionHashLines = foreach ($relative in $productionHashTargets) {
        $path = Join-Path $buildRootFull $relative
        Reject-DeveloperMarker $path
        $hash = Get-FileHash -LiteralPath $path -Algorithm SHA256
        "$($hash.Hash)  $($relative.Replace('\', '/'))"
    }
    [System.IO.File]::WriteAllLines(
        (Join-Path $buildRootFull "CUSTOMER-PRODUCTION-SHA256.txt"),
        $productionHashLines,
        (New-Object System.Text.UTF8Encoding($false)))

    if ($CustomerProductionOnly) {
        Write-Host "Latest Filter-only customer production base is ready: $customerFilterDestination" -ForegroundColor Green
        return
    }

    Write-Host "Filter-only Developer/Test and customer Production builds are ready." -ForegroundColor Green
    $developerHashTargets | ForEach-Object { Get-Item -LiteralPath (Join-Path $buildRootFull $_) } |
        Select-Object FullName, Length, LastWriteTime
    return
}

Write-Host "Building Developer/Test KMTGuardKit..." -ForegroundColor Cyan
$clientBuildDirectory = Join-Path $repoRoot "JTClientLibrary\build-client-dll-kmt-developer"
& (Join-Path $PSScriptRoot "Build-KmtGuardClientFlavor.cmd") `
    (Join-Path $repoRoot "JTClientLibrary") `
    $clientBuildDirectory `
    $clientOutput `
    "ON"
if ($LASTEXITCODE -ne 0) { throw "Developer KMTGuardKit build failed." }

Write-Host "Building Developer/Test GameServer add-on..." -ForegroundColor Cyan
$gameServerBuildDirectory = Join-Path $repoRoot "gameserver\cmake-build-relwithdebinfo"
& (Join-Path $PSScriptRoot "Build-KmtGuardGameServerFlavor.cmd") `
    (Join-Path $repoRoot "gameserver") `
    $gameServerBuildDirectory `
    $gameServerOutput `
    "ON"
if ($LASTEXITCODE -ne 0) { throw "Developer GameServer build failed." }

Write-Host "Building Developer/Test ShardManager add-on..." -ForegroundColor Cyan
$msbuild = Find-MsBuild
$shardProject = Join-Path $repoRoot "ShardManager\vSRO-ShardManager\vSRO-ShardManager.vcxproj"
& $msbuild $shardProject `
    /t:Rebuild `
    /m `
    /p:Configuration=Release `
    /p:Platform=Win32 `
    /p:KmtDevelopmentBuild=true `
    "/p:IntDir=$developerShardIntermediate\" `
    "/p:OutDir=$shardOutput\"
if ($LASTEXITCODE -ne 0) { throw "Developer ShardManager build failed." }

$developerClientDll = Join-Path $clientOutput "KMTGuardKit.dll"
$developerGameServerDll = Join-Path $gameServerOutput "KMTGuard_GameServer.dll"
$developerShardDll = Join-Path $shardOutput "KMTGuard_ShardManager.dll"
Require-DeveloperMarker (Join-Path $filterStage "KMTGuard.Agent.exe")
Require-DeveloperMarker $developerClientDll
Reject-DeveloperMarker $developerGameServerDll
Reject-DeveloperMarker $developerShardDll
Assert-ProductVersion (Join-Path $filterStage "KMTGuard.exe")
Assert-ProductVersion (Join-Path $filterStage "KMTGuard.Agent.exe")
Assert-ProductVersion (Join-Path $filterStage "KMTGuard.Download.exe")
Assert-ProductVersion (Join-Path $filterStage "KMTGuard.Gateway.exe")
Assert-ProductVersion $developerClientDll
Assert-ProductVersion $developerGameServerDll
Assert-ProductVersion $developerShardDll

Write-Host "Verifying that the normal customer build flavor remains licensed..." -ForegroundColor Cyan
& (Join-Path $PSScriptRoot "Publish-KmtGuardSplit.ps1") `
    -Configuration $Configuration `
    -Destination $productionCheck
if ($LASTEXITCODE -ne 0) { throw "Production flavor verification build failed." }
Reject-DeveloperMarker (Join-Path $productionCheck "KMTGuard.exe")
Reject-DeveloperMarker (Join-Path $productionCheck "KMTGuard.Agent.exe")

Write-Host "Building the Production base used only for customer packages..." -ForegroundColor Cyan
& (Join-Path $PSScriptRoot "Build-KmtGuardClientFlavor.cmd") `
    (Join-Path $repoRoot "JTClientLibrary") `
    $clientBuildDirectory `
    $productionClientOutput `
    "OFF"
if ($LASTEXITCODE -ne 0) { throw "Production KMTGuardKit verification build failed." }

& (Join-Path $PSScriptRoot "Build-KmtGuardGameServerFlavor.cmd") `
    (Join-Path $repoRoot "gameserver") `
    $gameServerBuildDirectory `
    $productionGameServerOutput `
    "OFF"
if ($LASTEXITCODE -ne 0) { throw "Production GameServer verification build failed." }

& $msbuild $shardProject `
    /t:Rebuild `
    /m `
    /p:Configuration=Release `
    /p:Platform=Win32 `
    /p:KmtDevelopmentBuild=false `
    "/p:IntDir=$productionShardIntermediate\" `
    "/p:OutDir=$productionShardOutput\"
if ($LASTEXITCODE -ne 0) { throw "Production ShardManager verification build failed." }

$productionClientDll = Join-Path $productionClientOutput "KMTGuardKit.dll"
$productionGameServerDll = Join-Path $productionGameServerOutput "KMTGuard_GameServer.dll"
$productionShardDll = Join-Path $productionShardOutput "KMTGuard_ShardManager.dll"
Reject-DeveloperMarker $productionClientDll
Reject-DeveloperMarker $productionGameServerDll
Reject-DeveloperMarker $productionShardDll
Assert-ProductVersion (Join-Path $productionCheck "KMTGuard.exe")
Assert-ProductVersion (Join-Path $productionCheck "KMTGuard.Agent.exe")
Assert-ProductVersion (Join-Path $productionCheck "KMTGuard.Download.exe")
Assert-ProductVersion (Join-Path $productionCheck "KMTGuard.Gateway.exe")
Assert-ProductVersion $productionClientDll
Assert-ProductVersion $productionGameServerDll
Assert-ProductVersion $productionShardDll

$dllStage = Join-Path $stageBuild "DLL"
New-Item -ItemType Directory -Path $dllStage -Force | Out-Null
Copy-Item -LiteralPath $developerClientDll -Destination (Join-Path $dllStage "KMTGuardKit.dll") -Force
Copy-Item -LiteralPath $versionPath -Destination (Join-Path $dllStage "VERSION.txt") -Force
foreach ($loader in @(
    (Join-Path $repoRoot "JTClientLibrary\BinOut\Release\WebView2Loader.dll"),
    (Join-Path $repoRoot "BinOut\Win32\Release\WebViewerBridge.dll")
)) {
    if (Test-Path -LiteralPath $loader -PathType Leaf) {
        Copy-Item -LiteralPath $loader -Destination $dllStage -Force
    }
}

$addonsStage = Join-Path $stageBuild "ServerAddons"
$gameServerStage = Join-Path $addonsStage "GameServer"
$shardStage = Join-Path $addonsStage "ShardManager"
New-Item -ItemType Directory -Path $gameServerStage, $shardStage -Force | Out-Null
Copy-Item -LiteralPath $developerGameServerDll -Destination $gameServerStage -Force
Copy-Item -LiteralPath $developerShardDll -Destination $shardStage -Force
Copy-Item -LiteralPath $versionPath -Destination (Join-Path $gameServerStage "VERSION.txt") -Force
Copy-Item -LiteralPath $versionPath -Destination (Join-Path $shardStage "VERSION.txt") -Force
foreach ($configPair in @(
    @{
        Source = Join-Path $buildRootFull "ServerAddons\GameServer\KMTGuard-Addon.ini"
        Destination = $gameServerStage
    },
    @{
        Source = Join-Path $repoRoot "ShardManager\vSRO-ShardManager\Outpus\KMTGuard-Addon.ini"
        Destination = $shardStage
    }
)) {
    if (Test-Path -LiteralPath $configPair.Source -PathType Leaf) {
        Copy-Item -LiteralPath $configPair.Source -Destination $configPair.Destination -Force
    }
}
Copy-Item -LiteralPath (Join-Path $repoRoot "deployment\DEVELOPER-TEST-BUILD.txt") `
    -Destination (Join-Path $addonsStage "DEVELOPER-TEST-BUILD.txt") -Force

$mediaStage = Join-Path $stageBuild "Media"
Copy-Tree (Join-Path $repoRoot "JTClientLibrary\clientlibrary") (Join-Path $mediaStage "clientlibrary")
Copy-Tree (Join-Path $repoRoot "JTClientLibrary\media") (Join-Path $mediaStage "media")
Copy-Tree (Join-Path $repoRoot "JTClientLibrary\client-resources") (Join-Path $mediaStage "client-resources")

$productionFilterStage = Join-Path $customerProductionStage "Filter"
Copy-Tree $productionCheck $productionFilterStage
Copy-Item -LiteralPath (Join-Path $repoRoot "filter\KMTGuardnew\KMTGuard\config\Settings.example.json") `
    -Destination (Join-Path $productionFilterStage "Settings.json") -Force

$productionDllStage = Join-Path $customerProductionStage "DLL"
New-Item -ItemType Directory -Path $productionDllStage -Force | Out-Null
Copy-Item -LiteralPath $productionClientDll -Destination (Join-Path $productionDllStage "KMTGuardKit.dll") -Force
Copy-Item -LiteralPath $versionPath -Destination (Join-Path $productionDllStage "VERSION.txt") -Force
foreach ($loader in @(
    (Join-Path $repoRoot "JTClientLibrary\BinOut\Release\WebView2Loader.dll"),
    (Join-Path $repoRoot "BinOut\Win32\Release\WebViewerBridge.dll")
)) {
    if (Test-Path -LiteralPath $loader -PathType Leaf) {
        Copy-Item -LiteralPath $loader -Destination $productionDllStage -Force
    }
}

$productionAddonsStage = Join-Path $customerProductionStage "ServerAddons"
$productionGameServerStage = Join-Path $productionAddonsStage "GameServer"
$productionShardStage = Join-Path $productionAddonsStage "ShardManager"
New-Item -ItemType Directory -Path $productionGameServerStage, $productionShardStage -Force | Out-Null
Copy-Item -LiteralPath $productionGameServerDll -Destination $productionGameServerStage -Force
Copy-Item -LiteralPath $productionShardDll -Destination $productionShardStage -Force
Copy-Item -LiteralPath $versionPath -Destination (Join-Path $productionGameServerStage "VERSION.txt") -Force
Copy-Item -LiteralPath $versionPath -Destination (Join-Path $productionShardStage "VERSION.txt") -Force
foreach ($configPair in @(
    @{
        Source = Join-Path $buildRootFull "ServerAddons\GameServer\KMTGuard-Addon.ini"
        Destination = $productionGameServerStage
    },
    @{
        Source = Join-Path $repoRoot "ShardManager\vSRO-ShardManager\Outpus\KMTGuard-Addon.ini"
        Destination = $productionShardStage
    }
)) {
    if (Test-Path -LiteralPath $configPair.Source -PathType Leaf) {
        Copy-Item -LiteralPath $configPair.Source -Destination $configPair.Destination -Force
    }
}
Copy-Tree (Join-Path $customerMediaRoot "Media") (Join-Path $customerProductionStage "Media")
Copy-Tree (Join-Path $customerMediaRoot "Client-import") (Join-Path $customerProductionStage "Client-import")
Copy-Item -LiteralPath $versionPath -Destination (Join-Path $customerProductionStage "VERSION.txt") -Force

foreach ($productionBinary in @(
    (Join-Path $productionFilterStage "KMTGuard.exe"),
    (Join-Path $productionFilterStage "KMTGuard.Agent.exe"),
    (Join-Path $productionFilterStage "KMTGuard.Download.exe"),
    (Join-Path $productionFilterStage "KMTGuard.Gateway.exe"),
    (Join-Path $productionDllStage "KMTGuardKit.dll"),
    (Join-Path $productionGameServerStage "KMTGuard_GameServer.dll"),
    (Join-Path $productionShardStage "KMTGuard_ShardManager.dll")
)) {
    Reject-DeveloperMarker $productionBinary
}

Write-Host "Building KMTGuard Update Center v2..." -ForegroundColor Cyan
dotnet publish (Join-Path $repoRoot "KMTGuard.Updater\KMTGuard.Updater.csproj") `
    -c $Configuration `
    -r win-x64 `
    --self-contained false `
    -p:PublishSingleFile=true `
    -p:DebugType=None `
    -o $updaterStage
if ($LASTEXITCODE -ne 0) { throw "KMTGuard Update Center publish failed." }
Copy-Item -LiteralPath (Join-Path $updaterStage "KMTGuard.Updater.exe") `
    -Destination (Join-Path $filterStage "KMTGuard.Updater.exe") -Force
Copy-Item -LiteralPath (Join-Path $updaterStage "KMTGuard.Updater.exe") `
    -Destination (Join-Path $productionFilterStage "KMTGuard.Updater.exe") -Force
Assert-ProductVersion (Join-Path $updaterStage "KMTGuard.Updater.exe")

if (-not $SkipLicenseCenter -and -not $CustomerProductionOnly) {
    Write-Host "Building the customer package generator with Production-base enforcement..." -ForegroundColor Cyan
    dotnet publish (Join-Path $repoRoot "KMTGuard.LicenseAdmin\KMTGuard.LicenseAdmin.csproj") `
        -c $Configuration `
        -r win-x64 `
        --self-contained true `
        -p:PublishSingleFile=true `
        -p:IncludeNativeLibrariesForSelfExtract=true `
        -p:DebugType=None `
        -o $licenseCenterStage
    if ($LASTEXITCODE -ne 0) { throw "License Center publish failed." }
    Assert-ProductVersion (Join-Path $licenseCenterStage "KMTGuard.LicenseAdmin.exe")
}

if ($CustomerProductionOnly) {
    Write-Host "Refreshing the licensed customer production base without touching the running Developer/Test delivery..." -ForegroundColor Cyan
    $customerProductionDestination = Join-Path $buildRootFull "CustomerProductionBase"
    if (Test-Path -LiteralPath $customerProductionDestination) {
        Remove-Item -LiteralPath $customerProductionDestination -Recurse -Force
    }
    Copy-Tree $customerProductionStage $customerProductionDestination
    Assert-FilterPackageLayout (Join-Path $customerProductionDestination "Filter")

    $productionHashTargets = @(
        "CustomerProductionBase\Filter\KMTGuard.exe",
        "CustomerProductionBase\Filter\KMTGuard.Agent.exe",
        "CustomerProductionBase\Filter\KMTGuard.Download.exe",
        "CustomerProductionBase\Filter\KMTGuard.Gateway.exe",
        "CustomerProductionBase\Filter\KMTGuard.Updater.exe",
        "CustomerProductionBase\DLL\KMTGuardKit.dll",
        "CustomerProductionBase\ServerAddons\GameServer\KMTGuard_GameServer.dll",
        "CustomerProductionBase\ServerAddons\ShardManager\KMTGuard_ShardManager.dll"
    )
    $productionHashLines = foreach ($relative in $productionHashTargets) {
        $path = Join-Path $buildRootFull $relative
        Reject-DeveloperMarker $path
        $hash = Get-FileHash -LiteralPath $path -Algorithm SHA256
        "$($hash.Hash)  $($relative.Replace('\', '/'))"
    }
    [System.IO.File]::WriteAllLines(
        (Join-Path $buildRootFull "CUSTOMER-PRODUCTION-SHA256.txt"),
        $productionHashLines,
        (New-Object System.Text.UTF8Encoding($false)))

    Write-Host "Latest customer production base is ready: $customerProductionDestination" -ForegroundColor Green
    return
}

Write-Host "Publishing Developer/Test delivery to D:\KMTGuard-build..." -ForegroundColor Cyan
$skipLicenseCenterPublish = [bool]$SkipLicenseCenter
if ($SkipLicenseCenter) {
    Write-Host "Leaving the running License Center in place during the automatic customer release refresh." -ForegroundColor Yellow
}
elseif ($null -ne (Get-Process -Name "KMTGuard.LicenseAdmin" -ErrorAction SilentlyContinue)) {
    $currentLicenseCenter = Join-Path $buildRootFull "Licensing\LicenseCenter\KMTGuard.LicenseAdmin.exe"
    $stagedLicenseCenter = Join-Path $licenseCenterStage "KMTGuard.LicenseAdmin.exe"
    if (-not (Test-Path -LiteralPath $currentLicenseCenter -PathType Leaf) -or
        (Get-FileHash -LiteralPath $currentLicenseCenter -Algorithm SHA256).Hash -ne
        (Get-FileHash -LiteralPath $stagedLicenseCenter -Algorithm SHA256).Hash) {
        throw "KMTGuard License Center is running and differs from the new build. Close it before publishing."
    }

    $skipLicenseCenterPublish = $true
    Write-Host "The running License Center is already byte-for-byte current; leaving it in place." -ForegroundColor Yellow
}
foreach ($folder in @("Filter", "DLL", "Media", "ServerAddons")) {
    if ($folder -eq "Filter") {
        Clear-GeneratedFilterPackageContent (Join-Path $buildRootFull $folder)
    }
    Copy-Tree (Join-Path $stageBuild $folder) (Join-Path $buildRootFull $folder)
}
$customerProductionDestination = Join-Path $buildRootFull "CustomerProductionBase"
if (Test-Path -LiteralPath $customerProductionDestination) {
    Remove-Item -LiteralPath $customerProductionDestination -Recurse -Force
}
Copy-Tree $customerProductionStage $customerProductionDestination
if (-not $skipLicenseCenterPublish) {
    Copy-Tree $licenseCenterStage (Join-Path $buildRootFull "Licensing\LicenseCenter")
}
Copy-Item -LiteralPath $versionPath -Destination (Join-Path $buildRootFull "VERSION.txt") -Force
Assert-FilterPackageLayout (Join-Path $buildRootFull "Filter")
Assert-FilterPackageLayout (Join-Path $customerProductionDestination "Filter")

$hashTargets = @(
    "Filter\KMTGuard.exe",
    "Filter\KMTGuard.Agent.exe",
    "Filter\KMTGuard.Download.exe",
    "Filter\KMTGuard.Gateway.exe",
    "Filter\KMTGuard.Updater.exe",
    "DLL\KMTGuardKit.dll",
    "ServerAddons\GameServer\KMTGuard_GameServer.dll",
    "ServerAddons\ShardManager\KMTGuard_ShardManager.dll"
)
$hashLines = foreach ($relative in $hashTargets) {
    $path = Join-Path $buildRootFull $relative
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
        throw "Required developer delivery file is missing: $path"
    }
    $hash = Get-FileHash -LiteralPath $path -Algorithm SHA256
    "$($hash.Hash)  $($relative.Replace('\', '/'))"
}
[System.IO.File]::WriteAllLines(
    (Join-Path $buildRootFull "DEVELOPER-SHA256.txt"),
    $hashLines,
    (New-Object System.Text.UTF8Encoding($false)))

$productionHashTargets = @(
    "CustomerProductionBase\Filter\KMTGuard.exe",
    "CustomerProductionBase\Filter\KMTGuard.Agent.exe",
    "CustomerProductionBase\Filter\KMTGuard.Download.exe",
    "CustomerProductionBase\Filter\KMTGuard.Gateway.exe",
    "CustomerProductionBase\Filter\KMTGuard.Updater.exe",
    "CustomerProductionBase\DLL\KMTGuardKit.dll",
    "CustomerProductionBase\ServerAddons\GameServer\KMTGuard_GameServer.dll",
    "CustomerProductionBase\ServerAddons\ShardManager\KMTGuard_ShardManager.dll"
)
$productionHashLines = foreach ($relative in $productionHashTargets) {
    $path = Join-Path $buildRootFull $relative
    Reject-DeveloperMarker $path
    $hash = Get-FileHash -LiteralPath $path -Algorithm SHA256
    "$($hash.Hash)  $($relative.Replace('\', '/'))"
}
[System.IO.File]::WriteAllLines(
    (Join-Path $buildRootFull "CUSTOMER-PRODUCTION-SHA256.txt"),
    $productionHashLines,
    (New-Object System.Text.UTF8Encoding($false)))

$staleReleaseHash = Join-Path $buildRootFull "RELEASE-SHA256.txt"
if (Test-Path -LiteralPath $staleReleaseHash -PathType Leaf) {
    Remove-Item -LiteralPath $staleReleaseHash -Force
}
Copy-Item -LiteralPath (Join-Path $repoRoot "deployment\DEVELOPER-TEST-BUILD.txt") `
    -Destination (Join-Path $buildRootFull "DEVELOPER-TEST-BUILD.txt") -Force

Write-Host "KMTGuard Developer/Test build is ready: $buildRootFull" -ForegroundColor Green
$hashTargets | ForEach-Object { Get-Item -LiteralPath (Join-Path $buildRootFull $_) } |
    Select-Object FullName, Length, LastWriteTime
