<?php

namespace App\Services;

use App\Models\ServerProfile;
use PDO;
use RuntimeException;
use Throwable;

final class GameAutomationService
{
    public function __construct(private readonly SqlServerConnectionFactory $connections) {}

    /** @return array<string, mixed> */
    public function run(ServerProfile $profile, string $action, array $input, bool $execute): array
    {
        return match ($action) {
            'accounts.ban' => $this->ban($profile, $input, $execute),
            'accounts.unban' => $this->unban($profile, $input, $execute),
            'accounts.rename-login' => $this->renameLogin($profile, $input, $execute),
            'silk.bulk-add' => $this->bulkSilk($profile, $input, $execute),
            'characters.reset-pk' => $this->resetPk($profile, $input, $execute),
            'characters.inventory-size' => $this->inventorySize($profile, $input, $execute),
            'characters.hwan' => $this->assignHwan($profile, $input, $execute),
            'characters.find-hwan' => $this->findHwan($profile, $input),
            'characters.rename' => $this->renameCharacter($profile, $input, $execute),
            'characters.clear-timed-jobs' => $this->clearTimedJobs($profile, $input, $execute),
            'inventory.find-item' => $this->findItem($profile, $input),
            'inventory.add-item' => $this->addItem($profile, $input, $execute),
            'skills.toggle' => $this->toggleSkill($profile, $input, $execute),
            'monsters.lookup-object' => $this->lookupObject($profile, $input),
            'monsters.list-uniques' => $this->listUniques($profile),
            'monsters.set-unique' => $this->setUnique($profile, $input, $execute),
            'monsters.change-stats' => $this->changeMonsterStats($profile, $input, $execute),
            'monsters.multiply-exp' => $this->multiplyMonsterExp($profile, $input, $execute),
            'monsters.replace-spawn' => $this->replaceSpawnMonster($profile, $input, $execute),
            'monsters.create-spawn' => $this->createSpawn($profile, $input, $execute),
            'monsters.spawn-settings' => $this->spawnSettings($profile, $input, $execute),
            'monsters.fix-fortress-uniques' => $this->fixFortressUniques($profile, $execute),
            'drops.assign' => $this->assignDrop($profile, $input, $execute),
            'drops.remove-item' => $this->removeDrop($profile, $input, $execute),
            'drops.disable-family' => $this->disableDropFamily($profile, $input, $execute),
            'economy.max-stack' => $this->maxStack($profile, $input, $execute),
            'economy.gacha-rate' => $this->gachaRate($profile, $input, $execute),
            'economy.job-exp-rate' => $this->jobExpRate($profile, $input, $execute),
            'economy.sox-rate' => $this->soxRate($profile, $input, $execute),
            'fortress.clear-ownership' => $this->clearFortressOwnership($profile, $execute),
            default => throw new RuntimeException('This automation does not have a reviewed adapter.'),
        };
    }

    private function ban(ServerProfile $profile, array $input, bool $execute): array
    {
        [$character, $jid] = $this->characterAndJid($profile, $input['char_name']);
        $pdo = $this->connections->connect($profile, $profile->account_database);
        $user = $this->one($pdo, 'SELECT [JID], [StrUserID] FROM [dbo].[TB_User] WHERE [JID] = ?', [$jid]);
        if (! $user) {
            throw new RuntimeException('The linked account was not found in TB_User.');
        }
        $existing = (int) $this->scalar($pdo, 'SELECT COUNT_BIG(*) FROM [dbo].[_BlockedUser] WHERE [UserJID] = ?', [$jid]);
        $before = ['character' => $character, 'account' => $user, 'active_block_rows' => $existing];
        if (! $execute) {
            return $this->result('preview', $before, ['Ban record will be created until '.$input['end_at'].'.']);
        }
        if ($existing > 0) {
            throw new RuntimeException('This account already has a blocked-user record. Remove or review it first.');
        }

        return $this->transaction($pdo, function (PDO $pdo) use ($character, $jid, $user, $input, $before) {
            $now = now()->format('Y-m-d H:i:s');
            $statement = $pdo->prepare(<<<'SQL'
INSERT INTO [dbo].[_Punishment]
([UserJID],[Type],[Executor],[Shard],[CharName],[CharInfo],[PosInfo],[Guide],[Description],[RaiseTime],[BlockStartTime],[BlockEndTime],[PunishTime],[Status])
OUTPUT INSERTED.[SerialNo]
VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
SQL);
            $statement->execute([$jid, 1, 'CASY', 0, $character['CharName16'], (string) $character['CharID'], (string) $character['LatestRegion'], $input['reason'], $input['reason'], $now, $now, $input['end_at'], $input['end_at'], 0]);
            $serial = (int) $statement->fetchColumn();
            $blocked = $pdo->prepare('INSERT INTO [dbo].[_BlockedUser] ([UserJID],[UserID],[Type],[SerialNo],[timeBegin],[timeEnd]) VALUES (?,?,?,?,?,?)');
            $blocked->execute([$jid, $user['StrUserID'], 1, $serial, $now, $input['end_at']]);
            return $this->result('executed', $before, ['Account blocked successfully.'], ['serial' => $serial, 'jid' => $jid, 'login' => $user['StrUserID']]);
        });
    }

    private function unban(ServerProfile $profile, array $input, bool $execute): array
    {
        [$character, $jid] = $this->characterAndJid($profile, $input['char_name']);
        $pdo = $this->connections->connect($profile, $profile->account_database);
        $blocked = (int) $this->scalar($pdo, 'SELECT COUNT_BIG(*) FROM [dbo].[_BlockedUser] WHERE [UserJID] = ?', [$jid]);
        $punishments = (int) $this->scalar($pdo, 'SELECT COUNT_BIG(*) FROM [dbo].[_Punishment] WHERE [UserJID] = ?', [$jid]);
        $before = ['character' => $character, 'jid' => $jid, 'blocked_rows' => $blocked, 'punishment_rows' => $punishments];
        if (! $execute) {
            return $this->result('preview', $before, ['All linked ban rows will be removed.']);
        }
        return $this->transaction($pdo, function (PDO $pdo) use ($jid, $before) {
            $first = $pdo->prepare('DELETE FROM [dbo].[_BlockedUser] WHERE [UserJID] = ?');
            $first->execute([$jid]);
            $second = $pdo->prepare('DELETE FROM [dbo].[_Punishment] WHERE [UserJID] = ?');
            $second->execute([$jid]);
            return $this->result('executed', $before, ['Ban records removed.'], ['removed' => $first->rowCount() + $second->rowCount()]);
        });
    }

    private function renameLogin(ServerProfile $profile, array $input, bool $execute): array
    {
        $pdo = $this->connections->connect($profile, $profile->account_database);
        $account = $this->one($pdo, 'SELECT [JID], [StrUserID], [Status], [GMrank] FROM [dbo].[TB_User] WHERE [StrUserID] = ?', [$input['current_login']]);
        if (! $account) {
            throw new RuntimeException('Current account login was not found.');
        }
        if ((int) $this->scalar($pdo, 'SELECT COUNT_BIG(*) FROM [dbo].[TB_User] WHERE [StrUserID] = ?', [$input['new_login']]) > 0) {
            throw new RuntimeException('The new account login is already in use.');
        }
        if (! $execute) {
            return $this->result('preview', $account, ['Login will change to '.$input['new_login'].'.']);
        }
        $statement = $pdo->prepare('UPDATE [dbo].[TB_User] SET [StrUserID] = ? WHERE [JID] = ? AND [StrUserID] = ?');
        $statement->execute([$input['new_login'], $account['JID'], $input['current_login']]);
        return $this->result('executed', $account, ['Account login renamed.'], ['JID' => $account['JID'], 'StrUserID' => $input['new_login']]);
    }

    private function bulkSilk(ServerProfile $profile, array $input, bool $execute): array
    {
        $pdo = $this->connections->connect($profile, $profile->account_database);
        $column = in_array($input['silk_type'], ['silk_own', 'silk_gift', 'silk_point'], true) ? $input['silk_type'] : throw new RuntimeException('Invalid silk type.');
        $accounts = (int) $pdo->query('SELECT COUNT_BIG(*) FROM [dbo].[TB_User]')->fetchColumn();
        $missing = (int) $pdo->query('SELECT COUNT_BIG(*) FROM [dbo].[TB_User] u WHERE NOT EXISTS (SELECT 1 FROM [dbo].[SK_Silk] s WHERE s.[JID] = u.[JID])')->fetchColumn();
        $beforeTotal = (int) $pdo->query('SELECT COALESCE(SUM(CAST(['.$column.'] AS bigint)), 0) FROM [dbo].[SK_Silk]')->fetchColumn();
        $impact = (int) $input['amount'] * $accounts;
        $before = compact('accounts', 'missing', 'beforeTotal', 'impact') + ['silk_type' => $column, 'amount_each' => (int) $input['amount']];
        if (! $execute) {
            return $this->result('preview', $before, ['Total currency impact: '.number_format($impact).'.']);
        }
        return $this->transaction($pdo, function (PDO $pdo) use ($column, $input, $before) {
            $inserted = $pdo->exec('INSERT INTO [dbo].[SK_Silk] ([JID],[silk_own],[silk_gift],[silk_point]) SELECT u.[JID],0,0,0 FROM [dbo].[TB_User] u WHERE NOT EXISTS (SELECT 1 FROM [dbo].[SK_Silk] s WHERE s.[JID] = u.[JID])');
            $statement = $pdo->prepare('UPDATE [dbo].[SK_Silk] SET ['.$column.'] = ['.$column.'] + ?');
            $statement->execute([(int) $input['amount']]);
            return $this->result('executed', $before, ['Silk balances updated.'], ['created_rows' => $inserted, 'updated_rows' => $statement->rowCount()]);
        });
    }

    private function resetPk(ServerProfile $profile, array $input, bool $execute): array
    {
        $pdo = $this->connections->connect($profile, $profile->shard_database);
        $character = $this->character($pdo, $input['char_name']);
        $before = array_intersect_key($character, array_flip(['CharID', 'CharName16', 'RemainHwanCount', 'DailyPK', 'TotalPK', 'PKPenaltyPoint']));
        if (! $execute) {
            return $this->result('preview', $before, ['PK counters will be reset.']);
        }
        $statement = $pdo->prepare('UPDATE [dbo].[_Char] SET [RemainHwanCount]=5,[DailyPK]=0,[TotalPK]=0,[PKPenaltyPoint]=0 WHERE [CharID]=?');
        $statement->execute([$character['CharID']]);
        return $this->result('executed', $before, ['PK status reset.'], ['CharID' => $character['CharID'], 'RemainHwanCount' => 5, 'DailyPK' => 0, 'TotalPK' => 0, 'PKPenaltyPoint' => 0]);
    }

    private function inventorySize(ServerProfile $profile, array $input, bool $execute): array
    {
        $pdo = $this->connections->connect($profile, $profile->shard_database);
        $character = $this->character($pdo, $input['char_name']);
        $before = ['CharID' => $character['CharID'], 'CharName16' => $character['CharName16'], 'InventorySize' => $character['InventorySize']];
        if (! $execute) {
            return $this->result('preview', $before, ['Inventory size will become '.$input['inventory_size'].'.']);
        }
        $statement = $pdo->prepare('UPDATE [dbo].[_Char] SET [InventorySize]=? WHERE [CharID]=?');
        $statement->execute([(int) $input['inventory_size'], $character['CharID']]);
        return $this->result('executed', $before, ['Inventory size updated.'], ['CharID' => $character['CharID'], 'InventorySize' => (int) $input['inventory_size']]);
    }

    private function assignHwan(ServerProfile $profile, array $input, bool $execute): array
    {
        $pdo = $this->connections->connect($profile, $profile->shard_database);
        $character = $this->character($pdo, $input['char_name']);
        if ((int) $input['hwan_level'] !== 0 && (int) $this->scalar($pdo, 'SELECT COUNT_BIG(*) FROM [dbo].[_RefHWANLevel] WHERE [HwanLevel]=?', [(int) $input['hwan_level']]) === 0) {
            throw new RuntimeException('That HWAN level is not defined in _RefHWANLevel.');
        }
        $before = ['CharID' => $character['CharID'], 'CharName16' => $character['CharName16'], 'HwanLevel' => $character['HwanLevel']];
        if (! $execute) {
            return $this->result('preview', $before, ['HWAN level will become '.$input['hwan_level'].'.']);
        }
        $statement = $pdo->prepare('UPDATE [dbo].[_Char] SET [HwanLevel]=? WHERE [CharID]=?');
        $statement->execute([(int) $input['hwan_level'], $character['CharID']]);
        return $this->result('executed', $before, ['HWAN title assigned.'], ['CharID' => $character['CharID'], 'HwanLevel' => (int) $input['hwan_level']]);
    }

    private function findHwan(ServerProfile $profile, array $input): array
    {
        $pdo = $this->connections->connect($profile, $profile->shard_database);
        $statement = $pdo->prepare('SELECT TOP 200 [CharID],[CharName16],[CurLevel],[HwanLevel] FROM [dbo].[_Char] WHERE [Deleted]=0 AND [HwanLevel]=? ORDER BY [CharName16]');
        $statement->execute([(int) $input['hwan_level']]);
        $rows = $statement->fetchAll();
        return $this->result('lookup', ['HwanLevel' => (int) $input['hwan_level']], [count($rows).' player(s) found.'], [], $rows);
    }

    private function renameCharacter(ServerProfile $profile, array $input, bool $execute): array
    {
        $pdo = $this->connections->connect($profile, $profile->shard_database);
        $character = $this->character($pdo, $input['current_name']);
        if ((int) $this->scalar($pdo, 'SELECT COUNT_BIG(*) FROM [dbo].[_Char] WHERE [CharName16]=?', [$input['new_name']]) > 0) {
            throw new RuntimeException('The new character name is already in use.');
        }
        $before = ['CharID' => $character['CharID'], 'CharName16' => $character['CharName16']];
        if (! $execute) {
            return $this->result('preview', $before, ['The verified _RenameCharName procedure will be called.']);
        }
        $statement = $pdo->prepare('EXEC [dbo].[_RenameCharName] @CurName=?, @NewName=?');
        $statement->execute([$input['current_name'], $input['new_name']]);
        return $this->result('executed', $before, ['Character renamed through the shard procedure.'], ['CharID' => $character['CharID'], 'CharName16' => $input['new_name']]);
    }

    private function clearTimedJobs(ServerProfile $profile, array $input, bool $execute): array
    {
        $pdo = $this->connections->connect($profile, $profile->shard_database);
        $character = $this->character($pdo, $input['char_name']);
        $statement = $pdo->prepare('SELECT [ID],[Category],[JobID],[TimeToKeep],[Data1],[Data2],[Serial64],[JID] FROM [dbo].[_TimedJob] WHERE [CharID]=? ORDER BY [ID]');
        $statement->execute([$character['CharID']]);
        $rows = $statement->fetchAll();
        $before = ['character' => ['CharID' => $character['CharID'], 'CharName16' => $character['CharName16']], 'timed_jobs' => $rows];
        if (! $execute) {
            return $this->result('preview', $before, [count($rows).' timed-job row(s) will be deleted.']);
        }
        $delete = $pdo->prepare('DELETE FROM [dbo].[_TimedJob] WHERE [CharID]=?');
        $delete->execute([$character['CharID']]);
        return $this->result('executed', $before, ['Timed-job rows cleared.'], ['removed_rows' => $delete->rowCount()]);
    }

    private function findItem(ServerProfile $profile, array $input): array
    {
        $pdo = $this->connections->connect($profile, $profile->shard_database);
        $statement = $pdo->prepare(<<<'SQL'
SELECT TOP 200 r.[CodeName128], i.[ID64], i.[OptLevel], inv.[Slot], c.[CharID], c.[CharName16]
FROM [dbo].[_RefObjCommon] r
JOIN [dbo].[_Items] i ON i.[RefItemID] = r.[ID]
JOIN [dbo].[_Inventory] inv ON inv.[ItemID] = i.[ID64]
JOIN [dbo].[_Char] c ON c.[CharID] = inv.[CharID]
WHERE r.[CodeName128] LIKE ?
ORDER BY c.[CharName16], inv.[Slot]
SQL);
        $statement->execute(['%'.$input['item_code'].'%']);
        $rows = $statement->fetchAll();
        return $this->result('lookup', ['item_code' => $input['item_code']], [count($rows).' inventory match(es) found.'], [], $rows);
    }

    private function addItem(ServerProfile $profile, array $input, bool $execute): array
    {
        $pdo = $this->connections->connect($profile, $profile->shard_database);
        $character = $this->character($pdo, $input['char_name']);
        $item = $this->objectByCode($pdo, $input['item_code']);
        $before = ['character' => ['CharID' => $character['CharID'], 'CharName16' => $character['CharName16']], 'item' => $item, 'amount' => (int) $input['amount'], 'plus' => (int) $input['plus']];
        if (! $execute) {
            return $this->result('preview', $before, ['The verified _ADD_ITEM_EXTERN procedure will be called.']);
        }
        $statement = $pdo->prepare('EXEC [dbo].[_ADD_ITEM_EXTERN] @charname=?, @codename=?, @data=?, @opt_level=?');
        $statement->execute([$character['CharName16'], $item['CodeName128'], (int) $input['amount'], (int) $input['plus']]);
        return $this->result('executed', $before, ['Item delivery procedure completed.'], ['procedure' => '_ADD_ITEM_EXTERN']);
    }

    private function toggleSkill(ServerProfile $profile, array $input, bool $execute): array
    {
        $pdo = $this->connections->connect($profile, $profile->shard_database);
        $skill = $this->one($pdo, 'SELECT [ID],[Basic_Code],[Basic_Name],[Service] FROM [dbo].[_RefSkill] WHERE [Basic_Code]=?', [$input['skill_code']]);
        if (! $skill) {
            throw new RuntimeException('Skill Basic_Code was not found.');
        }
        if (! $execute) {
            return $this->result('preview', $skill, ['Skill Service will become '.$input['service'].'.']);
        }
        $statement = $pdo->prepare('UPDATE [dbo].[_RefSkill] SET [Service]=? WHERE [ID]=? AND [Basic_Code]=?');
        $statement->execute([(int) $input['service'], $skill['ID'], $skill['Basic_Code']]);
        return $this->result('executed', $skill, ['Skill state updated.'], ['ID' => $skill['ID'], 'Basic_Code' => $skill['Basic_Code'], 'Service' => (int) $input['service']]);
    }

    private function lookupObject(ServerProfile $profile, array $input): array
    {
        $pdo = $this->connections->connect($profile, $profile->shard_database);
        $statement = $pdo->prepare('SELECT TOP 200 [ID],[CodeName128],[NameStrID128],[TypeID1],[TypeID2],[TypeID3],[TypeID4],[Rarity],[Service] FROM [dbo].[_RefObjCommon] WHERE [CodeName128] LIKE ? ORDER BY [CodeName128]');
        $statement->execute(['%'.$input['code_name'].'%']);
        $rows = $statement->fetchAll();
        return $this->result('lookup', ['code_name' => $input['code_name']], [count($rows).' object(s) found.'], [], $rows);
    }

    private function listUniques(ServerProfile $profile): array
    {
        $pdo = $this->connections->connect($profile, $profile->shard_database);
        $rows = $pdo->query("SELECT TOP 300 [ID],[CodeName128],[NameStrID128],[Service],[Rarity] FROM [dbo].[_RefObjCommon] WHERE [Rarity]=3 AND [CodeName128] LIKE 'MOB%' ORDER BY [CodeName128]")->fetchAll();
        return $this->result('lookup', [], [count($rows).' unique monster definition(s) returned.'], [], $rows);
    }

    private function setUnique(ServerProfile $profile, array $input, bool $execute): array
    {
        $pdo = $this->connections->connect($profile, $profile->shard_database);
        $mob = $this->objectByCode($pdo, $input['mob_code']);
        if (! str_starts_with(strtoupper($mob['CodeName128']), 'MOB')) {
            throw new RuntimeException('The selected object is not a monster CodeName.');
        }
        if (! $execute) {
            return $this->result('preview', $mob, ['Rarity will become 3.']);
        }
        $statement = $pdo->prepare('UPDATE [dbo].[_RefObjCommon] SET [Rarity]=3 WHERE [ID]=?');
        $statement->execute([$mob['ID']]);
        return $this->result('executed', $mob, ['Monster converted to unique rarity.'], ['ID' => $mob['ID'], 'CodeName128' => $mob['CodeName128'], 'Rarity' => 3]);
    }

    private function changeMonsterStats(ServerProfile $profile, array $input, bool $execute): array
    {
        $pdo = $this->connections->connect($profile, $profile->shard_database);
        $mob = $this->mobDetails($pdo, $input['mob_code']);
        $after = ['Lvl' => (int) $input['level'], 'MaxHP' => (int) round((int) $mob['MaxHP'] * (float) $input['hp_multiplier']), 'ExpToGive' => (int) round((int) $mob['ExpToGive'] * (float) $input['exp_multiplier'])];
        if (! $execute) {
            return $this->result('preview', $mob, ['Monster stats will be updated.'], $after);
        }
        $statement = $pdo->prepare('UPDATE [dbo].[_RefObjChar] SET [Lvl]=?,[MaxHP]=?,[ExpToGive]=? WHERE [ID]=?');
        $statement->execute([$after['Lvl'], $after['MaxHP'], $after['ExpToGive'], $mob['Link']]);
        return $this->result('executed', $mob, ['Monster level, HP and EXP updated.'], $after + ['ID' => $mob['Link']]);
    }

    private function multiplyMonsterExp(ServerProfile $profile, array $input, bool $execute): array
    {
        $pdo = $this->connections->connect($profile, $profile->shard_database);
        $mob = $this->mobDetails($pdo, $input['mob_code']);
        $newExp = (int) round((int) $mob['ExpToGive'] * (float) $input['multiplier']);
        if (! $execute) {
            return $this->result('preview', $mob, ['EXP will become '.number_format($newExp).'.'], ['ExpToGive' => $newExp]);
        }
        $statement = $pdo->prepare('UPDATE [dbo].[_RefObjChar] SET [ExpToGive]=? WHERE [ID]=?');
        $statement->execute([$newExp, $mob['Link']]);
        return $this->result('executed', $mob, ['Monster EXP updated.'], ['ID' => $mob['Link'], 'ExpToGive' => $newExp]);
    }

    private function replaceSpawnMonster(ServerProfile $profile, array $input, bool $execute): array
    {
        $pdo = $this->connections->connect($profile, $profile->shard_database);
        $old = $this->objectByCode($pdo, $input['old_mob_code']);
        $new = $this->objectByCode($pdo, $input['new_mob_code']);
        $count = (int) $this->scalar($pdo, 'SELECT COUNT_BIG(*) FROM [dbo].[Tab_RefTactics] WHERE [dwObjID]=?', [$old['ID']]);
        $before = ['current' => $old, 'replacement' => $new, 'affected_tactics' => $count];
        if (! $execute) {
            return $this->result('preview', $before, [$count.' tactics row(s) will be changed.']);
        }
        $statement = $pdo->prepare('UPDATE [dbo].[Tab_RefTactics] SET [dwObjID]=? WHERE [dwObjID]=?');
        $statement->execute([$new['ID'], $old['ID']]);
        return $this->result('executed', $before, ['Spawn monster references replaced.'], ['updated_rows' => $statement->rowCount()]);
    }

    private function createSpawn(ServerProfile $profile, array $input, bool $execute): array
    {
        $pdo = $this->connections->connect($profile, $profile->shard_database);
        $mob = $this->objectByCode($pdo, $input['mob_code']);
        $character = $this->character($pdo, $input['char_name']);
        $before = ['mob' => $mob, 'position' => array_intersect_key($character, array_flip(['CharID', 'CharName16', 'WorldID', 'LatestRegion', 'PosX', 'PosY', 'PosZ'])), 'settings' => array_intersect_key($input, array_flip(['delay_min', 'delay_max', 'radius', 'generate_radius']))];
        if (! $execute) {
            return $this->result('preview', $before, ['One new Hive, Tactics and Nest row will be created.']);
        }
        return $this->transaction($pdo, function (PDO $pdo) use ($mob, $character, $input, $before) {
            $tacticsId = (int) $pdo->query('SELECT ISNULL(MAX([dwTacticsID]),0)+1 FROM [dbo].[Tab_RefTactics] WITH (UPDLOCK,HOLDLOCK)')->fetchColumn();
            $hiveId = (int) $pdo->query('SELECT ISNULL(MAX([dwHiveID]),0)+1 FROM [dbo].[Tab_RefHive] WITH (UPDLOCK,HOLDLOCK)')->fetchColumn();
            $nestId = (int) $pdo->query('SELECT ISNULL(MAX([dwNestID]),0)+1 FROM [dbo].[Tab_RefNest] WITH (UPDLOCK,HOLDLOCK)')->fetchColumn();
            $tactics = $pdo->prepare('INSERT INTO [dbo].[Tab_RefTactics] ([dwTacticsID],[dwObjID],[btAIQoS],[nMaxStamina],[btMaxStaminaVariance],[nSightRange],[btAggressType],[AggressData],[btChangeTarget],[btHelpRequestTo],[btHelpResponseTo],[btBattleStyle],[BattleStyleData],[btDiversionBasis],[DiversionBasisData1],[DiversionBasisData2],[DiversionBasisData3],[DiversionBasisData4],[DiversionBasisData5],[DiversionBasisData6],[DiversionBasisData7],[DiversionBasisData8],[btDiversionKeepBasis],[DiversionKeepBasisData1],[DiversionKeepBasisData2],[DiversionKeepBasisData3],[DiversionKeepBasisData4],[DiversionKeepBasisData5],[DiversionKeepBasisData6],[DiversionKeepBasisData7],[DiversionKeepBasisData8],[btKeepDistance],[KeepDistanceData],[btTraceType],[btTraceBoundary],[TraceData],[btHomingType],[HomingData],[btAggressTypeOnHoming],[btFleeType],[dwChampionTacticsID],[AdditionOptionFlag],[szDescString128]) VALUES (?,?,0,500,50,200,0,0,2,2,2,0,0,5,0,0,0,0,0,30,0,0,4,0,0,0,0,0,0,0,0,0,0,0,1,500,0,0,2,0,0,0,?)');
            $tactics->execute([$tacticsId, $mob['ID'], $mob['CodeName128']]);
            $hive = $pdo->prepare('INSERT INTO [dbo].[Tab_RefHive] ([dwHiveID],[btKeepMonsterCountType],[dwOverwriteMaxTotalCount],[fMonsterCountPerPC],[dwSpawnSpeedIncreaseRate],[dwMaxIncreaseRate],[btFlag],[GameWorldID],[HatchObjType],[szDescString128]) VALUES (?,0,1,0,0,0,0,?,1,?)');
            $hive->execute([$hiveId, $character['WorldID'], $mob['CodeName128']]);
            $nest = $pdo->prepare('INSERT INTO [dbo].[Tab_RefNest] ([dwNestID],[dwHiveID],[dwTacticsID],[nRegionDBID],[fLocalPosX],[fLocalPosY],[fLocalPosZ],[wInitialDir],[nRadius],[nGenerateRadius],[nChampionGenPercentage],[dwDelayTimeMin],[dwDelayTimeMax],[dwMaxTotalCount],[btFlag],[btRespawn],[btType]) VALUES (?,?,?,?,?,?,?,0,?,?,0,?,?,1,0,1,0)');
            $nest->execute([$nestId, $hiveId, $tacticsId, $character['LatestRegion'], $character['PosX'], $character['PosY'], $character['PosZ'], (int) $input['radius'], (int) $input['generate_radius'], (int) $input['delay_min'], (int) $input['delay_max']]);
            return $this->result('executed', $before, ['Spawn graph created.'], ['tactics_id' => $tacticsId, 'hive_id' => $hiveId, 'nest_id' => $nestId]);
        });
    }

    private function spawnSettings(ServerProfile $profile, array $input, bool $execute): array
    {
        $pdo = $this->connections->connect($profile, $profile->shard_database);
        $mob = $this->objectByCode($pdo, $input['mob_code']);
        $statement = $pdo->prepare('SELECT n.[dwNestID],n.[dwDelayTimeMin],n.[dwDelayTimeMax],n.[dwMaxTotalCount] FROM [dbo].[Tab_RefNest] n JOIN [dbo].[Tab_RefTactics] t ON t.[dwTacticsID]=n.[dwTacticsID] WHERE t.[dwObjID]=? ORDER BY n.[dwNestID]');
        $statement->execute([$mob['ID']]);
        $rows = $statement->fetchAll();
        if (! $rows) {
            throw new RuntimeException('No Nest rows were found for this monster.');
        }
        if (! $execute) {
            return $this->result('preview', ['mob' => $mob, 'nests' => $rows], [count($rows).' Nest row(s) will be updated.']);
        }
        $update = $pdo->prepare('UPDATE n SET n.[dwDelayTimeMin]=?,n.[dwDelayTimeMax]=?,n.[dwMaxTotalCount]=? FROM [dbo].[Tab_RefNest] n JOIN [dbo].[Tab_RefTactics] t ON t.[dwTacticsID]=n.[dwTacticsID] WHERE t.[dwObjID]=?');
        $update->execute([(int) $input['delay_min'], (int) $input['delay_max'], (int) $input['max_count'], $mob['ID']]);
        return $this->result('executed', ['mob' => $mob, 'nests' => $rows], ['Spawn settings updated.'], ['updated_rows' => $update->rowCount()]);
    }

    private function assignDrop(ServerProfile $profile, array $input, bool $execute): array
    {
        $pdo = $this->connections->connect($profile, $profile->shard_database);
        $mob = $this->objectByCode($pdo, $input['mob_code']);
        $item = $this->objectByCode($pdo, $input['item_code']);
        $existing = (int) $this->scalar($pdo, 'SELECT COUNT_BIG(*) FROM [dbo].[_RefMonster_AssignedItemDrop] WHERE [RefMonsterID]=? AND [RefItemID]=?', [$mob['ID'], $item['ID']]);
        $before = ['mob' => $mob, 'item' => $item, 'existing_rows' => $existing, 'drop_ratio' => (float) $input['drop_ratio'], 'amount_min' => (int) $input['amount_min'], 'amount_max' => (int) $input['amount_max'], 'plus' => (int) $input['plus']];
        if (! $execute) {
            return $this->result('preview', $before, ['One drop assignment will be inserted.']);
        }
        if ($existing > 0) {
            throw new RuntimeException('This mob and item already have an assigned drop row.');
        }
        $statement = $pdo->prepare('INSERT INTO [dbo].[_RefMonster_AssignedItemDrop] ([RefMonsterID],[RefItemID],[DropGroupType],[OptLevel],[DropAmountMin],[DropAmountMax],[DropRatio],[RefMagicOptionID1],[CustomValue1],[RefMagicOptionID2],[CustomValue2],[RefMagicOptionID3],[CustomValue3],[RefMagicOptionID4],[CustomValue4],[RefMagicOptionID5],[CustomValue5],[RefMagicOptionID6],[CustomValue6],[RefMagicOptionID7],[CustomValue7],[RefMagicOptionID8],[CustomValue8],[RefMagicOptionID9],[CustomValue9],[RentCodeName]) VALUES (?,?,?,?,?,?,?,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,?)');
        $statement->execute([$mob['ID'], $item['ID'], 0, (int) $input['plus'], (int) $input['amount_min'], (int) $input['amount_max'], (float) $input['drop_ratio'], '']);
        return $this->result('executed', $before, ['Drop assignment created.'], ['inserted_rows' => $statement->rowCount()]);
    }

    private function removeDrop(ServerProfile $profile, array $input, bool $execute): array
    {
        $pdo = $this->connections->connect($profile, $profile->shard_database);
        $item = $this->objectByCode($pdo, $input['item_code']);
        $count = (int) $this->scalar($pdo, 'SELECT COUNT_BIG(*) FROM [dbo].[_RefDropItemAssign] WHERE [RefItemID]=?', [$item['ID']]);
        $before = ['item' => $item, 'assignment_rows' => $count];
        if (! $execute) {
            return $this->result('preview', $before, [$count.' standard assignment row(s) will be deleted.']);
        }
        $statement = $pdo->prepare('DELETE FROM [dbo].[_RefDropItemAssign] WHERE [RefItemID]=?');
        $statement->execute([$item['ID']]);
        return $this->result('executed', $before, ['Drop assignments removed.'], ['removed_rows' => $statement->rowCount()]);
    }

    private function disableDropFamily(ServerProfile $profile, array $input, bool $execute): array
    {
        $pdo = $this->connections->connect($profile, $profile->shard_database);
        $prefix = strtoupper($input['item_code_prefix']);
        $like = $prefix.'%';
        $count = (int) $this->scalar($pdo, 'SELECT COUNT_BIG(*) FROM [dbo].[_RefDropItemAssign] d JOIN [dbo].[_RefObjCommon] c ON c.[ID]=d.[RefItemID] WHERE c.[CodeName128] LIKE ? AND d.[Service]<>0', [$like]);
        $sample = $pdo->prepare('SELECT TOP 100 c.[ID],c.[CodeName128],d.[Service],d.[DropCount],d.[Prob_Relative],d.[Prob_Absolute] FROM [dbo].[_RefDropItemAssign] d JOIN [dbo].[_RefObjCommon] c ON c.[ID]=d.[RefItemID] WHERE c.[CodeName128] LIKE ? AND d.[Service]<>0 ORDER BY c.[CodeName128]');
        $sample->execute([$like]);
        $before = ['prefix' => $prefix, 'active_rows' => $count, 'sample' => $sample->fetchAll()];
        if (! $execute) {
            return $this->result('preview', $before, [$count.' active drop row(s) will be disabled.']);
        }
        $statement = $pdo->prepare('UPDATE d SET d.[Service]=0 FROM [dbo].[_RefDropItemAssign] d JOIN [dbo].[_RefObjCommon] c ON c.[ID]=d.[RefItemID] WHERE c.[CodeName128] LIKE ? AND d.[Service]<>0');
        $statement->execute([$like]);
        return $this->result('executed', $before, ['Matching drop family disabled.'], ['updated_rows' => $statement->rowCount(), 'prefix' => $prefix]);
    }

    private function maxStack(ServerProfile $profile, array $input, bool $execute): array
    {
        $pdo = $this->connections->connect($profile, $profile->shard_database);
        $item = $this->objectByCode($pdo, $input['item_code']);
        $row = $this->one($pdo, 'SELECT [ID],[MaxStack] FROM [dbo].[_RefObjItem] WHERE [ID]=?', [$item['Link']]);
        if (! $row) {
            throw new RuntimeException('The selected object has no linked _RefObjItem row.');
        }
        $before = ['item' => $item, 'MaxStack' => (int) $row['MaxStack']];
        $after = ['ID' => (int) $row['ID'], 'MaxStack' => (int) $input['max_stack'], 'media_sync_required' => true];
        if (! $execute) {
            return $this->result('preview', $before, ['MaxStack will change and the same value must be synchronized to client itemdata.'], $after);
        }
        $statement = $pdo->prepare('UPDATE [dbo].[_RefObjItem] SET [MaxStack]=? WHERE [ID]=?');
        $statement->execute([$after['MaxStack'], $row['ID']]);
        return $this->result('executed', $before, ['Item MaxStack updated. Client media still requires synchronization.'], $after);
    }

    private function gachaRate(ServerProfile $profile, array $input, bool $execute): array
    {
        $pdo = $this->connections->connect($profile, $profile->shard_database);
        $setId = (int) $input['set_id'];
        $multiplier = (float) $input['multiplier'];
        $statement = $pdo->prepare('SELECT [RefItemID],[Ratio],[Count],[GachaID],[Visible] FROM [dbo].[_RefGachaItemSet] WHERE [Set_ID]=? ORDER BY [RefItemID]');
        $statement->execute([$setId]);
        $rows = $statement->fetchAll();
        if (! $rows) {
            throw new RuntimeException('No Magic Pop rows were found for this Set ID.');
        }
        $eligible = count(array_filter($rows, fn (array $row) => (float) $row['Ratio'] * $multiplier <= 32767));
        $before = ['set_id' => $setId, 'rows' => $rows, 'eligible_rows' => $eligible, 'skipped_overflow_rows' => count($rows) - $eligible];
        if (! $execute) {
            return $this->result('preview', $before, [$eligible.' ratio row(s) will be scaled; '.(count($rows) - $eligible).' would exceed SMALLINT and will be skipped.']);
        }
        $update = $pdo->prepare('UPDATE [dbo].[_RefGachaItemSet] SET [Ratio]=CAST(ROUND([Ratio]*?,0) AS smallint) WHERE [Set_ID]=? AND [Ratio]*? BETWEEN 0 AND 32767');
        $update->execute([$multiplier, $setId, $multiplier]);
        return $this->result('executed', $before, ['Magic Pop ratios scaled within the SMALLINT guard.'], ['updated_rows' => $update->rowCount(), 'set_id' => $setId, 'multiplier' => $multiplier]);
    }

    private function jobExpRate(ServerProfile $profile, array $input, bool $execute): array
    {
        $pdo = $this->connections->connect($profile, $profile->shard_database);
        $from = (int) $input['level_from'];
        $to = (int) $input['level_to'];
        $divisor = (float) $input['divisor'];
        $statement = $pdo->prepare('SELECT [Lvl],[JobExp_Trader],[JobExp_Robber],[JobExp_Hunter] FROM [dbo].[_RefLevel] WHERE [Lvl] BETWEEN ? AND ? ORDER BY [Lvl]');
        $statement->execute([$from, $to]);
        $rows = $statement->fetchAll();
        if (! $rows) {
            throw new RuntimeException('No job-level rows were found in this range.');
        }
        $before = ['level_from' => $from, 'level_to' => $to, 'divisor' => $divisor, 'rows' => $rows];
        if (! $execute) {
            return $this->result('preview', $before, [count($rows).' job threshold row(s) will be divided by '.$divisor.'.']);
        }
        $update = $pdo->prepare('UPDATE [dbo].[_RefLevel] SET [JobExp_Trader]=CAST(ROUND([JobExp_Trader]/?,0) AS int),[JobExp_Robber]=CAST(ROUND([JobExp_Robber]/?,0) AS int),[JobExp_Hunter]=CAST(ROUND([JobExp_Hunter]/?,0) AS int) WHERE [Lvl] BETWEEN ? AND ?');
        $update->execute([$divisor, $divisor, $divisor, $from, $to]);
        return $this->result('executed', $before, ['Job EXP thresholds scaled.'], ['updated_rows' => $update->rowCount(), 'divisor' => $divisor]);
    }

    private function soxRate(ServerProfile $profile, array $input, bool $execute): array
    {
        $pdo = $this->connections->connect($profile, $profile->shard_database);
        $from = (int) $input['level_from'];
        $to = (int) $input['level_to'];
        $multiplier = (float) $input['multiplier'];
        $statement = $pdo->prepare('SELECT [MonLevel],[ProbGroup1],[ProbGroup2],[ProbGroup3],[ProbGroup4] FROM [dbo].[_RefDropClassSel_RareEquip] WHERE [MonLevel] BETWEEN ? AND ? ORDER BY [MonLevel]');
        $statement->execute([$from, $to]);
        $rows = $statement->fetchAll();
        if (! $rows) {
            throw new RuntimeException('No SOX probability rows were found in this monster-level range.');
        }
        $before = ['level_from' => $from, 'level_to' => $to, 'multiplier' => $multiplier, 'rows' => $rows, 'probability_groups' => 36];
        if (! $execute) {
            return $this->result('preview', $before, [count($rows).' monster-level row(s) across 36 probability groups will be scaled.']);
        }
        $sets = implode(',', array_map(fn (int $group) => "[ProbGroup{$group}]=[ProbGroup{$group}]*?", range(1, 36)));
        $update = $pdo->prepare('UPDATE [dbo].[_RefDropClassSel_RareEquip] SET '.$sets.' WHERE [MonLevel] BETWEEN ? AND ?');
        $update->execute(array_merge(array_fill(0, 36, $multiplier), [$from, $to]));
        return $this->result('executed', $before, ['SOX probability groups scaled.'], ['updated_rows' => $update->rowCount(), 'probability_groups' => 36, 'multiplier' => $multiplier]);
    }

    private function fixFortressUniques(ServerProfile $profile, bool $execute): array
    {
        $pdo = $this->connections->connect($profile, $profile->shard_database);
        $patterns = ['MOB_FW_KYKLOPES_%', 'MOB_FW_WHITETIGER_%', 'MOB_FW_DARKEAGLE%', 'MOB_FW_HAGIAZO%', 'MOB_FW_BIGSPIDER_%', 'MOB_FW_HANGA%'];
        $where = implode(' OR ', array_fill(0, count($patterns), '[CodeName128] LIKE ?'));
        $statement = $pdo->prepare('SELECT [ID],[CodeName128],[TypeID3],[TypeID4],[Rarity] FROM [dbo].[_RefObjCommon] WHERE '.$where.' ORDER BY [CodeName128]');
        $statement->execute($patterns);
        $rows = $statement->fetchAll();
        if (! $rows) {
            throw new RuntimeException('No matching fortress mob definitions exist in this schema.');
        }
        $before = ['mob_rows' => $rows];
        if (! $execute) {
            return $this->result('preview', $before, [count($rows).' fortress mob row(s) will be classified as TypeID3=1, TypeID4=1, Rarity=3.']);
        }
        $update = $pdo->prepare('UPDATE [dbo].[_RefObjCommon] SET [TypeID3]=1,[TypeID4]=1,[Rarity]=3 WHERE '.$where);
        $update->execute($patterns);
        return $this->result('executed', $before, ['Fortress unique classification repaired.'], ['updated_rows' => $update->rowCount()]);
    }

    private function clearFortressOwnership(ServerProfile $profile, bool $execute): array
    {
        $pdo = $this->connections->connect($profile, $profile->shard_database);
        $statement = $pdo->query('SELECT [FortressID],[GuildID],[TaxRatio],[Tax] FROM [dbo].[_SiegeFortress] WHERE [GuildID]<>0 ORDER BY [FortressID]');
        $rows = $statement->fetchAll();
        if (! $execute) {
            return $this->result('preview', ['fortresses' => $rows], [count($rows).' owned fortress row(s) will be reset.']);
        }
        $updated = $pdo->exec('UPDATE [dbo].[_SiegeFortress] SET [GuildID]=0 WHERE [GuildID]<>0');
        return $this->result('executed', ['fortresses' => $rows], ['Fortress ownership cleared.'], ['updated_rows' => $updated]);
    }

    private function characterAndJid(ServerProfile $profile, string $name): array
    {
        $pdo = $this->connections->connect($profile, $profile->shard_database);
        $character = $this->character($pdo, $name);
        $jid = $this->scalar($pdo, 'SELECT [UserJID] FROM [dbo].[_User] WHERE [CharID]=?', [$character['CharID']]);
        if ($jid === false || $jid === null) {
            throw new RuntimeException('The character is not linked to an account JID.');
        }
        return [$character, (int) $jid];
    }

    private function character(PDO $pdo, string $name): array
    {
        $row = $this->one($pdo, 'SELECT * FROM [dbo].[_Char] WHERE [Deleted]=0 AND [CharName16]=?', [$name]);
        if (! $row) {
            throw new RuntimeException('Player name was not found in the active shard.');
        }
        return $row;
    }

    private function objectByCode(PDO $pdo, string $code): array
    {
        $row = $this->one($pdo, 'SELECT [ID],[CodeName128],[NameStrID128],[TypeID1],[TypeID2],[TypeID3],[TypeID4],[Rarity],[Service],[Link] FROM [dbo].[_RefObjCommon] WHERE [CodeName128]=?', [$code]);
        if (! $row) {
            throw new RuntimeException('The exact object CodeName was not found.');
        }
        return $row;
    }

    private function mobDetails(PDO $pdo, string $code): array
    {
        $row = $this->one($pdo, 'SELECT c.[ID],c.[CodeName128],c.[Rarity],c.[Link],d.[Lvl],d.[MaxHP],d.[ExpToGive] FROM [dbo].[_RefObjCommon] c JOIN [dbo].[_RefObjChar] d ON d.[ID]=c.[Link] WHERE c.[CodeName128]=?', [$code]);
        if (! $row) {
            throw new RuntimeException('The monster and its linked _RefObjChar row were not found.');
        }
        return $row;
    }

    private function one(PDO $pdo, string $sql, array $bindings = []): ?array
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($bindings);
        return $statement->fetch() ?: null;
    }

    private function scalar(PDO $pdo, string $sql, array $bindings = []): mixed
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($bindings);
        return $statement->fetchColumn();
    }

    private function transaction(PDO $pdo, callable $callback): array
    {
        $pdo->beginTransaction();
        try {
            $result = $callback($pdo);
            $pdo->commit();
            return $result;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function result(string $mode, array $before, array $messages, array $after = [], array $rows = []): array
    {
        return compact('mode', 'before', 'after', 'messages', 'rows');
    }
}
