<?php

namespace Tests\Unit;

use App\Services\CapabilityRegistry;
use Tests\TestCase;

class CapabilityRegistryTest extends TestCase
{
    public function test_reviewed_capabilities_expose_risk_and_safety_flags(): void
    {
        $registry = app(CapabilityRegistry::class);

        $item = $registry->get('item.updated');

        $this->assertSame('high', $item['risk']);
        $this->assertTrue($item['transaction']);
        $this->assertTrue($item['restorable']);
        $this->assertTrue($registry->can('item.updated'));
        $this->assertFalse($registry->can('raw.sql.execute'));
    }
}
