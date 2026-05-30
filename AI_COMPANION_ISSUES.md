# AI Companion — Issues & Mejoras

> Archivo de seguimiento de la auditoría del 2026-05-30.
> Tachar con ~~texto~~ cuando se complete. Agregar fecha al tachar.

---

## 🔴 CRÍTICOS (seguridad y bugs bloqueantes)

### Seguridad — Backend
- [x] ~~**B-01** `POST /api/app/version` es público — cualquiera puede publicar versiones falsas~~ ✅ 2026-05-30  
  ~~_Fix: mover a grupo `auth:sanctum + is_admin`_~~
- [x] ~~**B-02** Webhook de Telegram sin validación de firma — cualquiera puede inyectar mensajes~~ ✅ 2026-05-30  
  ~~_Fix: validar `X-Telegram-Bot-API-Secret-Token` header_~~
- [x] ~~**B-03** Sin rate limiting en `/auth/login`, `/auth/register`, `/messages`~~ ✅ 2026-05-30  
  ~~_Fix: throttle 10/min en auth, 60/min en messages_~~
- [x] ~~**B-04** `parent_id` en memorias no valida propiedad del usuario — IDOR~~ ✅ 2026-05-30  
  ~~_Fix: verificar que parent_id.user_id === auth user_~~

### Seguridad — Mobile
- [x] ~~**M-01** Token de sesión en `SharedPreferences` sin cifrar — legible con ADB~~ ✅ 2026-05-30  
  ~~_Fix: EncryptedSharedPreferences AES-256 GCM + clave en Android Keystore_~~
- [x] ~~**M-02** Permiso `ACCESS_COARSE_LOCATION` falta en AndroidManifest — GPS siempre null~~ ✅ 2026-05-30  
  ~~_Fix: agregar permission + request runtime_~~

### Bugs funcionales — Web
- [x] ~~**W-01** Tabla "Usuarios por cerebro" en `/admin/memory` hardcodeada vacía — feature roto~~ ✅ 2026-05-30  
  ~~_Fix: cargar datos reales desde `/api/admin/users`_~~
- [x] ~~**W-02** Markdown de mensajes no se renderiza — `**texto**` se ve literal~~ ✅ 2026-05-30  
  ~~_Fix: react-markdown + remark-gfm + @tailwindcss/typography_~~

### Bugs funcionales — Mobile
- [x] ~~**M-03** Si `callApi()` lanza excepción, `state` queda en `THINKING` — servicio congelado~~ ✅ 2026-05-30  
  ~~_Fix: agregar `restartVosk()` en el catch de callApi_~~
- [x] ~~**M-04** NPE en `play_music`: `getLaunchIntentForPackage()` puede devolver null~~ ✅ 2026-05-30  
  ~~_Fix: null-check antes de `.addFlags()`_~~

---

## 🟡 IMPORTANTES (próxima semana)

### Performance — Backend
- [x] ~~**B-05** N+1 queries en `AdminController::users`~~ ✅ 2026-05-30
- [x] ~~**B-06** `AdminController::users` sin paginación~~ ✅ 2026-05-30 — paginate(50)
- [x] ~~**B-07** Sin caché en dashboard~~ ✅ 2026-05-30 — stats 5min, gráficas 1min
- [x] ~~**B-08** `EmbeddingService::embed` sin caché~~ ✅ 2026-05-30 — caché 24h SHA-256

### UX — Web
- [x] ~~**W-03** Sin feedback cuando Reverb se desconecta~~ ✅ 2026-05-30 — banner ámbar
- [x] ~~**W-04** Errores de API sin feedback visual~~ ✅ 2026-05-30 — error banner en admin pages
- [x] ~~**W-05** Admin panel no responsive~~ ✅ 2026-05-30 — NeuralGraph responsive, overflow-x

### UX — Mobile
- [x] ~~**M-05** Onboarding marca completado antes de terminar~~ ✅ N/A — comportamiento correcto
- [x] ~~**M-06** Race condition TTS/STT en dispositivos lentos~~ ✅ 2026-05-30 — delay 2000ms

### Configuración
- [x] ~~**B-09** CORS con IP hardcodeada~~ ✅ 2026-05-30 — desde CORS_ALLOWED_ORIGINS en .env
- [x] ~~**B-10** `ExtractMemoryJob` con `$tries = 1`~~ ✅ 2026-05-30 — tries=3, backoff=10s
- [x] ~~**B-11** `ProfileController` arrays sin límite~~ ✅ 2026-05-30 — max:20 items, max:100 chars

---

## 🟢 MEJORAS (backlog)

### Backend
- [ ] **B-12** Audit log cuando se cambia `is_admin` de un usuario
- [ ] **B-13** Respuestas de API inconsistentes — unificar formato `{success, data, error}`
- [ ] **B-14** `ConversationController::show` sin paginación de mensajes — 10k msgs = crash

### Web
- [ ] **W-06** Skeleton loaders en admin en lugar de texto "Cargando..."
- [ ] **W-07** Providers page expone API key en historial del formulario
- [ ] **W-08** Settings page demasiado larga — dividir en tabs o sub-páginas

### Mobile
- [ ] **M-07** FlatList en chat sin `maxToRenderPerBatch` — lento en conversaciones largas
- [ ] **M-08** Imágenes en mensajes sin aspect ratio fijo — layout thrashing
- [ ] **M-09** `UpdateModule` no valida integridad del APK descargado (CRC/hash)
- [ ] **M-10** `AriaNotificationListener` no filtra notificaciones de la propia app

---

## ✅ COMPLETADOS

### 2026-05-30
- **M-01** Token cifrado con EncryptedSharedPreferences AES-256 GCM (Android Keystore)
- **B-05/06** AdminController::users eager loading + paginate(50)
- **B-07** Dashboard en caché 5min, gráficas 1min
- **B-08** EmbeddingService::embed caché 24h SHA-256
- **B-09** CORS desde CORS_ALLOWED_ORIGINS en .env
- **B-10** ExtractMemoryJob tries=3 backoff=10s
- **B-11** ProfileController arrays max:20 strings max:100
- **W-03** Banner ámbar cuando Reverb se desconecta
- **W-04** Error banner en admin pages (antes solo console.error)
- **W-05** NeuralBrainGraph responsive, tablas con overflow-x
- **M-05** N/A — comportamiento del onboarding ya era correcto
- **M-06** Delay TTS→STT aumentado a 2000ms
- **B-01** POST /api/app/version movido a grupo admin (is_admin middleware)
- **B-02** Rate limiting: throttle 10/min en auth, 60/min en messages
- **B-03** Telegram webhook valida `X-Telegram-Bot-API-Secret-Token`
- **B-04** parent_id en memorias verifica ownership del usuario (IDOR fix)
- **M-02** Permisos ACCESS_COARSE/FINE_LOCATION en AndroidManifest
- **M-03** restartVosk() en catch de callApi — evita estado THINKING congelado
- **M-04** Null-check en getLaunchIntentForPackage — evita NPE en play_music
- **W-01** Tabla usuarios en /admin/memory carga datos reales de la API
- **W-02** react-markdown + remark-gfm — mensajes del asistente renderizan markdown

---

## Notas

- **Producción**: `https://ai.omnirepair.online` · servidor `root@134.122.21.84`
- **Deploy**: `ssh root@134.122.21.84` → `deploy backend` / `deploy web`
- **APK**: subir con `scp` + `curl -X POST /api/app/version`
- **Última auditoría**: 2026-05-30
