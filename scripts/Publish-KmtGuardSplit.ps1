param(
    [string]$Configuration = "Release",
    [string]$Destination = "D:\KMTGuard-build\Filter",
    [switch]$DevelopmentBuild
)

$ErrorActionPreference = "Stop"
$repoRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot ".."))
$stagingRoot = [System.IO.Path]::GetFullPath((Join-Path $repoRoot ".artifacts\split-runtime-$PID"))
$changelogPath = Join-Path $repoRoot "CHANGELOG.md"
$versionPath = Join-Path $repoRoot "VERSION.txt"

if (-not (Test-Path -LiteralPath $versionPath -PathType Leaf)) {
    throw "Required project file VERSION.txt is missing."
}
$productVersion = (Get-Content -LiteralPath $versionPath -Raw).Trim()
if ($productVersion -notmatch '^\d+\.\d+\.\d+$') {
    throw "VERSION.txt must contain one semantic product version."
}

if (-not (Test-Path -LiteralPath $changelogPath -PathType Leaf)) {
    throw "Required project file CHANGELOG.md is missing. KMTGuard.Agent cannot be published without the customer update history."
}
if ([string]::IsNullOrWhiteSpace((Get-Content -LiteralPath $changelogPath -Raw))) {
    throw "Required project file CHANGELOG.md is empty. Add the customer update history before publishing KMTGuard.Agent."
}

if (-not $stagingRoot.StartsWith($repoRoot, [System.StringComparison]::OrdinalIgnoreCase)) {
    throw "The staging directory resolved outside the repository."
}

if (Test-Path -LiteralPath $stagingRoot) {
    Remove-Item -LiteralPath $stagingRoot -Recurse -Force
}

try {
New-Item -ItemType Directory -Path $stagingRoot -Force | Out-Null
New-Item -ItemType Directory -Path $Destination -Force | Out-Null

$legacyArtifacts = @(
    "JTGuard.AdminDesktop.exe",
    "JTGuard.AdminDesktop.pdb",
    "KMTGuard.AdminDesktop.exe",
    "KMTGuard.AdminDesktop.pdb",
    "KMTGuard.runtimeconfig.json",
    "SilkroadSecurityAPI.pdb"
)
foreach ($legacyArtifact in $legacyArtifacts) {
    $legacyPath = Join-Path $Destination $legacyArtifact
    if (Test-Path -LiteralPath $legacyPath) {
        Remove-Item -LiteralPath $legacyPath -Force
    }
}

$projects = @(
    @{ Name = "Desktop"; Project = "KMTGuard.AdminDesktop\KMTGuard.AdminDesktop.csproj"; Exe = "KMTGuard.AdminDesktop.exe"; Output = "KMTGuard.exe"; SelfContained = $false },
    @{ Name = "Agent"; Project = "filter\KMTGuardnew\KMTGuard.AgentHost\KMTGuard.AgentHost.csproj"; Exe = "KMTGuard.Agent.exe"; Output = "KMTGuard.Agent.exe"; SelfContained = $false },
    @{ Name = "Download"; Project = "filter\KMTGuardnew\KMTGuard.DownloadHost\KMTGuard.DownloadHost.csproj"; Exe = "KMTGuard.Download.exe"; Output = "KMTGuard.Download.exe"; SelfContained = $false },
    @{ Name = "Gateway"; Project = "filter\KMTGuardnew\KMTGuard.GatewayHost\KMTGuard.GatewayHost.csproj"; Exe = "KMTGuard.Gateway.exe"; Output = "KMTGuard.Gateway.exe"; SelfContained = $false }
)

foreach ($entry in $projects) {
    $publishDirectory = Join-Path $stagingRoot $entry.Name
    $selfContained = $entry.SelfContained.ToString().ToLowerInvariant()
    $publishArguments = @(
        "publish",
        (Join-Path $repoRoot $entry.Project),
        "-c", $Configuration,
        "-r", "win-x64",
        "--self-contained", $selfContained,
        "-p:PublishSingleFile=true",
        "-p:EnableCompressionInSingleFile=$selfContained",
        "-p:IncludeNativeLibrariesForSelfExtract=true",
        "-p:DebugType=None",
        "-o", $publishDirectory
    )
    if ($DevelopmentBuild) {
        $publishArguments += "-p:KmtDevelopmentBuild=true"
    }
    else {
        $publishArguments += "-p:KmtDevelopmentBuild=false"
    }
    & dotnet @publishArguments

    if ($LASTEXITCODE -ne 0) {
        throw "Publish failed for $($entry.Name)."
    }

    $sourceExecutable = Join-Path $publishDirectory $entry.Exe
    if (-not (Test-Path -LiteralPath $sourceExecutable)) {
        throw "Published executable not found: $sourceExecutable"
    }

    Copy-Item -LiteralPath $sourceExecutable -Destination (Join-Path $Destination $entry.Output) -Force

    $publishedVersion = [System.Diagnostics.FileVersionInfo]::GetVersionInfo($sourceExecutable).ProductVersion
    if ($publishedVersion -notmatch '^(\d+\.\d+\.\d+)' -or $Matches[1] -ne $productVersion) {
        throw "$($entry.Name) reports product version '$publishedVersion'; expected $productVersion."
    }
}

Copy-Item -LiteralPath $changelogPath -Destination (Join-Path $Destination "CHANGELOG.md") -Force
Copy-Item -LiteralPath $versionPath -Destination (Join-Path $Destination "VERSION.txt") -Force

& (Join-Path $PSScriptRoot "Publish-KmtGuardDatabase.ps1") -Destination $Destination
if ($LASTEXITCODE -ne 0) {
    throw "Versioned database package publish failed."
}

$supportFiles = @(
    @{ Source = "docs\bot_protection.md"; Destination = "docs\BotProtection.md" },
    @{ Source = "docs\honor_rank_runtime_refresh.md"; Destination = "docs\HonorRankRefresh.md" },
    @{ Source = "docs\player_style_system.md"; Destination = "docs\PlayerStyleSystem.md" },
    @{ Source = "docs\vip_system.md"; Destination = "docs\VipSystem.md" },
    @{ Source = "docs\dynamic_ranking_sql_guide.md"; Destination = "docs\DynamicRanking.md" },
    @{ Source = "docs\item_chest_integrity.md"; Destination = "docs\ItemChestIntegrity.md" },
    @{ Source = "docs\telegram_notifications.md"; Destination = "docs\TelegramNotifications.md" },
    @{ Source = "docs\discord_notifications.md"; Destination = "docs\DiscordNotifications.md" },
    @{ Source = "docs\kmtguard_customer_installation_guide_ar.md"; Destination = "docs\KMTGuard-Customer-Installation-Guide-AR.md" },
    @{ Source = "scripts\Get-SilkroadServerPorts.cmd"; Destination = "tools\Detect-Silkroad-Real-Ports.cmd" },
    @{ Source = "scripts\Test_Filter_Ports_From_Client.cmd"; Destination = "tools\Test-KMTGuard-Public-Ports.cmd" },
    @{ Source = "scripts\Set-KmtGuardSqlCredential.ps1"; Destination = "tools\Set-KmtGuardSqlCredential.ps1" },
    @{ Source = "scripts\New-KmtGuardQuickLoginKey.ps1"; Destination = "tools\New-KmtGuardQuickLoginKey.ps1" }
)
foreach ($supportFile in $supportFiles) {
    $source = Join-Path $repoRoot ([string]($supportFile.Source))
    if (-not (Test-Path -LiteralPath $source -PathType Leaf)) {
        throw "Required packaged support file is missing: $source"
    }

    $supportDestination = Join-Path $Destination ([string]($supportFile.Destination))
    New-Item -ItemType Directory -Path (Split-Path $supportDestination) -Force | Out-Null
    Copy-Item -LiteralPath $source -Destination $supportDestination -Force
}

$rootSqlFiles = @(Get-ChildItem -LiteralPath $Destination -Filter "*.sql" -File)
if ($rootSqlFiles.Count -gt 0) {
    throw "SQL files must only be packaged below Filter\database: $($rootSqlFiles.Name -join ', ')"
}

$webViewer = Join-Path $repoRoot "filter\KMTGuardnew\KMTGuard\webviewer.json"
if (Test-Path -LiteralPath $webViewer) {
    Copy-Item -LiteralPath $webViewer -Destination (Join-Path $Destination "webviewer.json") -Force
}

$languageSource = Join-Path $repoRoot "filter\KMTGuardnew\KMTGuard\Languages"
$languageDestination = Join-Path $Destination "Languages"
foreach ($requiredLanguageFile in @("English.json", "Turkish.json", "README.txt")) {
    $source = Join-Path $languageSource $requiredLanguageFile
    if (-not (Test-Path -LiteralPath $source -PathType Leaf)) {
        throw "Required Filter language file is missing: $source"
    }
}
New-Item -ItemType Directory -Path $languageDestination -Force | Out-Null
Get-ChildItem -LiteralPath $languageSource -File |
    Copy-Item -Destination $languageDestination -Force

Get-ChildItem -LiteralPath $Destination -Filter "KMTGuard*.exe" |
    Sort-Object Name |
    Select-Object Name, Length, LastWriteTime
}
finally {
    if (Test-Path -LiteralPath $stagingRoot) {
        Remove-Item -LiteralPath $stagingRoot -Recurse -Force
    }
}
