<?php

namespace Softon\Indipay\Tests;

use PHPUnit\Framework\TestCase;

class ReadmeAfterResponseTest extends TestCase
{
    public function testReadmeTellsAppsToCheckOrderAndAmountAfterResponse()
    {
        $readme = file_get_contents(__DIR__.'/../README.md');

        $this->assertStringContainsString('## After the response', $readme);
        $this->assertStringContainsString('Compare the amount', $readme);
        $this->assertStringContainsString('idempotent', $readme);
        $this->assertStringContainsString('payment->status', $readme);
    }
}
