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
- [ ] **B-05** N+1 queries en `AdminController::users` (1 query por usuario)
- [ ] **B-06** `AdminController::users` sin paginación — crashea con 10k usuarios
- [ ] **B-07** Sin caché en dashboard — recalcula 10+ queries por cada visita
- [ ] **B-08** `EmbeddingService::embed` sin caché — mismo texto = múltiples llamadas a Gemini

### UX — Web
- [ ] **W-03** Sin feedback cuando Reverb se desconecta — streaming para silenciosamente
- [ ] **W-04** Errores de API con `catch(console.error)` — no se muestran al usuario
- [ ] **W-05** Admin panel no es responsive — gráficos con ancho fijo, no funcionan en mobile

### UX — Mobile
- [ ] **M-05** Onboarding se marca completado antes de terminar — si cierras a mitad no vuelve
- [ ] **M-06** Race condition TTS/STT — en dispositivos lentos STT arranca antes de que TTS termine

### Configuración
- [ ] **B-09** CORS tiene IP hardcodeada `134.122.21.84` — debería venir de `.env`
- [ ] **B-10** `ExtractMemoryJob` con `$tries = 1` — si API falla se pierde la memoria
- [ ] **B-11** `ProfileController` acepta arrays sin límite — saturación potencial de BD

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
