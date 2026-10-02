<?php

declare(strict_types=1);

return [
    'system' => 'Systém',
    'kinds' => [
        'initial' => 'Vytvorené',
        'transition' => 'Prechod',
        'expiry' => 'Vypršané',
        'scheduled' => 'Naplánovaný prechod',
        'rollback' => 'Vrátené späť',
        'adopted' => 'Prevzaté',
    ],
    'schedule_status' => [
        'pending' => 'Čaká',
        'paused' => 'Pozastavené',
        'executed' => 'Vykonané',
        'cancelled' => 'Zrušené',
        'failed' => 'Zlyhalo',
    ],
    'schedule_outcome' => [
        'executed' => 'Vykonané',
        'state_left' => 'Stav opustený',
        'reverted' => 'Vrátené',
        'replaced' => 'Nahradené',
        'cancelled' => 'Zrušené',
        'subject_missing' => 'Záznam chýba',
        'denied' => 'Zamietnuté',
        'max_attempts' => 'Príliš veľa pokusov',
        'error' => 'Chyba',
    ],
];
