<?php

return [
    // 'openai' or 'gemini'
    'provider'   => env('EMBEDDING_PROVIDER', 'openai'),
    'api_key'    => env('EMBEDDING_API_KEY'),
    'model'      => env('EMBEDDING_MODEL', 'text-embedding-3-small'),
    'dimensions' => (int) env('EMBEDDING_DIMENSIONS', 1536),
];
