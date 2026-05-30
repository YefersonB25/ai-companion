# AI Companion — Backend (Laravel)

API REST del asistente personal de IA **Aria**. Multi-tenant, multi-proveedor, con memoria vectorial, WebSockets, bot de Telegram y panel de administración exclusivo para desarrolladores.

---

## Stack

| Capa | Tecnología |
|------|-----------|
| Framework | Laravel 13 + PHP 8.3 |
| Auth | Laravel Sanctum (tokens Bearer) |
| Base de datos | SQLite (dev) / MySQL (prod: `ai_companion`) |
| WebSockets | Laravel Reverb (puerto 8080) |
| Colas | Laravel Queue (driver `database`, tries=3) |
| Vector DB | Qdrant 1.14+ |
| Embeddings | Gemini `gemini-embedding-001` (3072 dims, caché 24h) |
| Proveedor IA prod | Gemini 2.0 Flash (free: 1500 req/día) |
| Bot | Telegram Bot API (webhook con validación de firma) |

---

## Desarrollo local

### Requisitos
- PHP 8.3 + Composer
- [Laravel Herd](https://herd.laravel.com/)
- Qdrant binario: `~/bin/qdrant`

### Instalación

```bash
git clone https://github.com/YefersonB25/ai-companion.git
cd ai-companion
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed --class=AdminSeeder   # crea usuario admin desde .env
```

### Arrancar

```bash
~/bin/qdrant                   # Vector DB
php artisan reverb:start       # WebSockets
php artisan queue:work         # Colas
# Herd corre en http://ai-companion.test automáticamente
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

# Qdrant
QDRANT_URL=http://localhost:6333
QDRANT_COLLECTION=ai_companion_memories

# Proveedores IA
GEMINI_API_KEY=
OPENAI_API_KEY=
ANTHROPIC_API_KEY=
DEEPSEEK_API_KEY=
MISTRAL_API_KEY=

# Herramientas del asistente
SERPER_API_KEY=      # búsqueda web
TAVILY_API_KEY=      # búsqueda web fallback
OPENWEATHER_API_KEY= # clima

# Telegram
TELEGRAM_BOT_TOKEN=
TELEGRAM_WEBHOOK_URL=https://tu-dominio.com/api/telegram/webhook
TELEGRAM_WEBHOOK_SECRET=   # valida firma del webhook

# Push (Expo)
EXPO_PUSH_URL=https://exp.host/--/api/v2/push/send

# CORS (separado por comas)
CORS_ALLOWED_ORIGINS=http://localhost:3000,https://ai.omnirepair.online

# Admin panel (solo desarrolladores)
ADMIN_EMAIL=admin@aicompanion.dev
ADMIN_PASSWORD=
ADMIN_NAME="AI Companion Admin"
```

---

## Aria — Identidad del asistente

El system prompt define a **Aria** como asistente personal tipo Jarvis:

- **Nombre**: Aria, asistente de AI Companion
- **Wake words**: "Hey Aria", "Oye Aria", "Hola Aria"
- **Modo voz** (`voice=true`): respuestas 2-3 oraciones sin markdown
- **Modo conducción** (`driving_mode=true`): 1 oración máximo, prioridad seguridad
- **Contexto GPS** (`location={lat,lng}`): respuestas contextualizadas por ubicación

### Acciones del teléfono (bloques `[ACTION]...[/ACTION]`)

| Acción | JSON |
|--------|------|
| Llamar | `{"type":"make_call","contact":"nombre o número"}` |
| SMS | `{"type":"send_sms","contact":"...","message":"..."}` |
| WhatsApp | `{"type":"send_whatsapp","contact":"...","message":"..."}` |
| Email | `{"type":"send_email","to":"...","subject":"...","body":"..."}` |
| Música (reanudar) | `{"type":"play_music","resume":true}` |
| Música (buscar) | `{"type":"play_music","query":"...","app":"spotify\|youtubemusic"}` |
| Abrir app | `{"type":"open_app","name":"whatsapp\|telegram\|spotify\|..."}` |
| Bloquear pantalla | `{"type":"screen_off"}` |
| Encender pantalla | `{"type":"screen_on"}` |
| Linterna | `{"type":"flashlight","on":true\|false}` |
| Volumen | `{"type":"set_volume","level":0-15}` |
| Brillo | `{"type":"set_brightness","level":0-255}` |
| Notificaciones | `{"type":"read_notifications"}` |
| Recordatorio | `{"type":"set_reminder","when":"ISO8601","message":"..."}` |

---

## API Reference

**Base URL producción:** `https://ai.omnirepair.online/api`

### Rate limiting
- Auth: `10 req/min` por IP
- Messages: `60 req/min` por usuario

### Pública

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| POST | `/auth/register` | Registro |
| POST | `/auth/login` | Login → token |
| GET | `/providers/supported` | Proveedores IA disponibles |
| GET | `/app/version?platform=android&version_code=N` | Verifica actualización |
| POST | `/telegram/webhook` | Webhook Telegram (valida firma) |

### Autenticada (Bearer token)

El endpoint de mensajes acepta campos opcionales:
- `voice: boolean` — modo voz (respuestas cortas sin markdown)
- `driving_mode: boolean` — modo conducción (1 oración)
- `location: {lat, lng}` — contexto de ubicación
- `stream: boolean` — streaming SSE (default: true)

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| POST/GET | `/auth/logout`, `/auth/me` | Auth |
| GET | `/conversations` | Lista |
| POST | `/conversations` | Nueva |
| DELETE | `/conversations/{id}` | Elimina |
| GET | `/conversations/{id}/messages?per_page=50` | Mensajes paginados |
| POST | `/conversations/{id}/messages` | Envía mensaje |
| GET | `/conversations/{id}/export` | Exporta JSON |
| GET/POST/PUT/DELETE | `/providers/{id}` | CRUD proveedores |
| GET/POST/PUT/DELETE | `/memory/{id}` | CRUD memorias |
| GET | `/memory/mindmap` | Mapa mental |
| GET | `/memory/search?q=texto` | Búsqueda semántica (Qdrant) |
| GET/PUT | `/settings` | Configuración |
| GET/PUT | `/profile` | Perfil personal |
| GET | `/briefing/today` | Briefing diario |
| POST/DELETE | `/device-tokens` | Tokens push |

### Admin (`is_admin=true` requerido)

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| GET | `/admin/dashboard` | Stats globales (caché 5min) |
| GET | `/admin/users` | Usuarios con brain score (paginate 50) |
| GET | `/admin/users/{id}` | Detalle + memoria del usuario |
| POST | `/admin/users/{id}/toggle-admin` | Cambiar rol (con audit log) |
| GET | `/admin/memory` | Cerebro global |
| GET | `/admin/insights` | Insights generados por IA |
| POST | `/admin/app/version` | Registrar nueva versión APK |

---

## Panel Admin — Acceso exclusivo

El panel admin es solo para desarrolladores. **Nunca** accesible por usuarios normales.

```bash
# Crear admin desde .env
php artisan db:seed --class=AdminSeeder

# O manualmente
php artisan app:make-admin tu@email.com

# En producción
ssh root@134.122.21.84
php artisan app:make-admin yefersonbolano25@gmail.com
```

El campo `is_admin=false` está hardcodeado en el registro normal. Cambios de rol se loggean con IP, quién y cuándo.

---

## Comandos Artisan

```bash
php artisan memory:reindex                       # Reindexar Qdrant
php artisan telegram:set-webhook                 # Registrar webhook
php artisan telegram:delete-webhook              # Eliminar webhook
php artisan app:make-admin {email}               # Crear admin
php artisan db:seed --class=AdminSeeder          # Seeder admin desde .env
```

---

## Seguridad implementada

- Rate limiting en auth (10/min) y messages (60/min)
- `POST /api/app/version` protegido con `is_admin` middleware
- Telegram webhook valida `X-Telegram-Bot-API-Secret-Token`
- `parent_id` en memorias verifica ownership (IDOR fix)
- CORS desde variable de entorno `CORS_ALLOWED_ORIGINS`
- Audit log en cambios de rol admin
- Arrays en ProfileController con límite `max:20`

---

## Servicios en producción

Supervisor (`/etc/supervisor/conf.d/ai-companion.conf`):

| Proceso | Descripción |
|---------|-------------|
| `ai-companion-worker` | Cola de trabajos (×2, tries=3) |
| `ai-companion-reverb` | WebSockets puerto 8080 |
| `ai-companion-qdrant` | Vector DB Qdrant |
| `ai-companion-web` | Frontend Next.js puerto 3000 |

```bash
supervisorctl status
tail -f /var/www/ai-companion/storage/logs/worker.log
tail -f /var/www/ai-companion/storage/logs/reverb.log
```

---

## Deploy

**Nunca editar archivos directamente en el servidor.**

```bash
# Local: commit y push
git push origin main

# Servidor
ssh root@134.122.21.84
deploy backend         # git pull + migrate + restart workers
deploy web             # git pull + npm build + restart Next.js
deploy all             # ambos
deploy rollback backend [hash]
deploy rollback web [hash]
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
| Nginx | `/api/*` → Laravel, resto → Next.js:3000 |

---

## Archivo de issues

Ver `AI_COMPANION_ISSUES.md` — tracking completo de la auditoría con 31 issues resueltos (10 críticos, 11 importantes, 10 mejoras).
