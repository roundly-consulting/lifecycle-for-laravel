<?php

declare(strict_types=1);

return [
    'system' => 'System',
    'kinds' => [
        'initial' => 'Created',
        'transition' => 'Transition',
        'expiry' => 'Expired',
        'scheduled' => 'Scheduled transition',
        'rollback' => 'Rolled back',
        'adopted' => 'Adopted',
    ],
    'schedule_status' => [
        'pending' => 'Pending',
        'paused' => 'Paused',
        'executed' => 'Executed',
        'cancelled' => 'Cancelled',
        'failed' => 'Failed',
    ],
    'schedule_outcome' => [
        'executed' => 'Executed',
        'state_left' => 'State left',
        'reverted' => 'Reverted',
        'replaced' => 'Replaced',
        'cancelled' => 'Cancelled',
        'subject_missing' => 'Record missing',
        'denied' => 'Denied',
        'max_attempts' => 'Too many attempts',
        'error' => 'Error',
    ],
];
