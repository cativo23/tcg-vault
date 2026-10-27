<?php

declare(strict_types=1);

return [
    'base_url' => env('TCGCSV_BASE_URL', 'https://tcgcsv.com'),

    // tcgcsv asks scrapers to identify themselves with a custom
    // User-Agent (https://tcgcsv.com, usage guidelines).
    'user_agent' => env('TCGCSV_USER_AGENT', 'tcg-vault/1 (+https://tcgvault.cativo.dev)'),

    // 'fill' writes tcgcsv's TCGplayer price for linked prints tcgdex
    // doesn't price, and keeps each checked price so the tcgdex sync leaves
    // that print's row to the evening run; 'shadow' only compares and writes
    // nothing (rollback).
    'mode' => env('TCGCSV_MODE', 'fill'),

    // 'log' makes the run judge every base print by its product's printed
    // number and name and report what it would link (the run summary's
    // `identity` block), asking tcgcsv for each mapped group's products too.
    // It writes nothing; any other value leaves it off.
    'identity_links' => env('TCGCSV_IDENTITY_LINKS'),

    // TCGplayer groups whose products belong to many sets, so no set maps
    // them (1840 is "Deck Exclusives": a deck's non-holo print is a product
    // of its own there). A card links to one of their products only when it
    // is that card's plain `normal` print on a product of its own and the
    // product's number, total and name match the card; removing a group here
    // does not undo links it already made.
    'extra_groups' => [1840],
];
