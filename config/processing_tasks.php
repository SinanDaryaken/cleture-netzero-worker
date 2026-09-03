<?php

use App\ProcessingTasks\Definitions\Identity\EmailVerificationTaskDefinition;
use App\ProcessingTasks\Definitions\Identity\PasswordChangedTaskDefinition;
use App\ProcessingTasks\Definitions\Identity\PasswordResetTaskDefinition;
use App\ProcessingTasks\ProcessingTaskRouter;

return [
    'connection' => env('PROCESSING_TASK_CONNECTION', 'central'),
    'queue_connection' => env('PROCESSING_TASK_QUEUE_CONNECTION', 'database'),
    'worker_id' => env('PROCESSING_TASK_WORKER_ID'),

    'tables' => [
        'tasks' => 'processing_tasks',
        'failures' => 'processing_task_failures',
    ],

    'lease_seconds' => (int) env('PROCESSING_TASK_LEASE_SECONDS', 60),
    'max_attempts' => (int) env('PROCESSING_TASK_MAX_ATTEMPTS', 5),
    'backoff_seconds' => [5, 30, 120, 300, 900],

    'dispatch' => [
        'batch_size' => (int) env('PROCESSING_TASK_DISPATCH_BATCH_SIZE', 25),
        'stale_after_seconds' => (int) env('PROCESSING_TASK_DISPATCH_STALE_AFTER_SECONDS', 600),
    ],

    'contracts' => [
        ProcessingTaskRouter::EMAIL_VERIFICATION => [
            1 => [
                'definition' => EmailVerificationTaskDefinition::class,
                'queue' => 'identity-mails',
            ],
            2 => [
                'definition' => EmailVerificationTaskDefinition::class,
                'queue' => 'identity-mails',
            ],
        ],
        ProcessingTaskRouter::PASSWORD_RESET => [
            1 => [
                'definition' => PasswordResetTaskDefinition::class,
                'queue' => 'identity-mails',
            ],
            2 => [
                'definition' => PasswordResetTaskDefinition::class,
                'queue' => 'identity-mails',
            ],
        ],
        ProcessingTaskRouter::PASSWORD_CHANGED => [
            1 => [
                'definition' => PasswordChangedTaskDefinition::class,
                'queue' => 'identity-mails',
            ],
            2 => [
                'definition' => PasswordChangedTaskDefinition::class,
                'queue' => 'identity-mails',
            ],
        ],
    ],
];
