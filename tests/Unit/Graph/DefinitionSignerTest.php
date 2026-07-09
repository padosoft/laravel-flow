<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Graph;

use Padosoft\LaravelFlow\Graph\DefinitionSigner;
use PHPUnit\Framework\TestCase;

final class DefinitionSignerTest extends TestCase
{
    public function test_is_enabled_reflects_whether_a_non_blank_secret_is_configured(): void
    {
        $this->assertFalse((new DefinitionSigner)->isEnabled());
        $this->assertFalse((new DefinitionSigner(''))->isEnabled());
        $this->assertTrue((new DefinitionSigner('top-secret'))->isEnabled());
    }

    public function test_sign_returns_null_when_no_secret_is_configured(): void
    {
        $signer = new DefinitionSigner;

        $this->assertNull($signer->sign('checksum'));
    }

    public function test_sign_returns_null_when_secret_is_blank(): void
    {
        $signer = new DefinitionSigner('');

        $this->assertNull($signer->sign('checksum'));
    }

    public function test_sign_returns_hmac_sha256_of_the_checksum_when_a_secret_is_configured(): void
    {
        $signer = new DefinitionSigner('top-secret');

        $this->assertSame(hash_hmac('sha256', 'checksum', 'top-secret'), $signer->sign('checksum'));
    }

    public function test_verify_is_a_no_op_when_disabled_regardless_of_signature(): void
    {
        $signer = new DefinitionSigner;

        $this->assertTrue($signer->verify('checksum', null));
        $this->assertTrue($signer->verify('checksum', 'some-signature'));
    }

    public function test_verify_fails_when_enabled_and_signature_is_missing(): void
    {
        $signer = new DefinitionSigner('top-secret');

        $this->assertFalse($signer->verify('checksum', null));
    }

    public function test_verify_fails_when_enabled_and_signature_does_not_match(): void
    {
        $signer = new DefinitionSigner('top-secret');

        $this->assertFalse($signer->verify('checksum', 'not-the-real-signature'));
    }

    public function test_verify_succeeds_when_enabled_and_signature_matches(): void
    {
        $signer = new DefinitionSigner('top-secret');
        $signature = $signer->sign('checksum');

        $this->assertTrue($signer->verify('checksum', $signature));
    }

    public function test_verify_fails_when_checksum_changed_even_with_a_signature_valid_for_the_original_checksum(): void
    {
        $signer = new DefinitionSigner('top-secret');
        $signature = $signer->sign('original-checksum');

        $this->assertFalse($signer->verify('tampered-checksum', $signature));
    }
}
