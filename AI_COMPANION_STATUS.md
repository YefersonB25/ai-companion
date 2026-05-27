# AI Companion — Estado del Proyecto
**Última actualización:** 2026-05-25

---

## Stack tecnológico

| Capa | Tecnología | Ruta |
|------|-----------|------|
| Backend | Laravel 13 + Sanctum | `/Users/yefersonbc/Herd/ai-companion` |
| Frontend | Next.js 16 + Tailwind + shadcn/ui | `/Users/yefersonbc/Herd/ai-companion-web` |
| Mobile | Expo SDK 56 + React Native + NativeWind v4 | `/Users/yefersonbc/Herd/ai-companion-mobile` |
| Vector DB | Qdrant 1.14.0 (binario local) | `~/bin/qdrant` |
| WebSockets | Laravel Reverb | Puerto 8080 |
| Bot | Telegram `@AiCompanionYefBot` | — |

---

## Para arrancar el entorno de desarrollo

```bash
# 1. Qdrant (vector DB)
~/bin/qdrant

# 2. Reverb (WebSockets) — desde el backend
cd /Users/yefersonbc/Herd/ai-companion
php artisan reverb:start

# 3. Frontend
cd /Users/yefersonbc/Herd/ai-companion-web
npm run dev

# 4. Mobile
cd /Users/yefersonbc/Herd/ai-companion-mobile
npm start

# 5. Telegram webhook (necesita ngrok corriendo)
php artisan telegram:set-webhook
```

> Laravel/Herd corre automáticamente en http://ai-companion.test

---

## Variables de entorno importantes (.env backend)

```env
DB_CONNECTION=sqlite
BROADCAST_CONNECTION=reverb

REVERB_APP_ID=633172
REVERB_APP_KEY=cca9a7413d67828704e7119a2c2fb777
REVERB_APP_SECRET=23b8f1cbb2ace20dbcc3dc73087a733c77497ac21a67aab5ff4039e3f29087d6
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http

QDRANT_URL=http://localhost:6333
QDRANT_COLLECTION=ai_companion_memories

EMBEDDING_PROVIDER=gemini
EMBEDDING_MODEL=gemini-embedding-001
EMBEDDING_DIMENSIONS=3072
EMBEDDING_API_KEY=AIzaSyBsdEPvdl1ah1WY_pwt8VStTx-46W8--ek

TELEGRAM_BOT_TOKEN=8806628995:AAHY4lRoKIMO_qANqjvXJaCC7rzFFNeW_qw
TELEGRAM_WEBHOOK_URL=https://polypoid-simon-preintimately.ngrok-free.dev/api/telegram/webhook
```

---

## Lo que está COMPLETADO ✅

### Backend (Laravel)

- [x] **Auth** — registro, login, logout con Laravel Sanctum (tokens)
- [x] **AI Router** — abstracción multi-proveedor con fallback automático
  - Soporta: Claude, OpenAI, DeepSeek, Gemini, Mistral
  - `app/Services/AI/AIRouter.php`
- [x] **Proveedores IA** — CRUD completo, API key encriptada, proveedor por defecto
  - Bug corregido: `AiProviderController::authorize()` → reemplazado por ownership check
  - `app/Http/Controllers/Api/AiProviderController.php`
- [x] **Conversaciones y Mensajes** — historial completo, SSE streaming
  - `app/Http/Controllers/Api/MessageController.php`
- [x] **Base Controller** — tiene `AuthorizesRequests` trait (necesario para Policies)
- [x] **Memoria vectorial con Qdrant**
  - `app/Services/Qdrant/QdrantService.php` — CRUD contra Qdrant REST API
  - `app/Services/AI/EmbeddingService.php` — Gemini `gemini-embedding-001` (3072 dims)
  - `app/Services/Memory/MemoryService.php` — store/recall con fallback a keyword search
  - Colección: `ai_companion_memories` (3072 dimensiones, distancia Cosine)
  - Comando: `php artisan memory:reindex` para reindexar todos los nodos
- [x] **WebSockets con Laravel Reverb**
  - `app/Events/MessageCreated.php` — canal privado `conversations.{id}`
  - `app/Events/MemoryNodeSaved.php` — canal privado `users.{id}`
  - `routes/channels.php` — autorización por ownership
  - Ruta auth con Sanctum: `Broadcast::routes(['middleware' => 'auth:sanctum'])`
- [x] **Push Notifications (Expo Push API)**
  - `app/Services/PushNotificationService.php` — envía via Expo Push API (HTTP)
  - `app/Models/DeviceToken.php` + tabla `device_tokens`
  - `app/Http/Controllers/Api/DeviceTokenController.php`
  - Endpoints: `POST /api/device-tokens`, `DELETE /api/device-tokens`
  - Integrado en MessageController y TelegramBotService
- [x] **Bot de Telegram** — `@AiCompanionYefBot`
  - Commands: `/start` `/login` `/register` `/new` `/memory` `/help`
  - State machine: `idle → awaiting_email → awaiting_password → linked`
  - Chat con IA usando proveedor configurado del usuario
  - Envía push notification al móvil después de responder
  - `app/Services/Telegram/TelegramBotService.php`
- [x] **Settings** — persona JSON, routing_rules, memory_enabled, stream_responses
- [x] **26 endpoints REST** en `routes/api.php`

### Frontend (Next.js)

- [x] Auth con Zustand store persistente (login/register/logout)
- [x] Chat con streaming SSE en tiempo real
- [x] Mapa mental interactivo con React Flow (`/memory`)
- [x] Gestión de proveedores IA (`/providers`)
- [x] **Reverb/WebSockets integrado**
  - `src/lib/echo.ts` — singleton Laravel Echo con Reverb
  - `src/hooks/useRealtimeChat.ts` — mensajes en tiempo real en conversación activa
  - `src/hooks/useRealtimeMemory.ts` — nodos nuevos aparecen en el mapa mental automáticamente
- [x] Páginas: `/chat`, `/chat/[id]`, `/memory`, `/providers`, `/settings`, `/login`, `/register`

### Mobile (Expo)

- [x] Auth con `expo-secure-store` (más seguro que localStorage)
- [x] 5 tabs: Chat (con streaming), Historial, Memoria, Proveedores IA, Configuración
- [x] NativeWind v4 para estilos
- [x] **Push notifications**
  - `lib/notifications.ts` — pide permisos, obtiene token Expo, lo registra en backend
  - `_layout.tsx` — sincroniza token al login, navega a conversación al tocar notificación
  - Canal Android configurado con prioridad máxima

---

## Lo que queda PENDIENTE ⏳

### Prioridad alta

- [ ] **Deploy a producción** — sin esto el webhook de Telegram depende de ngrok (se cae al apagar el equipo)
  - Opción recomendada: VPS con Laravel Forge o Railway/Fly.io
  - Cambiar `TELEGRAM_WEBHOOK_URL` al dominio real
  - Registrar webhook nuevo: `php artisan telegram:set-webhook`
  - Configurar Qdrant en el servidor (Docker o Qdrant Cloud)

### Prioridad media

- [ ] **Extracción de memoria con IA** — actualmente usa regex simples para detectar preferencias/proyectos en el texto. Mejorar con una llamada real a la IA para extraer entidades estructuradas de la conversación
- [ ] **Títulos automáticos de conversación con IA** — hoy usa los primeros 50 chars del mensaje. Mejor: pedir a la IA un título de 5 palabras
- [ ] **Búsqueda en historial** — endpoint y UI para buscar en conversaciones pasadas
- [ ] **Clasificación de tareas para routing** — el `applyRoutingRules()` en AIRouter devuelve null siempre. Completar con clasificación por tipo de tarea (código → OpenAI, análisis → Claude, etc.)

### Prioridad baja / nice to have

- [ ] **Qdrant Cloud** para no depender del binario local en producción
- [ ] **Reverb en producción** — configurar con SSL (wss://) y supervisord/systemd
- [ ] **Multi-usuario real** — sistema de planes/subscripción para SaaS
- [ ] **Adjuntos e imágenes** — enviar imágenes al chat (vision models)
- [ ] **Exportar memoria** — descargar el mapa mental como JSON/PNG
- [ ] **Estadísticas de uso** — tokens consumidos, costo estimado por proveedor
- [ ] **Tests automatizados** — Feature tests para los endpoints principales

---

## Proveedores configurados (usuario ID=1)

| Proveedor | Modelo | Estado |
|-----------|--------|--------|
| DeepSeek | deepseek-chat | Activo / Predeterminado |

---

## Notas importantes

- **ngrok URL cambia** cada vez que se reinicia. Siempre verificar con `curl http://127.0.0.1:4040/api/tunnels` y re-registrar el webhook.
- **Qdrant no persiste** si se inicia sin `--storage-path`. El binario en `~/bin/qdrant` guarda datos en `./storage` relativo al directorio donde se ejecuta. Ejecutar siempre desde la misma carpeta o especificar path.
- **Mobile IP** — cambiar `API_URL` en `lib/api.ts` según la red WiFi actual (actualmente `192.168.2.35`).
- **Push notifications** solo funcionan en dispositivo físico, no en simulador.
- **Reverb** y **Qdrant** deben estar corriendo para que funcionen WebSockets y memoria vectorial respectivamente.
