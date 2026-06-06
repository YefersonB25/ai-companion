<?php

namespace Database\Seeders;

use App\Models\SecretRegistry;
use Illuminate\Database\Seeder;

/**
 * Precarga el registro de secretos del proyecto (DOCUMENTACIÓN — sin valores).
 *
 * Idempotente: usa updateOrCreate por `env_var`. NUNCA siembra valores de secretos;
 * solo metadatos. Los valores reales viven únicamente en `.env`.
 */
class SecretRegistrySeeder extends Seeder
{
    public function run(): void
    {
        $secrets = [
            [
                'env_var'      => 'EMBEDDING_API_KEY',
                'label'        => 'Google Gemini — Embeddings / Memoria',
                'app'          => 'backend',
                'provider'     => 'Google Gemini',
                'description'  => 'Embeddings para memoria vectorial (Qdrant). También sirve de fallback para Gemini TTS cuando GEMINI_TTS_API_KEY no está definida.',
                'used_in'      => 'Memoria vectorial (Qdrant) · config/services.php tts.gemini.api_key (fallback)',
                'rotation_url' => 'https://aistudio.google.com/apikey',
                'status'       => 'active',
                'sort_order'   => 10,
            ],
            [
                'env_var'      => 'ELEVENLABS_API_KEY',
                'label'        => 'ElevenLabs — Voz neural premium',
                'app'          => 'backend',
                'provider'     => 'ElevenLabs',
                'description'  => 'Voz neural premium (TTS) para reproducir respuestas habladas.',
                'used_in'      => 'config/services.php tts.elevenlabs',
                'rotation_url' => 'https://elevenlabs.io',
                'status'       => 'active',
                'sort_order'   => 20,
            ],
            [
                'env_var'      => 'OPENAI_TTS_API_KEY',
                'label'        => 'OpenAI — Voz neural (opcional)',
                'app'          => 'backend',
                'provider'     => 'OpenAI',
                'description'  => 'Voz neural de OpenAI (TTS). Opcional, usada como proveedor/fallback alternativo.',
                'used_in'      => 'config/services.php tts.openai',
                'rotation_url' => 'https://platform.openai.com/api-keys',
                'status'       => 'active',
                'sort_order'   => 30,
            ],
            [
                'env_var'      => 'GOOGLE_CLIENT_ID',
                'label'        => 'Google Cloud — OAuth Client ID',
                'app'          => 'backend',
                'provider'     => 'Google Cloud',
                'description'  => 'Client ID de OAuth para Google Calendar / Gmail (Fase 4).',
                'used_in'      => 'config/services.php google.client_id',
                'rotation_url' => 'https://console.cloud.google.com/apis/credentials',
                'status'       => 'active',
                'sort_order'   => 40,
            ],
            [
                'env_var'      => 'GOOGLE_CLIENT_SECRET',
                'label'        => 'Google Cloud — OAuth Client Secret',
                'app'          => 'backend',
                'provider'     => 'Google Cloud',
                'description'  => 'Client Secret de OAuth para Google Calendar / Gmail (Fase 4).',
                'used_in'      => 'config/services.php google.client_secret',
                'rotation_url' => 'https://console.cloud.google.com/apis/credentials',
                'status'       => 'active',
                'sort_order'   => 50,
            ],
            [
                'env_var'      => 'GOOGLE_REDIRECT_URI',
                'label'        => 'Google Cloud — OAuth Redirect URI',
                'app'          => 'backend',
                'provider'     => 'Google Cloud',
                'description'  => 'Redirect URI registrado para el callback OAuth de Google (Calendar/Gmail, Fase 4).',
                'used_in'      => 'config/services.php google.redirect · /api/integrations/google/callback',
                'rotation_url' => 'https://console.cloud.google.com/apis/credentials',
                'status'       => 'active',
                'sort_order'   => 60,
            ],
            [
                'env_var'      => 'TELEGRAM_BOT_TOKEN',
                'label'        => 'Telegram — Bot Token (BotFather)',
                'app'          => 'backend',
                'provider'     => 'Telegram / BotFather',
                'description'  => 'Token del bot de Telegram para enviar/recibir mensajes vía webhook.',
                'used_in'      => 'Bot de Telegram · /api/telegram/webhook',
                'rotation_url' => 'https://t.me/BotFather',
                'status'       => 'needs_rotation',
                'notes'        => 'Estaba commiteado en AI_COMPANION_STATUS.md; rotar en BotFather (/revoke).',
                'sort_order'   => 70,
            ],
            [
                'env_var'      => 'REVERB_APP_KEY',
                'label'        => 'Laravel Reverb — App Key',
                'app'          => 'shared',
                'provider'     => 'Laravel Reverb',
                'description'  => 'Clave pública de la app Reverb para websockets / broadcasting.',
                'used_in'      => 'config/broadcasting.php connections.reverb.key',
                'rotation_url' => null,
                'status'       => 'active',
                'sort_order'   => 80,
            ],
            [
                'env_var'      => 'REVERB_APP_SECRET',
                'label'        => 'Laravel Reverb — App Secret',
                'app'          => 'backend',
                'provider'     => 'Laravel Reverb',
                'description'  => 'Secreto de la app Reverb para autenticar el servidor de websockets / broadcasting.',
                'used_in'      => 'config/broadcasting.php connections.reverb.secret',
                'rotation_url' => null,
                'status'       => 'active',
                'sort_order'   => 90,
            ],
            [
                'env_var'      => 'SERPER_API_KEY',
                'label'        => 'Serper.dev — Búsqueda web (prioridad 1)',
                'app'          => 'backend',
                'provider'     => 'Serper.dev',
                'description'  => 'API de Google Search vía Serper.dev. Proveedor primario de la herramienta de búsqueda web.',
                'used_in'      => 'config/services.php serper.key · tool web_search',
                'rotation_url' => 'https://serper.dev/api-key',
                'status'       => 'active',
                'sort_order'   => 100,
            ],
            [
                'env_var'      => 'TAVILY_API_KEY',
                'label'        => 'Tavily — Búsqueda web (backup)',
                'app'          => 'backend',
                'provider'     => 'Tavily',
                'description'  => 'Búsqueda web de respaldo automático cuando Serper falla o se agota.',
                'used_in'      => 'config/services.php tavily.key · tool web_search',
                'rotation_url' => 'https://app.tavily.com/home',
                'status'       => 'active',
                'sort_order'   => 110,
            ],
            [
                'env_var'      => 'APP_KEY',
                'label'        => 'Laravel — App Key (cifrado)',
                'app'          => 'backend',
                'provider'     => 'Laravel',
                'description'  => 'Clave de cifrado de la aplicación (secreto de arranque, NO editable / NO rotar a la ligera).',
                'used_in'      => 'config/app.php key · cifrado de tokens y datos sensibles',
                'rotation_url' => null,
                'status'       => 'active',
                'notes'        => 'Bootstrap: rotarla invalida todos los tokens cifrados.',
                'sort_order'   => 120,
            ],
        ];

        foreach ($secrets as $secret) {
            SecretRegistry::updateOrCreate(
                ['env_var' => $secret['env_var']],
                $secret
            );
        }
    }
}
