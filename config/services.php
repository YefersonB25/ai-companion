<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'serper' => [
        'key' => env('SERPER_API_KEY'),
    ],

    'tavily' => [
        'key' => env('TAVILY_API_KEY'),
    ],

    'google' => [
        'client_id'     => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect'      => env('GOOGLE_REDIRECT_URI'),
        // Scopes solicitados durante el consentimiento OAuth:
        // - calendar.events: leer/escribir eventos (P-01 Google Calendar)
        // - gmail.readonly: lectura de correo (P-02 Gmail)
        // - openid/email/profile: identidad de la cuenta conectada
        'scopes' => [
            'https://www.googleapis.com/auth/calendar.events',
            'https://www.googleapis.com/auth/gmail.readonly',
            'openid',
            'email',
            'profile',
        ],
    ],

    /*
    | Text-to-Speech (Fase 5: voz neural premium).
    | Multi-proveedor con fallback, al estilo de AIRouter. El móvil reproduce
    | el mp3 devuelto por POST /api/tts, reemplazando el TTS robótico del sistema.
    */
    'tts' => [
        'default'  => env('TTS_PROVIDER', 'gemini'),
        'fallback' => env('TTS_FALLBACK', 'openai'),

        'gemini' => [
            'api_key' => env('GEMINI_TTS_API_KEY', env('EMBEDDING_API_KEY')),
            'model'   => env('GEMINI_TTS_MODEL', 'gemini-2.5-flash-preview-tts'),
            'voice'   => env('GEMINI_TTS_VOICE', 'Kore'),
        ],

        'elevenlabs' => [
            'api_key'  => env('ELEVENLABS_API_KEY'),
            'voice_id' => env('ELEVENLABS_VOICE_ID', 'EXAVITQu4vr4xnSDxMaL'),
            'model_id' => env('ELEVENLABS_MODEL', 'eleven_flash_v2_5'),
        ],

        'openai' => [
            'api_key' => env('OPENAI_TTS_API_KEY'),
            'model'   => env('OPENAI_TTS_MODEL', 'gpt-4o-mini-tts'),
            'voice'   => env('OPENAI_TTS_VOICE', 'nova'),
        ],
    ],

];
