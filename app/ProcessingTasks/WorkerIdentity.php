<?php

namespace App\ProcessingTasks;

use Illuminate\Support\Str;
use InvalidArgumentException;

class WorkerIdentity
{
    private readonly string $value;

    public function __construct()
    {
        $configuredIdentity = config('processing_tasks.worker_id');

        if (is_string($configuredIdentity) && trim($configuredIdentity) !== '') {
            $this->value = Str::limit(
                trim($configuredIdentity).':'.getmypid().':'.Str::uuid7(),
                255,
                '',
            );

            return;
        }

        $hostname = gethostname();
        if (! is_string($hostname) || trim($hostname) === '') {
            throw new InvalidArgumentException('Worker identity requires a hostname or configured identifier.');
        }

        $this->value = Str::limit(
            trim($hostname).':'.getmypid().':'.Str::uuid7(),
            255,
            '',
        );
    }

    public function value(): string
    {
        return $this->value;
    }
}
