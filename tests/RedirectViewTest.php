<?php

namespace Softon\Indipay\Tests;

use PHPUnit\Framework\Attributes\DataProvider;

class RedirectViewTest extends TestCase
{
    public static function redirectViews()
    {
        return [
            'ccavenue' => ['ccavenue', ['endPoint' => 'https://pg.test/pay', 'encRequest' => 'ENC', 'accessCode' => 'AC']],
            'citrus' => ['citrus', ['endPoint' => 'https://pg.test/pay', 'hash' => 'H', 'parameters' => [
                'merchantTxnId' => '1', 'orderAmount' => '1', 'currency' => 'INR', 'returnUrl' => 'u', 'notifyUrl' => 'u',
            ]]],
            'instamojo' => ['instamojo', ['longurl' => 'https://pg.test/pay']],
            'mocker' => ['mocker', ['end_point' => 'https://pg.test/pay', 'data' => ['a' => 'b']]],
            'paytm' => ['paytm', ['endPoint' => 'https://pg.test/pay', 'params' => ['a' => 'b'], 'checksum' => 'C']],
            'zapakpay' => ['zapakpay', ['endPoint' => 'https://pg.test/pay', 'params' => ['a' => 'b'], 'checksum' => 'C']],
            'payumoney' => ['payumoney', ['endPoint' => 'https://pg.test/pay', 'hash' => 'H', 'parameters' => [
                'key' => 'k', 'txnid' => 't', 'amount' => '1', 'firstname' => 'f', 'email' => 'e', 'phone' => 'p',
                'productinfo' => 'i', 'surl' => 's', 'furl' => 'f',
            ]]],
            'ebs' => ['ebs', ['endPoint' => 'https://pg.test/pay', 'hash' => 'H', 'parameters' => [
                'channel' => '0', 'account_id' => '1', 'reference_no' => 'r', 'amount' => '1', 'mode' => 'TEST',
                'currency' => 'INR', 'description' => 'd', 'return_url' => 'u', 'name' => 'n', 'address' => 'a',
                'city' => 'c', 'country' => 'IND', 'postal_code' => '1', 'phone' => 'p', 'email' => 'e',
            ]]],
        ];
    }

    #[DataProvider('redirectViews')]
    public function test_view_is_valid_html5_with_script_and_noscript_fallback($name, array $data)
    {
        $html = view('indipay::'.$name, $data)->render();

        $this->assertStringStartsWith('<!doctype html>', $html);
        $this->assertStringContainsString('<meta charset="utf-8">', $html);
        $this->assertStringContainsString('name="viewport"', $html);
        $this->assertStringNotContainsString("language='javascript'", $html);
        $this->assertStringContainsString('action="https://pg.test/pay"', $html);
        $this->assertStringContainsString("getElementById('indipay-redirect').submit()", $html);
        $this->assertPatternMatches('#<noscript>\s*<button type="submit">Continue to payment</button>\s*</noscript>#', $html);
        $this->assertStringNotContainsString('nonce=', $html);
    }

    #[DataProvider('redirectViews')]
    public function test_view_applies_the_csp_nonce($name, array $data)
    {
        $html = view('indipay::'.$name, $data + ['nonce' => 'abc123'])->render();

        $this->assertStringContainsString('<script nonce="abc123">', $html);
    }

    public function test_shared_nonce_reaches_the_view()
    {
        view()->share('nonce', 'shared1');

        $html = view('indipay::instamojo', ['longurl' => 'https://pg.test/pay'])->render();

        $this->assertStringContainsString('nonce="shared1"', $html);
    }

    public function test_vite_csp_nonce_is_used_when_no_nonce_is_shared()
    {
        if (!class_exists('Illuminate\\Foundation\\Vite')) {
            $this->markTestSkipped('Vite is not available in this Laravel version.');
        }

        \Illuminate\Support\Facades\Vite::useCspNonce('vite-nonce');

        $html = view('indipay::instamojo', ['longurl' => 'https://pg.test/pay'])->render();

        $this->assertStringContainsString('<script nonce="vite-nonce">', $html);
    }

    public function test_action_is_html_escaped()
    {
        $html = view('indipay::instamojo', ['longurl' => 'https://pg.test/"><b>x'])->render();

        $this->assertStringContainsString('action="https://pg.test/&quot;&gt;&lt;b&gt;x"', $html);
        $this->assertStringNotContainsString('<b>x', $html);
    }

    public function test_instamojo_redirects_with_get()
    {
        $html = view('indipay::instamojo', ['longurl' => 'https://pg.test/pay'])->render();

        $this->assertStringContainsString('method="get"', $html);
    }
}
