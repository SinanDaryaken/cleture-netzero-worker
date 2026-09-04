<?php

namespace Tests\Unit\Jobs;

use App\Jobs\ProcessProcessingTask;
use Tests\TestCase;

class ProcessProcessingTaskTest extends TestCase
{
    public function test_transport_timeout_stays_below_database_queue_retry_window(): void
    {
        config(['queue.connections.database.retry_after' => 330]);
        $job = new ProcessProcessingTask(
            '0199a5e6-7d10-7f2d-8b31-2ee0833e7f85',
            '0199a5e6-7d10-7f2d-8b31-2ee0833e7f86',
        );

        $this->assertSame(300, $job->timeout);
        $this->assertTrue($job->failOnTimeout);
        $this->assertLessThan(
            config('queue.connections.database.retry_after'),
            $job->timeout,
        );
    }
}
