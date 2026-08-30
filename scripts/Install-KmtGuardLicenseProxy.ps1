param(
    [string]$Domain = "license.play-casy.online",
    [string]$ProxyRoot = "C:\laragon\www\kmtguard-license-proxy",
    [string]$VhostPath = "C:\laragon\etc\apache2\sites-enabled\license.play-casy.online.conf",
    [string]$CertificateFile = "C:\laragon\etc\ssl\laragon.crt",
    [string]$CertificateKeyFile = "C:\laragon\etc\ssl\laragon.key",
    [string]$ApacheServiceName = "LaragonApache"
)

$ErrorActionPreference = "Stop"
Set-StrictMode -Version Latest

$repoRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot ".."))
$sourceRoot = Join-Path $repoRoot "deployment\license-proxy"
$indexSource = Join-Path $sourceRoot "public\index.php"
$templatePath = Join-Path $sourceRoot "apache-vhost.conf.template"

foreach ($required in @($indexSource, $templatePath, $CertificateFile, $CertificateKeyFile)) {
    if (-not (Test-Path -LiteralPath $required -PathType Leaf)) {
        throw "Required file is missing: $required"
    }
}

$service = Get-CimInstance Win32_Service -Filter "Name='$ApacheServiceName'"
if ($null -eq $service) {
    throw "Apache service '$ApacheServiceName' was not found."
}
$apacheExecutable = if ($service.PathName -match '^\s*"([^"]+httpd\.exe)"') { $Matches[1] } else { ($service.PathName -split '\s+-k\s+')[0].Trim('"') }
if (-not (Test-Path -LiteralPath $apacheExecutable -PathType Leaf)) {
    throw "Apache executable was not found: $apacheExecutable"
}

New-Item -ItemType Directory -Path $ProxyRoot -Force | Out-Null
Copy-Item -LiteralPath $indexSource -Destination (Join-Path $ProxyRoot "index.php") -Force

$normalizedRoot = $ProxyRoot.Replace('\', '/')
$normalizedCertificate = $CertificateFile.Replace('\', '/')
$normalizedCertificateKey = $CertificateKeyFile.Replace('\', '/')
$vhost = Get-Content -LiteralPath $templatePath -Raw
$vhost = $vhost.Replace('@@DOMAIN@@', $Domain)
$vhost = $vhost.Replace('@@PROXY_ROOT@@', $normalizedRoot)
$vhost = $vhost.Replace('@@CERT_FILE@@', $normalizedCertificate)
$vhost = $vhost.Replace('@@CERT_KEY@@', $normalizedCertificateKey)

$backupPath = $null
if (Test-Path -LiteralPath $VhostPath) {
    $backupPath = "$VhostPath.backup-$(Get-Date -Format 'yyyyMMdd-HHmmss')"
    Copy-Item -LiteralPath $VhostPath -Destination $backupPath -Force
}

try {
    New-Item -ItemType Directory -Path (Split-Path $VhostPath) -Force | Out-Null
    [System.IO.File]::WriteAllText($VhostPath, $vhost, [System.Text.UTF8Encoding]::new($false))

    & $apacheExecutable -t
    if ($LASTEXITCODE -ne 0) {
        throw "Apache rejected the KMTGuard license virtual host."
    }

    Restart-Service -Name $ApacheServiceName -Force
    $deadline = (Get-Date).AddSeconds(20)
    do {
        Start-Sleep -Milliseconds 500
        $current = Get-Service -Name $ApacheServiceName
    } while ($current.Status -ne 'Running' -and (Get-Date) -lt $deadline)
    if ($current.Status -ne 'Running') {
        throw "Apache did not return to the Running state."
    }

    $health = Invoke-RestMethod -Uri "http://127.0.0.1/health" -Headers @{ Host = $Domain } -TimeoutSec 10
    if ($health.status -ne 'healthy') {
        throw "The local license proxy health test did not pass."
    }

    Write-Host "KMTGuard public license proxy is ready for $Domain." -ForegroundColor Green
    Write-Host "Cloudflare DNS still needs: A / license / 54.37.205.126 / Proxied."
}
catch {
    if ($null -ne $backupPath -and (Test-Path -LiteralPath $backupPath)) {
        Copy-Item -LiteralPath $backupPath -Destination $VhostPath -Force
    }
    elseif (Test-Path -LiteralPath $VhostPath) {
        Remove-Item -LiteralPath $VhostPath -Force
    }
    try { Restart-Service -Name $ApacheServiceName -Force } catch { }
    throw
}
