param(
    [string]$Configuration = "Release",
    [string]$BuildRoot = "D:\KMTGuard-build"
)

$ErrorActionPreference = "Stop"
Set-StrictMode -Version Latest

$repoRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot ".."))
$buildRootFull = [System.IO.Path]::GetFullPath($BuildRoot).TrimEnd('\')
$expectedBuildRoot = [System.IO.Path]::GetFullPath("D:\KMTGuard-build").TrimEnd('\')
if (-not $buildRootFull.Equals($expectedBuildRoot, [System.StringComparison]::OrdinalIgnoreCase)) {
    throw "The official release destination must be D:\KMTGuard-build."
}
New-Item -ItemType Directory -Path $buildRootFull -Force | Out-Null
$releaseLockPath = Join-Path $buildRootFull ".release.lock"
try {
    $releaseLock = [System.IO.File]::Open(
        $releaseLockPath,
        [System.IO.FileMode]::OpenOrCreate,
        [System.IO.FileAccess]::ReadWrite,
        [System.IO.FileShare]::None)
}
catch {
    throw "Another KMTGuard release build is already running. Wait for it to finish before starting a second build."
}

$stamp = Get-Date -Format "yyyyMMdd-HHmmss"
$stageRoot = Join-Path $repoRoot ".artifacts\official-release\$stamp"
$stageBuild = Join-Path $stageRoot "build"
$backupRoot = Join-Path $buildRootFull ".release-backups\$stamp"
$settingsTemplate = Join-Path $repoRoot "filter\KMTGuardnew\KMTGuard\config\Settings.example.json"
$filterSettings = Join-Path $buildRootFull "Filter\Settings.json"
$filterLicense = Join-Path $buildRootFull "Filter\KMTGuard-License.txt"
$settingsPath = Join-Path $buildRootFull "Filter\Settings.json"
$versionPath = Join-Path $repoRoot "VERSION.txt"
$releaseFolders = @("Filter", "DLL", "Media", "ServerAddons")
$serviceDefinitions = @(
    @{ Role = "Agent"; Executable = "KMTGuard.Agent.exe" },
    @{ Role = "Download"; Executable = "KMTGuard.Download.exe" },
    @{ Role = "Gateway"; Executable = "KMTGuard.Gateway.exe" }
)

if (-not (Test-Path -LiteralPath $versionPath -PathType Leaf)) {
    throw "Required project file VERSION.txt is missing."
}
$productVersion = (Get-Content -LiteralPath $versionPath -Raw).Trim()
if ($productVersion -notmatch '^\d+\.\d+\.\d+$') {
    throw "VERSION.txt must contain one semantic product version."
}

function Copy-Tree([string]$Source, [string]$Destination) {
    if (-not (Test-Path -LiteralPath $Source -PathType Container)) {
        throw "Release source folder is missing: $Source"
    }

    New-Item -ItemType Directory -Path $Destination -Force | Out-Null
    Get-ChildItem -LiteralPath $Source -Force | Copy-Item -Destination $Destination -Recurse -Force
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

function Send-RuntimeCommand([string]$Role, [string]$Command, [int]$TimeoutMilliseconds = 5000) {
    $pipeName = "KMTGuard.Runtime.$Role.v1"
    $reader = $null
    $writer = $null
    $pipe = New-Object System.IO.Pipes.NamedPipeClientStream(
        ".",
        $pipeName,
        [System.IO.Pipes.PipeDirection]::InOut,
        [System.IO.Pipes.PipeOptions]::None)
    try {
        $pipe.Connect($TimeoutMilliseconds)
        $reader = New-Object System.IO.StreamReader($pipe)
        $writer = New-Object System.IO.StreamWriter($pipe)
        $writer.AutoFlush = $true
        $request = @{ command = $Command; payload = $null } | ConvertTo-Json -Compress
        $writer.WriteLine($request)
        $responseLine = $reader.ReadLine()
        if ([string]::IsNullOrWhiteSpace($responseLine)) {
            throw "$Role closed its control pipe without a response."
        }

        return $responseLine | ConvertFrom-Json
    }
    finally {
        if ($null -ne $writer) { $writer.Dispose() }
        if ($null -ne $reader) { $reader.Dispose() }
        $pipe.Dispose()
    }
}

function Wait-ForProcessExit([string]$ExecutableName, [int]$TimeoutSeconds) {
    $processName = [System.IO.Path]::GetFileNameWithoutExtension($ExecutableName)
    $deadline = (Get-Date).AddSeconds($TimeoutSeconds)
    do {
        if ($null -eq (Get-Process -Name $processName -ErrorAction SilentlyContinue)) {
            return
        }
        Start-Sleep -Milliseconds 250
    } while ((Get-Date) -lt $deadline)

    throw "$ExecutableName did not stop within $TimeoutSeconds seconds. Deployment was cancelled without replacing files."
}

function Wait-ForRuntimeReady([string]$Role, [string]$ExecutableName, [int]$TimeoutSeconds = 120) {
    $processName = [System.IO.Path]::GetFileNameWithoutExtension($ExecutableName)
    $deadline = (Get-Date).AddSeconds($TimeoutSeconds)
    $lastError = $null
    do {
        if ($null -eq (Get-Process -Name $processName -ErrorAction SilentlyContinue)) {
            throw "$ExecutableName exited during startup."
        }

        try {
            $response = Send-RuntimeCommand -Role $Role -Command "runtime.ping" -TimeoutMilliseconds 1000
            if ($response.success) {
                return
            }
            $lastError = $response.message
        }
        catch {
            $lastError = $_.Exception.Message
        }
        Start-Sleep -Milliseconds 300
    } while ((Get-Date) -lt $deadline)

    throw "$ExecutableName did not become ready. $lastError"
}

function Start-RuntimeService([hashtable]$Definition) {
    $executablePath = Join-Path $buildRootFull ("Filter\" + $Definition.Executable)
    if (-not (Test-Path -LiteralPath $executablePath -PathType Leaf)) {
        throw "Runtime executable is missing: $executablePath"
    }

    $oldSettingsPath = $env:KMTGUARD_SETTINGS_PATH
    try {
        $env:KMTGUARD_SETTINGS_PATH = $settingsPath
        Start-Process -FilePath $executablePath -WorkingDirectory (Split-Path $executablePath) -WindowStyle Hidden
    }
    finally {
        $env:KMTGUARD_SETTINGS_PATH = $oldSettingsPath
    }

    Wait-ForRuntimeReady -Role $Definition.Role -ExecutableName $Definition.Executable
}

function Stop-RunningRelease([array]$RunningServices, [bool]$DesktopWasRunning) {
    if ($DesktopWasRunning) {
        Get-Process -Name "KMTGuard" -ErrorAction SilentlyContinue | ForEach-Object { [void]$_.CloseMainWindow() }
        Wait-ForProcessExit -ExecutableName "KMTGuard.exe" -TimeoutSeconds 15
    }

    for ($index = $serviceDefinitions.Count - 1; $index -ge 0; $index--) {
        $definition = $serviceDefinitions[$index]
        if ($RunningServices -notcontains $definition.Role) {
            continue
        }

        $response = Send-RuntimeCommand -Role $definition.Role -Command "runtime.shutdown"
        if (-not $response.success) {
            throw "$($definition.Role) rejected the shutdown request: $($response.message)"
        }
        Wait-ForProcessExit -ExecutableName $definition.Executable -TimeoutSeconds 30
    }
}

function Restore-RunningRelease([array]$RunningServices, [bool]$DesktopWasRunning) {
    foreach ($definition in $serviceDefinitions) {
        if ($RunningServices -contains $definition.Role) {
            Start-RuntimeService -Definition $definition
        }
    }

    if ($DesktopWasRunning) {
        $desktopPath = Join-Path $buildRootFull "Filter\KMTGuard.exe"
        Start-Process -FilePath $desktopPath -WorkingDirectory (Split-Path $desktopPath)
    }
}

function Write-ReleaseHashes {
    $requiredOutputs = @(
        (Join-Path $buildRootFull "Filter\KMTGuard.exe"),
        (Join-Path $buildRootFull "Filter\KMTGuard.Agent.exe"),
        (Join-Path $buildRootFull "Filter\KMTGuard.Download.exe"),
        (Join-Path $buildRootFull "Filter\KMTGuard.Gateway.exe"),
        (Join-Path $buildRootFull "DLL\KMTGuardKit.dll"),
        (Join-Path $buildRootFull "ServerAddons\GameServer\KMTGuard_GameServer.dll"),
        (Join-Path $buildRootFull "ServerAddons\ShardManager\KMTGuard_ShardManager.dll"),
        (Join-Path $buildRootFull "Licensing\LicenseServer\KMTGuard.LicenseServer.exe"),
        (Join-Path $buildRootFull "Licensing\LicenseCenter\KMTGuard.LicenseAdmin.exe")
    )
    foreach ($output in $requiredOutputs) {
        if (-not (Test-Path -LiteralPath $output -PathType Leaf)) {
            throw "Required final output is missing: $output"
        }
    }

    $hashes = $requiredOutputs | ForEach-Object {
        $hash = Get-FileHash -LiteralPath $_ -Algorithm SHA256
        $relativePath = $_.Substring($buildRootFull.Length + 1).Replace('\', '/')
        "$($hash.Hash)  $relativePath"
    }
    [System.IO.File]::WriteAllLines(
        (Join-Path $buildRootFull "RELEASE-SHA256.txt"),
        $hashes,
        (New-Object System.Text.UTF8Encoding($false)))

    return $requiredOutputs
}

New-Item -ItemType Directory -Path $stageBuild -Force | Out-Null
Write-Host "Building the complete KMTGuard release in staging..." -ForegroundColor Cyan

& (Join-Path $PSScriptRoot "Publish-KmtGuardSplit.ps1") `
    -Configuration $Configuration `
    -Destination (Join-Path $stageBuild "Filter")
if ($LASTEXITCODE -ne 0) { throw "Split filter publish failed." }

if (-not (Test-Path -LiteralPath $settingsTemplate -PathType Leaf)) {
    throw "The filter Settings.json template is missing: $settingsTemplate"
}
Copy-Item -LiteralPath $settingsTemplate -Destination (Join-Path $stageBuild "Filter\Settings.json") -Force

$clientDll = Join-Path $repoRoot "JTClientLibrary\BinOut\Release\KMTGuardKit.dll"
if (-not (Test-Path -LiteralPath $clientDll -PathType Leaf)) {
    throw "The Release client DLL is missing: $clientDll"
}
$dllRoot = Join-Path $stageBuild "DLL"
New-Item -ItemType Directory -Path $dllRoot -Force | Out-Null
Copy-Item -LiteralPath $clientDll -Destination (Join-Path $dllRoot "KMTGuardKit.dll") -Force
Copy-Item -LiteralPath $versionPath -Destination (Join-Path $dllRoot "VERSION.txt") -Force
Assert-ProductVersion $clientDll
foreach ($optionalDll in @(
    (Join-Path $repoRoot "JTClientLibrary\BinOut\Release\WebView2Loader.dll"),
    (Join-Path $repoRoot "JTClientLibrary\BinOut\RelWithDebInfo\WebView2Loader.dll"),
    (Join-Path $repoRoot "BinOut\Win32\Release\WebViewerBridge.dll")
)) {
    if (Test-Path -LiteralPath $optionalDll -PathType Leaf) {
        $destination = Join-Path $dllRoot ([System.IO.Path]::GetFileName($optionalDll))
        if (-not (Test-Path -LiteralPath $destination -PathType Leaf)) {
            Copy-Item -LiteralPath $optionalDll -Destination $destination -Force
        }
    }
}

$mediaRoot = Join-Path $stageBuild "Media"
Copy-Tree (Join-Path $repoRoot "JTClientLibrary\clientlibrary") (Join-Path $mediaRoot "clientlibrary")
Copy-Tree (Join-Path $repoRoot "JTClientLibrary\media") (Join-Path $mediaRoot "media")
Copy-Tree (Join-Path $repoRoot "JTClientLibrary\client-resources") (Join-Path $mediaRoot "client-resources")

$addonsRoot = Join-Path $stageBuild "ServerAddons"
$gameServerRoot = Join-Path $addonsRoot "GameServer"
$shardRoot = Join-Path $addonsRoot "ShardManager"
New-Item -ItemType Directory -Path $gameServerRoot, $shardRoot -Force | Out-Null
$gameServerDll = "C:\Gs\KMTGuard_GameServer.dll"
$gameServerConfig = "C:\Gs\KMTGuard-Addon.ini"
$shardDll = Join-Path $repoRoot "ShardManager\vSRO-ShardManager\Outpus\KMTGuard_ShardManager.dll"
$shardConfig = Join-Path $repoRoot "ShardManager\vSRO-ShardManager\Outpus\KMTGuard-Addon.ini"
foreach ($required in @($gameServerDll, $gameServerConfig, $shardDll, $shardConfig)) {
    if (-not (Test-Path -LiteralPath $required -PathType Leaf)) {
        throw "Built server add-on output is missing: $required"
    }
}
Copy-Item -LiteralPath $gameServerDll -Destination $gameServerRoot -Force
Copy-Item -LiteralPath $gameServerConfig -Destination $gameServerRoot -Force
Copy-Item -LiteralPath $shardDll -Destination $shardRoot -Force
Copy-Item -LiteralPath $shardConfig -Destination $shardRoot -Force
Copy-Item -LiteralPath $versionPath -Destination (Join-Path $gameServerRoot "VERSION.txt") -Force
Copy-Item -LiteralPath $versionPath -Destination (Join-Path $shardRoot "VERSION.txt") -Force
Assert-ProductVersion $gameServerDll
Assert-ProductVersion $shardDll
$addonInstructions = @"
KMTGuard Server Add-ons

1. Generate the customer's package from KMTGuard License Center.
2. Copy the GameServer folder contents beside SR_GameServer.exe.
3. Copy the ShardManager folder contents beside ShardManager.exe.
4. Activate Filter\KMTGuard.exe before starting either server component.
5. Keep the generated KMTGuard-License.txt beside each protected server executable.
"@
[System.IO.File]::WriteAllText(
    (Join-Path $addonsRoot "INSTALL.txt"),
    $addonInstructions,
    (New-Object System.Text.UTF8Encoding($false)))

& (Join-Path $PSScriptRoot "Publish-KmtGuardLicensing.ps1") `
    -Configuration $Configuration `
    -DestinationRoot (Join-Path $stageBuild "Licensing")
if ($LASTEXITCODE -ne 0) { throw "Licensing publish failed." }
Copy-Item -LiteralPath $versionPath -Destination (Join-Path $stageBuild "VERSION.txt") -Force
foreach ($stagedProduct in @(
    (Join-Path $stageBuild "Filter\KMTGuard.exe"),
    (Join-Path $stageBuild "Filter\KMTGuard.Agent.exe"),
    (Join-Path $stageBuild "Filter\KMTGuard.Download.exe"),
    (Join-Path $stageBuild "Filter\KMTGuard.Gateway.exe"),
    (Join-Path $stageBuild "Licensing\LicenseServer\KMTGuard.LicenseServer.exe"),
    (Join-Path $stageBuild "Licensing\LicenseCenter\KMTGuard.LicenseAdmin.exe")
)) {
    Assert-ProductVersion $stagedProduct
}

$runningServices = @()
foreach ($definition in $serviceDefinitions) {
    $processName = [System.IO.Path]::GetFileNameWithoutExtension($definition.Executable)
    if ($null -ne (Get-Process -Name $processName -ErrorAction SilentlyContinue)) {
        $runningServices += $definition.Role
    }
}
$desktopWasRunning = $null -ne (Get-Process -Name "KMTGuard" -ErrorAction SilentlyContinue)
$releaseStopped = $false
$deploymentCommitted = $false

try {
    Stop-RunningRelease -RunningServices $runningServices -DesktopWasRunning $desktopWasRunning
    $releaseStopped = $true

    if (Test-Path -LiteralPath $filterSettings -PathType Leaf) {
        Copy-Item -LiteralPath $filterSettings -Destination (Join-Path $stageBuild "Filter\Settings.json") -Force
    }
    if (Test-Path -LiteralPath $filterLicense -PathType Leaf) {
        Copy-Item -LiteralPath $filterLicense -Destination (Join-Path $stageBuild "Filter\KMTGuard-License.txt") -Force
    }
    $existingLogs = Join-Path $buildRootFull "Filter\logs"
    if (Test-Path -LiteralPath $existingLogs -PathType Container) {
        Copy-Tree $existingLogs (Join-Path $stageBuild "Filter\logs")
    }

    New-Item -ItemType Directory -Path $buildRootFull, $backupRoot -Force | Out-Null
    $movedOldFolders = New-Object System.Collections.Generic.List[string]
    $movedNewFolders = New-Object System.Collections.Generic.List[string]
    try {
        $licensingDestination = Join-Path $buildRootFull "Licensing"
        if (Test-Path -LiteralPath $licensingDestination -PathType Container) {
            Copy-Tree $licensingDestination (Join-Path $backupRoot "Licensing")
        }
        foreach ($folder in $releaseFolders) {
            $destination = Join-Path $buildRootFull $folder
            $backup = Join-Path $backupRoot $folder
            $staged = Join-Path $stageBuild $folder
            if (Test-Path -LiteralPath $destination) {
                Move-Item -LiteralPath $destination -Destination $backup
                $movedOldFolders.Add($folder)
            }
            Move-Item -LiteralPath $staged -Destination $destination
            $movedNewFolders.Add($folder)
        }
        Copy-Tree (Join-Path $stageBuild "Licensing") $licensingDestination
        Copy-Item -LiteralPath $versionPath -Destination (Join-Path $buildRootFull "VERSION.txt") -Force
        $deploymentCommitted = $true
    }
    catch {
        for ($index = $movedNewFolders.Count - 1; $index -ge 0; $index--) {
            $folder = $movedNewFolders[$index]
            $destination = Join-Path $buildRootFull $folder
            if (Test-Path -LiteralPath $destination) {
                Remove-Item -LiteralPath $destination -Recurse -Force
            }
        }
        for ($index = $movedOldFolders.Count - 1; $index -ge 0; $index--) {
            $folder = $movedOldFolders[$index]
            $backup = Join-Path $backupRoot $folder
            if (Test-Path -LiteralPath $backup) {
                Move-Item -LiteralPath $backup -Destination (Join-Path $buildRootFull $folder)
            }
        }
        $licensingBackup = Join-Path $backupRoot "Licensing"
        if (Test-Path -LiteralPath $licensingBackup -PathType Container) {
            Copy-Tree $licensingBackup (Join-Path $buildRootFull "Licensing")
        }
        throw
    }

    $requiredOutputs = Write-ReleaseHashes
    foreach ($developerManifest in @(
        "DEVELOPER-TEST-BUILD.txt",
        "DEVELOPER-SHA256.txt",
        "CUSTOMER-PRODUCTION-SHA256.txt"
    )) {
        $developerManifestPath = Join-Path $buildRootFull $developerManifest
        if (Test-Path -LiteralPath $developerManifestPath -PathType Leaf) {
            Remove-Item -LiteralPath $developerManifestPath -Force
        }
    }
    Restore-RunningRelease -RunningServices $runningServices -DesktopWasRunning $desktopWasRunning
    $releaseStopped = $false

    Get-ChildItem -LiteralPath (Split-Path $backupRoot) -Directory -ErrorAction SilentlyContinue |
        Sort-Object LastWriteTime -Descending |
        Select-Object -Skip 2 |
        Remove-Item -Recurse -Force

    Write-Host "KMTGuard release is ready: $buildRootFull" -ForegroundColor Green
    $requiredOutputs | Get-Item | Select-Object FullName, Length, LastWriteTime
}
catch {
    if ($releaseStopped) {
        try { Restore-RunningRelease -RunningServices $runningServices -DesktopWasRunning $desktopWasRunning } catch { }
    }
    throw
}
finally {
    $releaseLock.Dispose()
    if (Test-Path -LiteralPath $stageRoot) {
        Remove-Item -LiteralPath $stageRoot -Recurse -Force
    }
    if (-not $deploymentCommitted -and (Test-Path -LiteralPath $backupRoot)) {
        Remove-Item -LiteralPath $backupRoot -Recurse -Force
    }
}
