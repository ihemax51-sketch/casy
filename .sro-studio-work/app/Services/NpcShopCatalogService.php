<?php

namespace App\Services;

use App\Models\ServerProfile;
use PDO;
use RuntimeException;

final class NpcShopCatalogService
{
    public function __construct(private readonly SqlServerConnectionFactory $connections) {}

    public function workspace(ServerProfile $profile, string $search = '', ?string $npcCode = null, ?string $tabCode = null): array
    {
        $database = $profile->shard_database;
        if (! $database) {
            throw new RuntimeException('The shard database is not configured.');
        }
        $pdo = $this->connections->connect($profile, $database);

        $params = [];
        $where = ["g.[Service] = 1", "g.[RefNPCCodeName] <> 'xxx'"];
        if ($search !== '') {
            $where[] = '(g.[RefNPCCodeName] LIKE ? OR o.[NameStrID128] LIKE ? OR o.[CodeName128] LIKE ?)';
            $term = '%'.$search.'%';
            array_push($params, $term, $term, $term);
        }

        $npcSql = 'SELECT TOP 250 g.[RefNPCCodeName] AS npc_code, MAX(o.[ID]) AS npc_id, MAX(o.[NameStrID128]) AS name_key, MAX(o.[CodeName128]) AS object_code, COUNT(DISTINCT g.[CodeName128]) AS group_count, COUNT(DISTINCT goods.[index]) AS goods_count
            FROM [dbo].[_RefShopGroup] g
            LEFT JOIN [dbo].[_RefObjCommon] o ON o.[CodeName128] = g.[RefNPCCodeName]
            LEFT JOIN [dbo].[_RefMappingShopGroup] mapGroup ON mapGroup.[RefShopGroupCodeName] = g.[CodeName128] AND mapGroup.[Service] = 1
            LEFT JOIN [dbo].[_RefMappingShopWithTab] mapTab ON mapTab.[RefShopCodeName] = mapGroup.[RefShopCodeName] AND mapTab.[Service] = 1
            LEFT JOIN [dbo].[_RefShopTab] tab ON tab.[RefTabGroupCodeName] = mapTab.[RefTabGroupCodeName] AND tab.[Service] = 1
            LEFT JOIN [dbo].[_RefShopGoods] goods ON goods.[RefTabCodeName] = tab.[CodeName128] AND goods.[Service] = 1
            WHERE '.implode(' AND ', $where).'
            GROUP BY g.[RefNPCCodeName]
            ORDER BY goods_count DESC, g.[RefNPCCodeName]';
        $statement = $pdo->prepare($npcSql);
        $statement->execute($params);
        $npcs = $statement->fetchAll();

        if (! $npcs) {
            return ['npcs' => [], 'npc' => null, 'groups' => [], 'tabs' => [], 'tab' => null, 'items' => []];
        }

        $availableCodes = array_column($npcs, 'npc_code');
        if (! $npcCode || ! in_array($npcCode, $availableCodes, true)) {
            $npcCode = $availableCodes[0];
        }
        $selectedNpc = $npcs[array_search($npcCode, $availableCodes, true)];

        $groupStatement = $pdo->prepare('SELECT [ID] AS id, [CodeName128] AS group_code, [RefNPCCodeName] AS npc_code FROM [dbo].[_RefShopGroup] WHERE [Service] = 1 AND [RefNPCCodeName] = ? ORDER BY [ID]');
        $groupStatement->execute([$npcCode]);
        $groups = $groupStatement->fetchAll();
        $groupCodes = array_column($groups, 'group_code');

        $shops = [];
        if ($groupCodes) {
            $shopStatement = $pdo->prepare('SELECT m.[RefShopGroupCodeName] AS group_code, m.[RefShopCodeName] AS shop_code, s.[ID] AS shop_id
                FROM [dbo].[_RefMappingShopGroup] m
                LEFT JOIN [dbo].[_RefShop] s ON s.[CodeName128] = m.[RefShopCodeName] AND s.[Service] = 1
                WHERE m.[Service] = 1 AND m.[RefShopGroupCodeName] IN ('.implode(',', array_fill(0, count($groupCodes), '?')).')
                ORDER BY m.[RefShopGroupCodeName], m.[RefShopCodeName]');
            $shopStatement->execute($groupCodes);
            $shops = $shopStatement->fetchAll();
        }

        foreach ($groups as &$group) {
            $group['shops'] = array_values(array_filter($shops, fn (array $shop) => $shop['group_code'] === $group['group_code']));
        }
        unset($group);

        $shopCodes = array_values(array_unique(array_column($shops, 'shop_code')));
        $tabGroups = [];
        if ($shopCodes) {
            $mappingStatement = $pdo->prepare('SELECT [RefShopCodeName] AS shop_code, [RefTabGroupCodeName] AS tab_group_code
                FROM [dbo].[_RefMappingShopWithTab]
                WHERE [Service] = 1 AND [RefShopCodeName] IN ('.implode(',', array_fill(0, count($shopCodes), '?')).')
                ORDER BY [RefShopCodeName], [RefTabGroupCodeName]');
            $mappingStatement->execute($shopCodes);
            $tabGroups = $mappingStatement->fetchAll();
        }

        $tabGroupCodes = array_values(array_unique(array_column($tabGroups, 'tab_group_code')));
        $tabs = [];
        if ($tabGroupCodes) {
            $tabStatement = $pdo->prepare('SELECT t.[ID] AS id, t.[CodeName128] AS tab_code, t.[RefTabGroupCodeName] AS tab_group_code, t.[StrID128_Tab] AS name_key
                FROM [dbo].[_RefShopTab] t
                WHERE t.[Service] = 1 AND t.[RefTabGroupCodeName] IN ('.implode(',', array_fill(0, count($tabGroupCodes), '?')).')
                ORDER BY t.[RefTabGroupCodeName], t.[ID]');
            $tabStatement->execute($tabGroupCodes);
            $tabs = $tabStatement->fetchAll();
        }

        $availableTabs = array_column($tabs, 'tab_code');
        if ($availableTabs && (! $tabCode || ! in_array($tabCode, $availableTabs, true))) {
            $tabCode = $availableTabs[0];
        }
        $selectedTab = $tabCode && $tabs ? $tabs[array_search($tabCode, $availableTabs, true)] : null;

        $items = [];
        if ($selectedTab) {
            $goodsStatement = $pdo->prepare(<<<'SQL'
SELECT g.[index] AS goods_id, g.[SlotIndex] AS slot_index, g.[RefTabCodeName] AS tab_code,
       g.[RefPackageItemCodeName] AS package_code, p.[NameStrID] AS package_name_key,
       COALESCE(NULLIF(p.[AssocFileIcon], 'xxx'), o.[AssocFileIcon128]) AS icon_path,
       scrap.[RefItemCodeName] AS item_code, o.[ID] AS item_id, o.[NameStrID128] AS item_name_key,
       price.[index] AS price_id, price.[PaymentDevice] AS payment_device, price.[Cost] AS cost
FROM [dbo].[_RefShopGoods] g
LEFT JOIN [dbo].[_RefPackageItem] p ON p.[CodeName128] = g.[RefPackageItemCodeName] AND p.[Service] = 1
OUTER APPLY (SELECT TOP 1 s.[RefItemCodeName] FROM [dbo].[_RefScrapOfPackageItem] s WHERE s.[RefPackageItemCodeName] = g.[RefPackageItemCodeName] AND s.[Service] = 1 ORDER BY s.[Index]) scrap
LEFT JOIN [dbo].[_RefObjCommon] o ON o.[CodeName128] = scrap.[RefItemCodeName]
OUTER APPLY (SELECT TOP 1 pp.[index], pp.[PaymentDevice], pp.[Cost] FROM [dbo].[_RefPricePolicyOfItem] pp WHERE pp.[RefPackageItemCodeName] = g.[RefPackageItemCodeName] AND pp.[Service] = 1 ORDER BY pp.[PaymentDevice], pp.[index]) price
WHERE g.[Service] = 1 AND g.[RefTabCodeName] = ?
ORDER BY g.[SlotIndex], g.[index]
SQL);
            $goodsStatement->execute([$selectedTab['tab_code']]);
            $items = $goodsStatement->fetchAll();
        }

        return [
            'npcs' => $npcs,
            'npc' => $selectedNpc,
            'groups' => $groups,
            'tabs' => $tabs,
            'tab' => $selectedTab,
            'items' => $items,
        ];
    }

    public function updatePrice(ServerProfile $profile, int $priceId, int $cost): array
    {
        if ($priceId < 1 || $cost < 0 || $cost > 2147483647) {
            throw new RuntimeException('Enter a valid non-negative price.');
        }
        $pdo = $this->connections->connect($profile, $profile->shard_database);
        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare('SELECT [Cost] FROM [dbo].[_RefPricePolicyOfItem] WITH (UPDLOCK, HOLDLOCK) WHERE [index] = ? AND [Service] = 1');
            $statement->execute([$priceId]);
            $current = $statement->fetch();
            if (! $current) {
                throw new RuntimeException('The selected price policy no longer exists or is inactive.');
            }
            $statement = $pdo->prepare('UPDATE [dbo].[_RefPricePolicyOfItem] SET [PreviousCost] = [Cost], [Cost] = ? WHERE [index] = ? AND [Service] = 1');
            $statement->execute([$cost, $priceId]);
            $pdo->commit();

            return ['price_id' => $priceId, 'old_cost' => (int) $current['Cost'], 'cost' => $cost];
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function removeGood(ServerProfile $profile, int $goodsId): array
    {
        if ($goodsId < 1) {
            throw new RuntimeException('The selected shop item is invalid.');
        }
        $pdo = $this->connections->connect($profile, $profile->shard_database);
        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare('SELECT [RefTabCodeName], [RefPackageItemCodeName] FROM [dbo].[_RefShopGoods] WITH (UPDLOCK, HOLDLOCK) WHERE [index] = ? AND [Service] = 1');
            $statement->execute([$goodsId]);
            $row = $statement->fetch();
            if (! $row) {
                throw new RuntimeException('The selected shop item no longer exists or is inactive.');
            }
            $statement = $pdo->prepare('DELETE FROM [dbo].[_RefShopGoods] WHERE [index] = ? AND [Service] = 1');
            $statement->execute([$goodsId]);
            $pdo->commit();

            return ['goods_id' => $goodsId, 'tab_code' => $row['RefTabCodeName'], 'package_code' => $row['RefPackageItemCodeName']];
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function createTab(ServerProfile $profile, array $input): array
    {
        $shopCode = trim((string) ($input['shop_code'] ?? ''));
        $tabGroupCode = trim((string) ($input['tab_group_code'] ?? ''));
        $tabCode = trim((string) ($input['tab_code'] ?? ''));
        $tabGroupName = trim((string) ($input['tab_group_name'] ?? ''));
        $tabName = trim((string) ($input['tab_name'] ?? ''));
        $country = (int) ($input['country'] ?? 15);
        foreach (['shop_code' => $shopCode, 'tab_group_code' => $tabGroupCode, 'tab_code' => $tabCode, 'tab_group_name' => $tabGroupName, 'tab_name' => $tabName] as $field => $value) {
            if ($value === '' || ! preg_match('/^[A-Za-z0-9_\-]+$/', $value)) {
                throw new RuntimeException("{$field} must contain letters, numbers, underscores or hyphens.");
            }
        }
        if ($country < 1 || $country > 2147483647) {
            throw new RuntimeException('Country must be a positive integer.');
        }

        $pdo = $this->connections->connect($profile, $profile->shard_database);
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare('SELECT [Country] FROM [dbo].[_RefShop] WITH (UPDLOCK, HOLDLOCK) WHERE [CodeName128] = ? AND [Service] = 1');
            $statement->execute([$shopCode]);
            $shop = $statement->fetch();
            if (! $shop) {
                throw new RuntimeException('The selected shop does not exist or is inactive.');
            }
            $country = (int) $shop['Country'];

            $statement = $pdo->prepare('SELECT 1 FROM [dbo].[_RefShopTab] WITH (UPDLOCK, HOLDLOCK) WHERE [CodeName128] = ?');
            $statement->execute([$tabCode]);
            if ($statement->fetch()) {
                throw new RuntimeException('That tab CodeName already exists.');
            }

            $statement = $pdo->prepare('SELECT [ID], [StrID128_Group] FROM [dbo].[_RefShopTabGroup] WITH (UPDLOCK, HOLDLOCK) WHERE [CodeName128] = ? AND [Country] = ?');
            $statement->execute([$tabGroupCode, $country]);
            $group = $statement->fetch();
            if ($group && (string) $group['StrID128_Group'] !== $tabGroupName) {
                throw new RuntimeException('That tab group exists with a different display key.');
            }
            if (! $group) {
                $statement = $pdo->prepare('INSERT INTO [dbo].[_RefShopTabGroup] ([Service], [Country], [CodeName128], [StrID128_Group]) VALUES (1, ?, ?, ?)');
                $statement->execute([$country, $tabGroupCode, $tabGroupName]);
            }

            $statement = $pdo->prepare('INSERT INTO [dbo].[_RefShopTab] ([Service], [Country], [CodeName128], [RefTabGroupCodeName], [StrID128_Tab]) VALUES (1, ?, ?, ?, ?)');
            $statement->execute([$country, $tabCode, $tabGroupCode, $tabName]);
            $statement = $pdo->prepare('IF NOT EXISTS (SELECT 1 FROM [dbo].[_RefMappingShopWithTab] WHERE [Country] = ? AND [RefShopCodeName] = ? AND [RefTabGroupCodeName] = ?) INSERT INTO [dbo].[_RefMappingShopWithTab] ([Service], [Country], [RefShopCodeName], [RefTabGroupCodeName]) VALUES (1, ?, ?, ?)');
            $statement->execute([$country, $shopCode, $tabGroupCode, $country, $shopCode, $tabGroupCode]);
            $pdo->commit();

            return ['shop_code' => $shopCode, 'tab_group_code' => $tabGroupCode, 'tab_code' => $tabCode, 'country' => $country];
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }
}
