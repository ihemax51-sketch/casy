$ErrorActionPreference = 'Stop'

$repositoryRoot = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$sourcePath = Join-Path $repositoryRoot 'gameserver\source\SilkroadOnline\SR_VIETNAM_SERVICE\v188\Server\SR_GameServer\GameServer\GameServer\src\KMTGuardCustom\CGObjPCCustom.cpp'
$source = Get-Content -LiteralPath $sourcePath -Raw
$guardPath = Join-Path $repositoryRoot 'gameserver\source\SilkroadOnline\SR_VIETNAM_SERVICE\v188\Server\SR_GameServer\GameServer\GameServer\src\KMTGuardCustom\ItemRegionTravelGuard.cpp'
$guard = Get-Content -LiteralPath $guardPath -Raw
$filterInventoryPath = Join-Path $repositoryRoot 'filter\KMTGuardnew\KMTGuard\Server\AgentServer\PacketHandler\Inventory\InventoryPackets.cs'
$filterInventory = Get-Content -LiteralPath $filterInventoryPath -Raw
$filterAdmissionPath = Join-Path $repositoryRoot 'filter\KMTGuardnew\KMTGuard\ServerManagers\RegionControlService.cs'
$filterAdmission = Get-Content -LiteralPath $filterAdmissionPath -Raw

function Assert-True {
    param([bool]$Condition, [string]$Message)
    if (-not $Condition) { throw $Message }
}

$handlerMatch = [regex]::Match(
    $source,
    'void\s+CGObjPC::HandleCustomReverseUseRequest\s*\(CMsg\*\s*pMsg\)\s*\{(?<Body>[\s\S]*?)\r?\n\}',
    [System.Text.RegularExpressions.RegexOptions]::CultureInvariant)

Assert-True $handlerMatch.Success 'The custom Reverse teleport handler is missing.'
$handler = $handlerMatch.Groups['Body'].Value

$deleteIndex = $handler.IndexOf('this->SetLiveDeleteItem(SlotID, 1);')
$effectIndex = $handler.IndexOf('this->SendMsg(pMsg32);')
$moveIndex = $handler.IndexOf('this->MoveTo(targetWorldId')
$approvalIndex = $handler.IndexOf('admissionApproved != 1')

Assert-True ($approvalIndex -ge 0) 'Reverse teleport must require explicit Filter admission approval.'
Assert-True ($deleteIndex -ge 0) 'Reverse teleport must consume its validated scroll.'
Assert-True ($approvalIndex -lt $deleteIndex) 'Reverse admission must be validated before the scroll is consumed.'
Assert-True ($effectIndex -gt $deleteIndex) 'Reverse teleport must send its item-use effect after consuming the scroll.'
Assert-True ($moveIndex -gt $effectIndex) 'Reverse inventory and effect work must finish before the world-transfer handshake starts.'
Assert-True ($handler.Substring($moveIndex) -notmatch 'SetLiveDeleteItem|SendMsg\(pMsg32\)') 'Reverse teleport must not mutate inventory or send the use effect after world transfer starts.'

Assert-True ($source -match '#define\s+FILTER_RETURN_TO_TOWN_PACKET\s+0x3543') 'The authenticated Filter return-to-town packet is missing.'
$townPacketIndex = $source.IndexOf('else if (*pMsg->m_wpMsgId == FILTER_RETURN_TO_TOWN_PACKET)')
Assert-True ($townPacketIndex -ge 0) 'The Filter return-to-town packet handler is missing.'
$townHandler = $source.Substring($townPacketIndex, [Math]::Min(500, $source.Length - $townPacketIndex))
$townAuthIndex = $townHandler.IndexOf('ValidateFilterSessionKey(this, Key)')
$townMoveIndex = $townHandler.IndexOf('this->TeleportToTown();')
Assert-True ($townAuthIndex -ge 0) 'The Filter return-to-town command must authenticate its session key.'
Assert-True ($townMoveIndex -gt $townAuthIndex) 'Return-to-town movement must run only after Filter authentication succeeds.'

Assert-True ($filterInventory -match 'BlockReverseItemActivationAsync\(session\)') 'Reverse item activation must check every persisted native destination before the scroll is forwarded.'
Assert-True ($filterAdmission -match 'destinations\.Count == 0[\s\S]*?return null;') 'A new character with no persisted Reverse destination must be allowed through the native game flow.'
Assert-True ($filterInventory -match 'ArmPostArrivalAdmission\(session, RegionTravelMethod\.Reverse\)') 'Native Reverse travel must retain post-arrival admission enforcement.'
Assert-True ($source -notmatch 'REVERSE_MOVE_AUTHORIZATION_PACKET') 'Native Reverse must not depend on an unverified intermediate client opcode.'
Assert-True ($guard -notmatch 'Blocked unapproved native Reverse move') 'The GameServer must not reject its own native Reverse destination for a missing Filter handshake.'

Assert-True ($guard -match 'ITEM_REGION_BLOCKED_PACKET\s*=\s*0x3572') 'The pre-move item/region denial notice is missing.'
Assert-True ($guard -match 'InspectItemUse') 'The GameServer must inspect the real inventory item at use time.'
Assert-True ($guard -match 'InstanceItem->RefItemID') 'The item/region guard must use the server-owned RefItemID.'
Assert-True ($guard -match 'IsItemBlockedInRegion\(worldId, regionId, itemId\)') 'The target world, region, and item rule check is missing.'
$blockIndex = $guard.IndexOf('SendBlockedNotice(player);', $guard.IndexOf('bool __fastcall MoveToGuard'))
$moveCallIndex = $guard.IndexOf('return s_originalMoveTo(player', $blockIndex)
Assert-True ($blockIndex -ge 0 -and $moveCallIndex -gt $blockIndex) 'The denial decision must run before native MoveTo.'
Assert-True ($guard -match 'KeepPendingForRetry\(player\)') 'MoveTo retry attempts must preserve the denial decision.'

Write-Output 'GameServer Reverse teleport ordering checks: PASS'
