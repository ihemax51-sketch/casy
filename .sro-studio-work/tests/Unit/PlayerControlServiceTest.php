<?php

namespace Tests\Unit;

use App\Services\KmtGuardCommandService;
use App\Services\PlayerControlService;
use App\Services\SqlServerConnectionFactory;
use PHPUnit\Framework\TestCase;

class PlayerControlServiceTest extends TestCase
{
    private function service(): PlayerControlService
    {
        $connections = new SqlServerConnectionFactory();

        return new PlayerControlService($connections, new KmtGuardCommandService($connections));
    }

    public function test_player_actions_require_the_shared_character_target(): void
    {
        $service = $this->service();

        $this->assertTrue($service->requiresTarget('silk'));
        $this->assertTrue($service->requiresTarget('level'));
        $this->assertTrue($service->requiresTarget('item_player'));
        $this->assertTrue($service->requiresTarget('spawn', ['spawn_mode' => 'player']));
    }

    public function test_server_wide_and_position_actions_do_not_require_a_character(): void
    {
        $service = $this->service();

        $this->assertFalse($service->requiresTarget('server_notice'));
        $this->assertFalse($service->requiresTarget('item_online'));
        $this->assertFalse($service->requiresTarget('spawn', ['spawn_mode' => 'position']));
    }
}
