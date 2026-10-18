<?php namespace Softon\Indipay\Gateways;

interface VerifiesPayments {
    public function verify($parameters);
}
