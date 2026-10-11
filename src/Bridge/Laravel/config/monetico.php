<?php

declare(strict_types=1);

return [

    /*
    |---------------------------------------------------------------------------
    | Terminal, key and company code
    |---------------------------------------------------------------------------
    |
    | The three values Monetico issues per point of sale. They differ between
    | the sandbox and production, all three of them - so they belong in the
    | environment file and not in here.
    |
    | The key is 40 hexadecimal characters. It is the one secret in this
    | protocol: anything that holds it can take a payment in the shop's name
    | and can forge a payment confirmation to itself.
    |
    */

    'terminal' => env('MONETICO_TERMINAL'),

    'key' => env('MONETICO_KEY'),

    'company' => env('MONETICO_COMPANY'),

    /*
    |---------------------------------------------------------------------------
    | Environment
    |---------------------------------------------------------------------------
    |
    | "sandbox" or "production". Deliberately not derived from the
    | application's own environment: a staging server pointed at production
    | takes real money, and a production server pointed at the sandbox takes
    | none while reporting success.
    |
    */

    'environment' => env('MONETICO_ENVIRONMENT', 'sandbox'),

    /*
    |---------------------------------------------------------------------------
    | Seal algorithms
    |---------------------------------------------------------------------------
    |
    | Notifications are sealed with the algorithm in force when the order was
    | created, and an instalment plan can call back months later. Leave this on
    | while any such order can still report in; turn it off once none can, and
    | the older algorithm stops being accepted at all.
    |
    */

    'accept_legacy_seals' => (bool) env('MONETICO_ACCEPT_LEGACY_SEALS', true),

    /*
    |---------------------------------------------------------------------------
    | Timezone
    |---------------------------------------------------------------------------
    |
    | The dates sent to the platform are formatted in this zone. Monetico is a
    | French bank's platform and reads them as Paris local time; an application
    | running in UTC that sends UTC gets a dashboard stamped two hours early.
    |
    */

    'timezone' => env('MONETICO_TIMEZONE', 'Europe/Paris'),

];
