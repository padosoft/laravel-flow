<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Executor;

use Padosoft\LaravelFlow\Executor\Attributes\Retry;
use Padosoft\LaravelFlow\Executor\RetryPolicy;
use PHPUnit\Framework\TestCase;

final class RetryPolicyTest extends TestCase
{
    public function test_defaults_to_a_single_attempt(): void
    {
        $policy = RetryPolicy::fromAttribute(null);

        $this->assertSame(1, $policy->tries());
        $this->assertSame(0, $policy->timeout());
        $this->assertSame(0, $policy->backoffForAttempt(1));
        $this->assertTrue($policy->isExhausted(1));
    }

    public function test_fixed_backoff(): void
    {
        $policy = RetryPolicy::fromAttribute(new Retry(tries: 3, backoff: 5));

        $this->assertSame(3, $policy->tries());
        $this->assertSame(5, $policy->backoffForAttempt(1));
        $this->assertSame(5, $policy->backoffForAttempt(2));
        $this->assertSame(5, $policy->backoffForAttempt(99));
    }

    public function test_per_attempt_backoff_list_with_clamp(): void
    {
        $policy = RetryPolicy::fromAttribute(new Retry(tries: 5, backoff: [1, 3, 10]));

        $this->assertSame(1, $policy->backoffForAttempt(1));
        $this->assertSame(3, $policy->backoffForAttempt(2));
        $this->assertSame(10, $policy->backoffForAttempt(3));
        // Shorter than attempts → clamp to the last value.
        $this->assertSame(10, $policy->backoffForAttempt(4));
        $this->assertSame(10, $policy->backoffForAttempt(9));
    }

    public function test_config_overrides_attribute(): void
    {
        $policy = RetryPolicy::fromAttribute(
            new Retry(tries: 2, backoff: 5, timeout: 30),
            ['tries' => 4, 'backoff' => [2, 4], 'timeout' => 60],
        );

        $this->assertSame(4, $policy->tries());
        $this->assertSame(2, $policy->backoffForAttempt(1));
        $this->assertSame(4, $policy->backoffForAttempt(2));
        $this->assertSame(4, $policy->backoffForAttempt(3));
        $this->assertSame(60, $policy->timeout());
    }

    public function test_config_backoff_with_non_int_entries_is_ignored(): void
    {
        // A persisted/imported graph may supply a malformed backoff; it must be
        // rejected (keep the attribute value) rather than crash under strict_types.
        $policy = RetryPolicy::fromAttribute(
            new Retry(tries: 3, backoff: 7),
            ['backoff' => [1, 'two', 3]],
        );

        $this->assertSame(7, $policy->backoffForAttempt(1));
    }

    public function test_attribute_backoff_with_non_int_entries_is_ignored(): void
    {
        // #[Retry(backoff: [...])] is typed int|array, so a non-int list is
        // possible; it must be ignored (0) rather than crash normalizeBackoff().
        $policy = RetryPolicy::fromAttribute(new Retry(tries: 2, backoff: [1, '2']));

        $this->assertSame(0, $policy->backoffForAttempt(1));
    }

    public function test_config_backoff_non_list_array_is_ignored(): void
    {
        $policy = RetryPolicy::fromAttribute(
            new Retry(tries: 3, backoff: 7),
            ['backoff' => ['first' => 1]],
        );

        $this->assertSame(7, $policy->backoffForAttempt(1));
    }

    public function test_exhaustion(): void
    {
        $policy = RetryPolicy::fromAttribute(new Retry(tries: 2));

        $this->assertFalse($policy->isExhausted(1));
        $this->assertTrue($policy->isExhausted(2));
        $this->assertTrue($policy->isExhausted(3));
    }

    public function test_tries_below_one_is_a_single_attempt(): void
    {
        // Divergence from Laravel's job-level "0 = unlimited": a node uses 0/negative
        // as a single attempt so a poison node cannot loop forever.
        $this->assertSame(1, RetryPolicy::fromAttribute(new Retry(tries: 0))->tries());
        $this->assertSame(1, RetryPolicy::fromAttribute(new Retry(tries: -3))->tries());
    }
}
