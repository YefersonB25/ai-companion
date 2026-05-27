<?php

return [
    'url'        => env('QDRANT_URL', 'http://localhost:6333'),
    'api_key'    => env('QDRANT_API_KEY'),
    'collection' => env('QDRANT_COLLECTION', 'ai_companion_memories'),
    'timeout'    => 10,
];
