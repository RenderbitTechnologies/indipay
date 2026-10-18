<?php

namespace Softon\Indipay\Tests;

use PHPUnit\Framework\TestCase;

class ComposerConstraintsTest extends TestCase
{
    private function require_()
    {
        $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);

        return $composer['require'];
    }

    public function test_guzzle_constraint_excludes_guzzle_5()
    {
        // The gateways use form_params and Client::request(), added in Guzzle 6.
        $this->assertSame('~6.0|^7.0', $this->require_()['guzzlehttp/guzzle']);
    }

    public function test_php_constraint_has_real_floor_and_upper_bound()
    {
        // The code uses `??` (PHP 7.0+) and Laravel 5.6 needs PHP 7.1.3.
        $this->assertSame('^7.1.3|^8.0', $this->require_()['php']);
    }
}
