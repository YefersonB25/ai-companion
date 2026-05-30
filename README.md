# AI Companion — Backend (Laravel)

API REST del asistente personal de IA "Aria". Multi-tenant, multi-proveedor, con memoria vectorial, WebSockets, bot de Telegram y panel de administración.

---

## Stack

| Capa | Tecnología |
|------|-----------|
| Framework | Laravel 13 + PHP 8.3 |
| Auth | Laravel Sanctum (tokens Bearer) |
| Base de datos | SQLite (dev) / MySQL (prod) |
| WebSockets | Laravel Reverb (puerto 8080) |
| Colas | Laravel Queue (driver `database`) |
| Vector DB | Qdrant 1.14+ |
| Embeddings | Gemini `gemini-embedding-001` (3072 dims) |
| Bot | Telegram Bot API (webhook) |
| Proveedor IA prod | Gemini 2.0 Flash (gemini-2.0-flash) |

---

## Desarrollo local

### Requisitos
- PHP 8.3 + Composer
- [Laravel Herd](https://herd.laravel.com/) (recomendado)
- Qdrant binario: `~/bin/qdrant`

### Instalación

```bash
git clone https://github.com/YefersonB25/ai-companion.git
cd ai-companion
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed --class=AdminSeeder  # crea usuario admin
```

### Arrancar servicios

```bash
~/bin/qdrant                    # Vector DB
php artisan reverb:start        # WebSockets
php artisan queue:work          # Colas
# Laravel Herd corre en http://ai-companion.test automáticamente
```

---

## Variables de entorno (.env)

```env
APP_NAME="AI Companion"
APP_URL=http://ai-companion.test

DB_CONNECTION=sqlite

# Reverb WebSockets
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=...
REVERB_APP_KEY=...
REVERB_APP_SECRET=...
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http

# Qdrant
QDRANT_URL=http://localhost:6333
QDRANT_COLLECTION=ai_companion_memories

# Proveedores IA (al menos uno requerido)
GEMINI_API_KEY=
OPENAI_API_KEY=
ANTHROPIC_API_KEY=

# Herramientas
SERPER_API_KEY=
TAVILY_API_KEY=
OPENWEATHER_API_KEY=

# Telegram
TELEGRAM_BOT_TOKEN=
TELEGRAM_WEBHOOK_URL=https://tu-dominio.com/api/telegram/webhook

# Push (Expo)
EXPO_PUSH_URL=https://exp.host/--/api/v2/push/send

# Admin panel (developer access only)
ADMIN_EMAIL=admin@aicompanion.dev
ADMIN_PASSWORD=
ADMIN_NAME="AI Companion Admin"
```

---

## Sistema de Aria (AI Identity)

El system prompt incluye la identidad completa de **Aria** y sus capacidades:
- Nombre: Aria, asistente tipo Jarvis
- Wake words: "Hey Aria", "Oye Aria", "Hola Aria"
- **Modo voz** (`voice=true`): respuestas 2-3 oraciones sin markdown
- **Modo conducción** (`driving_mode=true`): respuestas 1 oración
- **GPS context** (`location={lat,lng}`): respuestas contextualizadas por ubicación
- Acciones del teléfono: calls, SMS, WhatsApp, música, apps, pantalla, linterna, brillo, volumen, notificaciones

---

## API Reference

**Base URL producción:** `https://ai.omnirepair.online/api`

### Pública

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| POST | `/auth/register` | Registro |
| POST | `/auth/login` | Login → token |
| GET | `/providers/supported` | Proveedores disponibles |
| GET | `/app/version?platform=android&version_code=N` | Verifica actualización |
| POST | `/app/version` | Registra nueva versión APK |
| POST | `/telegram/webhook` | Webhook Telegram |

### Autenticada (Bearer token)

**Mensajes** — acepta campos opcionales:
- `voice: boolean` — activa modo voz (respuestas cortas)
- `driving_mode: boolean` — activa modo conducción
- `location: {lat, lng}` — contexto GPS
- `stream: boolean` — streaming SSE (default: true en chat, false en voz)

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| GET | `/conversations` | Lista conversaciones |
| POST | `/conversations` | Nueva conversación |
| DELETE | `/conversations/{id}` | Elimina conversación |
| GET | `/conversations/{id}/messages` | Mensajes (SSE streaming) |
| POST | `/conversations/{id}/messages` | Envía mensaje |
| GET | `/conversations/{id}/export` | Exporta en JSON |
| GET | `/providers` | Lista proveedores |
| POST/PUT/DELETE | `/providers/{id}` | CRUD proveedores |
| GET/PUT | `/memory` | Nodos de memoria |
| GET | `/memory/mindmap` | Estructura mapa mental |
| GET | `/memory/search?q=texto` | Búsqueda semántica (Qdrant) |
| GET/PUT | `/settings` | Configuración usuario |
| GET/PUT | `/profile` | Perfil usuario |
| GET | `/briefing/today` | Briefing diario |
| POST/DELETE | `/device-tokens` | Tokens push (Expo) |

### Admin (requiere `is_admin=true`)

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| GET | `/admin/dashboard` | Stats globales, gráficas |
| GET | `/admin/users` | Usuarios con brain score |
| GET | `/admin/users/{id}` | Detalle usuario + memoria |
| POST | `/admin/users/{id}/toggle-admin` | Cambiar rol admin |
| GET | `/admin/memory` | Cerebro global analytics |
| GET | `/admin/insights` | Insights generados por IA |

---

## Panel Admin — Acceso Exclusivo

El panel admin en `/admin` es solo para desarrolladores. Los usuarios normales **nunca** pueden acceder.

```bash
# Crear usuario admin (con .env configurado)
php artisan db:seed --class=AdminSeeder

# O manualmente
php artisan app:make-admin admin@email.com

# En producción
ssh root@134.122.21.84
php artisan app:make-admin tu@email.com
```

Credenciales se configuran en `.env` con `ADMIN_EMAIL`, `ADMIN_PASSWORD`, `ADMIN_NAME`.

---

## Comandos Artisan

```bash
php artisan memory:reindex           # Reindexar memoria en Qdrant
php artisan telegram:set-webhook     # Registrar webhook Telegram
php artisan telegram:delete-webhook  # Eliminar webhook
php artisan app:make-admin {email}   # Crear usuario admin
php artisan db:seed --class=AdminSeeder  # Seeder admin desde .env
```

---

## Servicios en producción

Supervisor (`/etc/supervisor/conf.d/ai-companion.conf`):

| Proceso | Descripción |
|---------|-------------|
| `ai-companion-worker` | Cola de trabajos (×2) |
| `ai-companion-reverb` | WebSockets puerto 8080 |
| `ai-companion-qdrant` | Vector DB Qdrant |
| `ai-companion-web` | Frontend Next.js puerto 3000 |

```bash
supervisorctl status
tail -f /var/www/ai-companion/storage/logs/worker.log
```

---

## Deploy

**Nunca editar archivos directamente en el servidor.**

```bash
# Local: commit y push
git push origin main

# Servidor
ssh root@134.122.21.84
deploy backend        # pull + migrate + restart workers
deploy web            # pull + npm build + restart Next.js
deploy all            # ambos
deploy rollback backend          # revertir al commit anterior
deploy rollback backend abc123   # revertir a commit específico
```

---

## Producción

| | |
|-|--|
| API | `https://ai.omnirepair.online/api` |
| Web | `https://ai.omnirepair.online` |
| WebSockets | `wss://ai.omnirepair.online/app/{key}` |
| Servidor | `root@134.122.21.84` |
| DB | MySQL — `ai_companion` |
| Proveedor IA | Gemini 2.0 Flash (free tier: 1500 req/día) |
