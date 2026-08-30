<?php

return [
    'owner_only' => true,

    'defaults' => [
        'name' => env('CASY_DEFAULT_SQL_NAME', 'My SRO server'),
        'host' => env('CASY_DEFAULT_SQL_HOST', '127.0.0.1'),
        'port' => (int) env('CASY_DEFAULT_SQL_PORT', 1433),
        'username' => env('CASY_DEFAULT_SQL_USERNAME', ''),
        'password' => env('CASY_DEFAULT_SQL_PASSWORD'),
        'account_database' => env('CASY_DEFAULT_ACCOUNT_DATABASE', 'SRO_VT_ACCOUNT'),
        'shard_database' => env('CASY_DEFAULT_SHARD_DATABASE', 'SRO_VT_SHARD'),
        'log_database' => env('CASY_DEFAULT_LOG_DATABASE', 'SRO_VT_SHARDLOG'),
        'proxy_database' => env('CASY_DEFAULT_PROXY_DATABASE', 'KMTGUARD'),
        'icon_root' => env('CASY_DEFAULT_ICON_ROOT', ''),
    ],

    // Table Studio is read-first. Only these explicitly reviewed tables can
    // receive a visual insert/update; domain studios remain the preferred
    // path for all game content mutations.
    'table_studio' => [
        'write_allowlist' => [
            '_RefGame_World', '_RefInstance_World_Region', '_RefInstance_World_Start_Pos',
            '_RefTeleport', '_RefTeleLink', '_Char', 'SK_Silk',
        ],
        'page_size' => 60,
    ],

    'capabilities' => [
        'item.updated' => ['risk' => 'high', 'transaction' => true, 'restorable' => true],
        'item.cloned' => ['risk' => 'high', 'transaction' => true, 'restorable' => false],
        'npc-shop.price-updated' => ['risk' => 'medium', 'transaction' => true, 'restorable' => true],
        'npc-shop.good-removed' => ['risk' => 'high', 'transaction' => true, 'restorable' => false],
        'instance-world.created' => ['risk' => 'high', 'transaction' => true, 'restorable' => false],
        'instance-world.region-removed' => ['risk' => 'high', 'transaction' => true, 'restorable' => false],
        'table.row.updated' => ['risk' => 'high', 'transaction' => true, 'restorable' => true],
        'table.row.created' => ['risk' => 'high', 'transaction' => true, 'restorable' => false],
        'live.command' => ['risk' => 'high', 'transaction' => false, 'restorable' => false],
        'player.control' => ['risk' => 'medium', 'transaction' => true, 'restorable' => false],
    ],
    'live_procedures' => [
        'Live_Silk' => ['risk' => 'medium', 'target' => true, 'label' => 'Change silk balance'],
        'Live_Gold' => ['risk' => 'medium', 'target' => true, 'label' => 'Add player gold'],
        'Live_Level' => ['risk' => 'high', 'target' => true, 'label' => 'Update player level'],
        'Teleport_Self' => ['risk' => 'medium', 'target' => false, 'label' => 'Self teleport'],
        'Teleport_Position' => ['risk' => 'medium', 'target' => true, 'label' => 'Teleport to position'],
        'Teleport_PlayerToTown' => ['risk' => 'medium', 'target' => true, 'label' => 'Return player to town'],
        'Teleport_2Town' => ['risk' => 'medium', 'target' => true, 'label' => 'Return player to town (safe)'],
        'Command_NoticeByID' => ['risk' => 'medium', 'target' => true, 'label' => 'Send private notice'],
        'Command_NoticeByName' => ['risk' => 'medium', 'target' => true, 'label' => 'Send private notice'],
        'Command_NoticeAll' => ['risk' => 'medium', 'target' => false, 'label' => 'Send server notice'],
        'Command_DisconnectByID' => ['risk' => 'high', 'target' => true, 'label' => 'Disconnect player'],
        'Command_DisconnectByName' => ['risk' => 'high', 'target' => true, 'label' => 'Disconnect player'],
        'NPC_SpawnAtPosition' => ['risk' => 'high', 'target' => false, 'label' => 'Spawn monster at position'],
        'NPC_SpawnNearPlayer' => ['risk' => 'high', 'target' => true, 'label' => 'Spawn monster near player'],
        'Item_AddChestByCodeName' => ['risk' => 'medium', 'target' => true, 'label' => 'Send item to player'],
        'Item_ChestSendToOnline' => ['risk' => 'high', 'target' => false, 'label' => 'Send item to online players'],
        'Item_ChestSendToAll' => ['risk' => 'critical', 'target' => false, 'label' => 'Send item to all characters'],
    ],
    'emergency_actions' => [
        'database.reset', 'fortress.reset', 'global.item.remove', 'global.player.teleport',
    ],
];
