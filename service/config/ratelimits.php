<?php

return [
    'global' => [
        'max_attempts' => (int) env('RATE_LIMIT_MAX_ATTEMPTS', 200),
        'decay_minutes' => (int) env('RATE_LIMIT_DECAY_MINUTES', 1),
        'min_attempts' => 10,
        'max_attempts_limit' => 10000,
        'min_decay' => 1,
        'max_decay' => 60,
    ],
];
