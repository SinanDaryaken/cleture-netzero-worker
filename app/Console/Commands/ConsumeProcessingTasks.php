<?php

namespace App\Console\Commands;

use App\ProcessingTasks\ProcessingTaskConsumer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('processing-tasks:consume {--limit= : Maximum number of eligible tasks to dispatch}')]
#[Description('Atomically dispatch eligible processing tasks to the Laravel database queue')]
class ConsumeProcessingTasks extends Command
{
    public function handle(ProcessingTaskConsumer $consumer): int
    {
        $limit = $this->limit();

        if ($limit === null) {
            $this->components->error('The --limit option must be an integer between 1 and 1000.');

            return self::FAILURE;
        }

        $result = $consumer->consume($limit);

        $this->components->info(sprintf(
            'Selected %d task(s); enqueued %d, deferred %d, archived %d.',
            $result->claimed,
            $result->enqueued,
            $result->retried,
            $result->failed,
        ));

        return self::SUCCESS;
    }

    private function limit(): ?int
    {
        $option = $this->option('limit');

        if ($option === null) {
            return max(1, min(
                (int) config('processing_tasks.dispatch.batch_size'),
                1000,
            ));
        }

        if (! is_string($option) || preg_match('/^[0-9]+$/', $option) !== 1) {
            return null;
        }

        $limit = (int) $option;

        return $limit >= 1 && $limit <= 1000 ? $limit : null;
    }
}
