# AI Companion — Backend (Laravel)

API REST del asistente personal de IA. Multi-tenant, multi-proveedor, con memoria vectorial, WebSockets y bot de Telegram.

---

## Stack

| Capa | Tecnología |
|------|-----------|
| Framework | Laravel 13 + PHP 8.3 |
| Auth | Laravel Sanctum (tokens Bearer) |
| Base de datos | SQLite (dev) / MySQL o PostgreSQL (prod) |
| WebSockets | Laravel Reverb (puerto 8080) |
| Colas | Laravel Queue (driver `database`) |
| Vector DB | Qdrant 1.14+ |
| Embeddings | Gemini `gemini-embedding-001` (3072 dims) |
| Bot | Telegram Bot API (webhook) |

---

## Desarrollo local

### Requisitos
- PHP 8.3 + Composer
- Node.js 20+
- [Laravel Herd](https://herd.laravel.com/) (recomendado) o `php artisan serve`
- Qdrant binario: `~/bin/qdrant`

### Instalación

```bash
git clone https://github.com/YefersonB25/ai-companion.git
cd ai-companion
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
```

### Arrancar servicios

```bash
# Qdrant (vector DB) — en una terminal separada
~/bin/qdrant

# WebSockets (Reverb)
php artisan reverb:start

# Cola de trabajos
php artisan queue:work

# Con Herd el servidor PHP corre automáticamente en http://ai-companion.test
# Sin Herd:
php artisan serve
```

---

## Variables de entorno (.env)

```env
APP_NAME="AI Companion"
APP_URL=http://ai-companion.test

DB_CONNECTION=sqlite

# Reverb WebSockets
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=tu_app_id
REVERB_APP_KEY=tu_app_key
REVERB_APP_SECRET=tu_app_secret
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
DEEPSEEK_API_KEY=
MISTRAL_API_KEY=

# Herramientas del asistente
SERPER_API_KEY=       # búsqueda web
TAVILY_API_KEY=       # búsqueda web (fallback)
OPENWEATHER_API_KEY=  # clima

# Telegram bot
TELEGRAM_BOT_TOKEN=
TELEGRAM_WEBHOOK_URL=https://tu-dominio.com/api/telegram/webhook

# Push notifications (Expo)
EXPO_PUSH_URL=https://exp.host/--/api/v2/push/send
```

---

## API Reference

**Base URL producción:** `https://ai.omnirepair.online/api`

### Pública (sin auth)

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| POST | `/auth/register` | Registro de usuario |
| POST | `/auth/login` | Login → devuelve token |
| GET | `/providers/supported` | Proveedores de IA disponibles |
| GET | `/app/version?platform=android&version_code=N` | Verifica actualización disponible |
| POST | `/app/version` | Registra nueva versión (deploy) |
| POST | `/telegram/webhook` | Webhook del bot de Telegram |

### Autenticada (Bearer token)

**Auth**
| Método | Endpoint | Descripción |
|--------|----------|-------------|
| POST | `/auth/logout` | Cierra sesión (revoca token) |
| GET | `/auth/me` | Datos del usuario autenticado |

**Conversaciones**
| Método | Endpoint | Descripción |
|--------|----------|-------------|
| GET | `/conversations` | Lista conversaciones |
| POST | `/conversations` | Nueva conversación |
| GET | `/conversations/{id}` | Detalle de conversación |
| DELETE | `/conversations/{id}` | Elimina conversación |
| GET | `/conversations/{id}/messages` | Mensajes (con streaming SSE) |
| POST | `/conversations/{id}/messages` | Envía mensaje (soporta imagen) |
| GET | `/conversations/{id}/export` | Exporta conversación en JSON |

**Proveedores IA**
| Método | Endpoint | Descripción |
|--------|----------|-------------|
| GET | `/providers` | Lista proveedores configurados |
| POST | `/providers` | Agrega proveedor |
| PUT | `/providers/{id}` | Actualiza proveedor |
| DELETE | `/providers/{id}` | Elimina proveedor |

**Memoria**
| Método | Endpoint | Descripción |
|--------|----------|-------------|
| GET | `/memory` | Lista nodos de memoria |
| POST | `/memory` | Crea nodo manual |
| PUT | `/memory/{id}` | Actualiza nodo |
| DELETE | `/memory/{id}` | Elimina nodo |
| GET | `/memory/mindmap` | Estructura para mapa mental |
| GET | `/memory/search?q=texto` | Búsqueda semántica (Qdrant) |

**Otros**
| Método | Endpoint | Descripción |
|--------|----------|-------------|
| GET | `/settings` | Configuración del usuario |
| PUT | `/settings` | Actualiza configuración |
| GET | `/profile` | Perfil del usuario |
| PUT | `/profile` | Actualiza perfil |
| GET | `/briefing/today` | Briefing diario generado por IA |
| POST | `/device-tokens` | Registra token push (Expo) |
| DELETE | `/device-tokens` | Elimina token push |

---

## Comandos Artisan útiles

```bash
# Reindexar memoria en Qdrant
php artisan memory:reindex

# Registrar webhook de Telegram
php artisan telegram:set-webhook

# Eliminar webhook de Telegram
php artisan telegram:delete-webhook

# Publicar versión APK en la API
php artisan app:publish-version
```

---

## Bot de Telegram

Comandos disponibles:

| Comando | Descripción |
|---------|-------------|
| `/start` | Inicia el bot |
| `/login` | Vincula cuenta de AI Companion |
| `/register` | Crea cuenta nueva |
| `/new` | Nueva conversación |
| `/memory` | Ver nodos de memoria recientes |
| `/help` | Ayuda |

**Configurar en desarrollo (con ngrok):**
```bash
ngrok http 80
# Actualiza TELEGRAM_WEBHOOK_URL en .env y:
php artisan telegram:set-webhook
```

---

## Servicios en producción

Gestionados por **Supervisor** (`/etc/supervisor/conf.d/ai-companion.conf`):

| Proceso | Descripción |
|---------|-------------|
| `ai-companion-worker` | Cola de trabajos (×2 procesos) |
| `ai-companion-reverb` | WebSockets en puerto 8080 |
| `ai-companion-qdrant` | Vector DB Qdrant |
| `ai-companion-web` | Frontend Next.js en puerto 3000 |

```bash
# Ver estado
supervisorctl status

# Reiniciar workers
supervisorctl restart ai-companion-worker:*

# Ver logs
tail -f /var/www/ai-companion/storage/logs/worker.log
tail -f /var/www/ai-companion/storage/logs/reverb.log
```

---

## Deploy

Todo cambio al servidor debe venir de git. **Nunca editar archivos directamente en el servidor.**

### Flujo de trabajo

```bash
# 1. En tu máquina: hacer cambios, commit y push
git add .
git commit -m "feat: descripción del cambio"
git push origin main

# 2. En el servidor:
ssh root@134.122.21.84
deploy backend
```

El script `scripts/deploy.sh` ejecuta automáticamente:
1. `git pull origin main`
2. `composer install --no-dev --optimize-autoloader`
3. `php artisan migrate --force`
4. Reconstruye cachés (config, routes, views, events)
5. Reinicia workers, Reverb y PHP-FPM

### Revertir un error

```bash
# Revertir al commit anterior
deploy rollback backend

# Revertir a un commit específico (el hash se muestra al final de cada deploy)
deploy rollback backend abc1234
```

### Comando global `deploy` (en el servidor)

```
deploy backend              — pull + migrate + restart workers
deploy web                  — pull + npm build + restart Next.js
deploy all                  — ambos proyectos
deploy rollback backend     — revierte backend al commit anterior
deploy rollback web         — revierte web al commit anterior
deploy rollback backend abc123  — revierte a un commit específico
```

---

## Producción

| | |
|-|--|
| API | `https://ai.omnirepair.online/api` |
| WebSockets | `wss://ai.omnirepair.online/app/{key}` |
| Servidor | `root@134.122.21.84` |
| Panel Supervisor | `supervisorctl status` |
