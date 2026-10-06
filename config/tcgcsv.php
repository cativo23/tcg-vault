<?php

declare(strict_types=1);

return [
    'base_url' => env('TCGCSV_BASE_URL', 'https://tcgcsv.com'),

    // tcgcsv asks scrapers to identify themselves with a custom
    // User-Agent (https://tcgcsv.com, usage guidelines).
    'user_agent' => env('TCGCSV_USER_AGENT', 'tcg-vault/1 (+https://tcgvault.cativo.dev)'),

    // shadow: fetch and compare only, write no prices.
    'mode' => env('TCGCSV_MODE', 'shadow'),
];
