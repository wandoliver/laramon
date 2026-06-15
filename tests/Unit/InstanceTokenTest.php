<?php

namespace Tests\Unit;

use App\Support\InstanceToken;
use PHPUnit\Framework\TestCase;

class InstanceTokenTest extends TestCase
{
    public function test_generated_tokens_use_laramon_prefix(): void
    {
        $token = InstanceToken::generate(123);

        $this->assertMatchesRegularExpression('/^lm_123_[A-Za-z0-9]{40}$/', $token);
        $this->assertSame(123, InstanceToken::instanceId($token));
    }

    public function test_legacy_tokens_still_resolve_instance_id(): void
    {
        $token = 'ahm_456_'.str_repeat('a', 40);

        $this->assertSame(456, InstanceToken::instanceId($token));
    }
}
