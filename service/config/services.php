<?php

return [

    // Shared secret for service-to-service authentication (VerifyServiceToken middleware)
    'service_token' => env('SERVICE_TOKEN'),
    'identity' => [
        'endpoint' => env('IDENTITY_SERVICE_URL'),
    ],
];
