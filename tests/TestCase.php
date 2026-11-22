<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    private bool $hadRemoteAddress = false;

    private ?string $originalRemoteAddress = null;

    private bool $hadUserAgent = false;

    private ?string $originalUserAgent = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hadRemoteAddress = array_key_exists('REMOTE_ADDR', $_SERVER);
        $this->originalRemoteAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        if (! isset($_SERVER['REMOTE_ADDR']) || trim((string) $_SERVER['REMOTE_ADDR']) === '') {
            $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        }

        $this->hadUserAgent = array_key_exists('HTTP_USER_AGENT', $_SERVER);
        $this->originalUserAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        if (! isset($_SERVER['HTTP_USER_AGENT']) || trim((string) $_SERVER['HTTP_USER_AGENT']) === '') {
            $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit';
        }
    }

    protected function tearDown(): void
    {
        if ($this->hadRemoteAddress) {
            $_SERVER['REMOTE_ADDR'] = $this->originalRemoteAddress;
        } else {
            unset($_SERVER['REMOTE_ADDR']);
        }

        if ($this->hadUserAgent) {
            $_SERVER['HTTP_USER_AGENT'] = $this->originalUserAgent;
        } else {
            unset($_SERVER['HTTP_USER_AGENT']);
        }

        parent::tearDown();
    }
}
