<?php

return [

    /*
    | Our margin over the provider cost, in percent. Fixed by the assessment;
    | not editable from the admin settings.
    */

    'markup_percent' => 25,

    /*
    | Fictitious credit packs (USD) offered by "Buy credits". No real payment.
    */

    'credit_packs' => ['5', '10', '20'],

    /*
    | Defaults for the admin settings (settings table overrides them).
    */

    'settings' => [
        'daily_limit' => 20,
        'timezone' => 'UTC',
        'registration_open' => '1',
    ],

    /*
    | How long the live GET /v1/pricing table is cached.
    */

    'pricing_cache_seconds' => 300,

    /*
    | Fallback pricing table, used when GET /v1/pricing is unreachable.
    | Same shape as the API response. Rates are USD per 1M tokens, as strings.
    */

    'fallback_pricing' => [
        'rates' => [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],
        'tokens' => [
            'characters_per_text_token' => 4,
            'text_token_variance' => '0.15',
            'per_reference_image' => 765,
            'output' => [
                'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
            ],
            'reasoning' => [
                'low' => ['min' => 0, 'max' => 500],
                'medium' => ['min' => 200, 'max' => 2000],
                'high' => ['min' => 1000, 'max' => 8000],
            ],
        ],
    ],

];
