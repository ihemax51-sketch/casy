$ErrorActionPreference = 'Stop'

$repositoryRoot = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$sourceRoot = Join-Path $repositoryRoot 'gameserver\source\SilkroadOnline\SR_VIETNAM_SERVICE\v188\Server\SR_GameServer'
$sqlSource = Get-Content -LiteralPath (Join-Path $sourceRoot 'GameServer\GameServer\src\SqlConnection\sqlCon.cpp') -Raw
$settingsSource = Get-Content -LiteralPath (Join-Path $sourceRoot 'GameServer\GameServer\src\SettingMgr\NewSettings.cpp') -Raw
$desktopSource = Get-Content -LiteralPath (Join-Path $repositoryRoot 'KMTGuard.AdminDesktop\MainWindow.xaml.cs') -Raw
$desktopLayout = Get-Content -LiteralPath (Join-Path $repositoryRoot 'KMTGuard.AdminDesktop\MainWindow.xaml') -Raw
$sqlMigration = Get-Content -LiteralPath (Join-Path $repositoryRoot 'database\migrations\20260810_gameserver_patch_dashboard.sql') -Raw

function Assert-True {
    param([bool]$Condition, [string]$Message)
    if (-not $Condition) { throw $Message }
}

Assert-True ($sqlSource -match 'm_Settings->PENALTY_DROP_LEVEL_MIN') 'PK drop penalty is not applied from its canonical setting.'
Assert-True ($sqlSource -notmatch 'm_Settings->MIN_PK_LEVEL_FOR_DROP_ITEM') 'Retired PK drop setting remains active.'
Assert-True ($sqlSource -match 'm_Settings->MinItemLevelForAstralToTakeEffect') 'Astral activation setting is not applied.'
Assert-True ($sqlSource -match 'm_Settings->ItemLevelForAstralRecovery') 'Astral recovery setting is not applied.'
Assert-True ($sqlSource -notmatch 'm_Settings->STONE_ASTRAL_VALUE') 'Retired combined Astral setting remains active.'
$settingsStart = $sqlSource.IndexOf('bool CSqlCon::ApplyRuntimeSettings()')
$settingsEnd = $sqlSource.IndexOf('CRegionRestrictionDBSet* CSqlCon::GetRegionRestrictionDbSet()', $settingsStart)
Assert-True ($settingsStart -ge 0 -and $settingsEnd -gt $settingsStart) 'Runtime settings function boundaries are missing.'
$settingsMethod = $sqlSource.Substring($settingsStart, $settingsEnd - $settingsStart)
Assert-True ([regex]::Matches($settingsMethod, '#pragma\s+push_macro\("BS_INFO"\)').Count -eq 1) 'Runtime setting detail suppression is not opened exactly once.'
Assert-True ([regex]::Matches($settingsMethod, '#pragma\s+pop_macro\("BS_INFO"\)').Count -eq 1) 'Runtime setting detail suppression is not closed exactly once.'
Assert-True ($settingsMethod.IndexOf('#pragma pop_macro("BS_INFO")') -lt $settingsMethod.IndexOf('Runtime configuration rolled back')) 'Runtime setting failures are hidden from the operator console.'
Assert-True ($sqlSource -notmatch 'questRaiseEventDiagnostic|0x005805C8') 'The withdrawn Quest RaiseEvent runtime patch is still present.'
Assert-True ($settingsSource -match 'KMT_REQUIRE_RANGE\(PENALTY_DROP_LEVEL_MIN, 1, 255\)') 'PK drop range validation is missing.'
Assert-True ($settingsSource -match 'KMT_REQUIRE_RANGE\(MinItemLevelForAstralToTakeEffect, 0, 255\)') 'Astral activation range validation is missing.'
Assert-True ($settingsSource -match 'KMT_REQUIRE_RANGE\(ItemLevelForAstralRecovery, 0, 255\)') 'Astral recovery range validation is missing.'

$loaderSettings = [regex]::Matches($sqlSource, 'strcmp\(\(char\*\)szSettingName, "([^"]+)"\)') |
    ForEach-Object { $_.Groups[1].Value } |
    Sort-Object -Unique
$methodStart = $desktopSource.IndexOf('private static IReadOnlyDictionary<string, SettingDefinition> BuildGameServerSettingDefinitions()')
$methodEnd = $desktopSource.IndexOf('    private enum SettingKind', $methodStart)
Assert-True ($methodStart -ge 0 -and $methodEnd -gt $methodStart) 'Gameserver Patch dashboard catalog is missing.'
$dashboardMethod = $desktopSource.Substring($methodStart, $methodEnd - $methodStart)
$dashboardSettings = [regex]::Matches($dashboardMethod, 'D\("([^"]+)"') |
    ForEach-Object { $_.Groups[1].Value } |
    Sort-Object -Unique
$difference = Compare-Object $loaderSettings $dashboardSettings
Assert-True ($null -eq $difference) 'The Gameserver Patch dashboard does not cover the full GameServer loader catalog.'
Assert-True ($desktopSource -match 'Restart every GameServer after saving') 'The required GameServer restart warning is missing.'
Assert-True ($desktopLayout -match '(?s)<ListBoxItem Tag="Settings">.*?</ListBoxItem>\s*<ListBoxItem Tag="GameServerPatch">') 'Gameserver Patch is not a standalone navigation page directly below Filter Settings.'
Assert-True ($desktopSource -match 'page is "Settings" or "GameServerPatch"') 'The standalone Gameserver Patch page is not routed through the settings editor.'
Assert-True ($desktopSource -match '\.Where\(setting => setting\.Store == _settingsStoreView\)') 'Filter and GameServer settings are not isolated by their owning store.'
Assert-True ($desktopSource -match 'SettingsSectionsPanel\.Visibility = gameServerPage \? Visibility\.Collapsed') 'Gameserver Patch still exposes the Filter Settings section selector.'

foreach ($movedSetting in @('DisableDurability','DisableGreenBook','EnablePartyMonsterSpawn','PartyMonsterMinimumMembers','PartyMonsterSpawnRate')) {
    Assert-True ($sqlMigration -match "DELETE dbo.System_Settings[\s\S]*?$movedSetting") "$movedSetting is not removed from System_Settings."
}

Write-Output "GameServer settings contract tests: PASS ($($loaderSettings.Count) dashboard settings)"
