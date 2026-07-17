<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Persistence;

use Padosoft\LaravelFlow\ApprovalTokenManager;
use Padosoft\LaravelFlow\Exceptions\FlowExecutionException;
use Padosoft\LaravelFlow\Exceptions\FlowInputException;
use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\FlowRun;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\AlwaysSucceedsHandler;

/**
 * Pins that {@see FlowEngine::resumeByHash()}/{@see FlowEngine::rejectByHash()}
 * decide an approval by its stored token HASH exactly as `resume()`/`reject()`
 * do by the plain token — the seam a companion dashboard needs (it only ever
 * holds hashes; plain tokens are never recoverable from storage).
 */
final class ApproveByHashTest extends PersistenceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        AlwaysSucceedsHandler::$callCount = 0;
    }

    public function test_resume_by_hash_matches_resume_by_plain_token(): void
    {
        $this->migrateFlowTables();
        $engine = $this->engineWithPersistence();

        $engine->define('flow.byhash.resume')
            ->step('create', AlwaysSucceedsHandler::class)
            ->approvalGate('manager')
            ->step('publish', AlwaysSucceedsHandler::class)
            ->register();

        $paused = $engine->execute('flow.byhash.resume', []);
        $this->assertSame(FlowRun::STATUS_PAUSED, $paused->status);

        $plain = $paused->approvalTokens['manager']->plainTextToken;
        $resumed = $engine->resumeByHash(ApprovalTokenManager::hashToken($plain));

        $this->assertSame($paused->id, $resumed->id);
        $this->assertSame(FlowRun::STATUS_SUCCEEDED, $resumed->status);
    }

    public function test_reject_by_hash_matches_reject_by_plain_token(): void
    {
        $this->migrateFlowTables();
        $engine = $this->engineWithPersistence();

        $engine->define('flow.byhash.reject')
            ->step('create', AlwaysSucceedsHandler::class)
            ->approvalGate('manager')
            ->register();

        $paused = $engine->execute('flow.byhash.reject', []);
        $plain = $paused->approvalTokens['manager']->plainTextToken;

        $rejected = $engine->rejectByHash(ApprovalTokenManager::hashToken($plain));

        $this->assertSame(FlowRun::STATUS_FAILED, $rejected->status);
    }

    public function test_resume_by_hash_rejects_a_blank_hash(): void
    {
        $this->migrateFlowTables();
        $engine = $this->engineWithPersistence();

        $this->expectException(FlowInputException::class);
        $engine->resumeByHash('   ');
    }

    public function test_resume_by_hash_rejects_an_unknown_hash(): void
    {
        $this->migrateFlowTables();
        $engine = $this->engineWithPersistence();

        $this->expectException(FlowExecutionException::class);
        $engine->resumeByHash(ApprovalTokenManager::hashToken('never-issued'));
    }

    private function engineWithPersistence(): FlowEngine
    {
        $this->app['config']->set('laravel-flow.persistence.enabled', true);
        $this->app['config']->set('laravel-flow.queue.lock_store', 'file');
        $this->app->forgetInstance(FlowEngine::class);

        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);

        return $engine;
    }
}
