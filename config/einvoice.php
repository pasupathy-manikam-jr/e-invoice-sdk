<?php

return [
    // Driver used to talk to LHDN: 'jiannius' or 'fake'.
    'driver' => env('EINVOICE_DRIVER', 'jiannius'),

    // Queue for submit/poll/cancel jobs. Null uses the app default.
    'queue' => env('EINVOICE_QUEUE'),
];
