# Sistema de Versionado de App - Backend (Laravel)

## 📋 Descripción General

El sistema de versionado permite publicar y gestionar versiones de la app mobile (Android/iOS) con soporte para:
- Actualizaciones obligatorias vs opcionales
- Changelog personalizado
- URLs de descarga
- Control por plataforma

---

## 🏗️ Componentes

### Tabla Base de Datos: `app_versions`

```sql
CREATE TABLE app_versions (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    platform VARCHAR(20),              -- 'android' o 'ios'
    version VARCHAR(20),               -- Semver: '1.0.62'
    version_code INT,                  -- Entero incremental: 62
    changelog JSON,                    -- Array JSON de strings
    download_url VARCHAR(500) NULLABLE,-- URL al APK/IPA
    is_required BOOLEAN DEFAULT false, -- Actualización obligatoria
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    UNIQUE(platform, version),
    INDEX(platform, version_code)
);
```

### Modelo: `app/Models/AppVersion.php`

```php
class AppVersion extends Model {
    protected $fillable = ['platform', 'version', 'version_code', 'changelog', 'download_url', 'is_required'];
}
```

### Controlador: `app/Http/Controllers/Api/AppVersionController.php`

#### Endpoint GET `/api/app/version`

**Público** - Chequea actualizaciones disponibles

```http
GET /api/app/version?platform=android&version_code=61

Respuesta:
{
  "update_available": true,
  "version": "1.0.62",
  "version_code": 62,
  "changelog": ["FIXES: NotificationListenerService", ...],
  "download_url": "http://ai.omnirepair.online/downloads/ai-companion-v1.0.62.apk",
  "is_required": false
}
```

**Lógica**:
1. Obtiene última versión para la plataforma
2. Compara `version_code` del cliente vs servidor
3. Si servidor > cliente, `update_available = true`
4. Retorna metadata completa (changelog, URL, obligatoria)

#### Endpoint POST `/admin/app/version`

**Protegido** - Solo admin - Publica nueva versión

```http
POST /admin/app/version
Authorization: Bearer ADMIN_TOKEN
Content-Type: application/json

{
  "platform": "android",
  "version": "1.0.62",
  "version_code": 62,
  "changelog": ["FIXES: NotificationListenerService", "Fix: WakeWord initialization"],
  "download_url": "http://ai.omnirepair.online/downloads/ai-companion-v1.0.62.apk",
  "is_required": false
}

Respuesta (201):
{
  "id": 42,
  "platform": "android",
  "version": "1.0.62",
  ...
}
```

---

## 🎯 Comando Artisan: `app:release`

### Uso

```bash
php artisan app:release 1.0.62 \
  --platform=android \
  --url="http://ai.omnirepair.online/downloads/ai-companion-v1.0.62.apk" \
  --required
```

### Opciones

```
{version}              Versión semver (e.g., 1.0.62)
--platform=android     Plataforma: 'android' o 'ios'
--url=URL              URL de descarga del APK/IPA
--required             Marcar como actualización obligatoria
```

### Flujo Interactivo

```
$ php artisan app:release 1.0.62 --platform=android

Publishing android v1.0.62

versionCode (Android integer) [62]:
> 62

Enter changelog items one by one. Empty line to finish:
  - FIXES v1.0.62: NotificationListenerService
  - Fix: WakeWord initialization
  - 

Is this a required update? (yes/no) [no]:
> no

✓ Version 1.0.62 published for android.
┌──────────────┬───────────────────────────────────────┐
│ Field        │ Value                                 │
├──────────────┼───────────────────────────────────────┤
│ version      │ 1.0.62                                │
│ version_code │ 62                                    │
│ required     │ No                                    │
│ download_url │ http://...downloads/ai-companion-... │
│ changelog    │ FIXES v1.0.62: NotificationListener..│
└──────────────┴───────────────────────────────────────┘
```

---

## 🚀 Workflow Completo

### 1. App Mobile Publica Nueva Versión

```bash
cd /ai-companion-mobile
npm run build:prod          # Genera APK

# APK se encuentra en ./dist/ai-companion-v1.0.62.apk
```

### 2. Subir APK a Servidor

```bash
scp dist/ai-companion-v1.0.62.apk \
  root@ai.omnirepair.online:/var/www/ai-companion/public/downloads/
```

### 3. Publicar en Base de Datos

**Opción A: Interactivo** (recomendado)

```bash
ssh root@ai.omnirepair.online
cd /var/www/ai-companion

php artisan app:release 1.0.62 \
  --platform=android \
  --url="http://ai.omnirepair.online/downloads/ai-companion-v1.0.62.apk"

# Responder preguntas del comando
```

**Opción B: HTTP Request** (CI/CD)

```bash
curl -X POST "https://ai.omnirepair.online/admin/app/version" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "platform": "android",
    "version": "1.0.62",
    "version_code": 62,
    "changelog": ["FIXES: NotificationListenerService"],
    "download_url": "http://ai.omnirepair.online/downloads/ai-companion-v1.0.62.apk",
    "is_required": false
  }'
```

### 4. Hacer Deploy (para cambios de código)

```bash
cd /ai-companion
git add app.json package.json
git commit -m "Bump version to v1.0.62"
git push origin main

ssh root@ai.omnirepair.online "bash /var/www/ai-companion/deploy.sh"
```

---

## 🔍 Verificación

### Verificar Última Versión Publicada

```bash
# Via API
curl "https://ai.omnirepair.online/api/app/version?platform=android&version_code=61"

# Via DB
ssh root@ai.omnirepair.online
cd /var/www/ai-companion
php artisan tinker

>>> AppVersion::where('platform', 'android')->latest('version_code')->first()
=> App\Models\AppVersion {
     platform: "android",
     version: "1.0.62",
     version_code: 62,
     changelog: ["FIXES v1.0.62..."],
     download_url: "http://...",
     is_required: false,
   }
```

### Verificar APK en Servidor

```bash
ssh root@ai.omnirepair.online
ls -lh /var/www/ai-companion/public/downloads/ai-companion-v*.apk

# Debe existir:
# -rw-r--r-- 1 root root 109M Jun 12 12:34 ai-companion-v1.0.62.apk
```

### Descargar APK de Verificación

```bash
curl -I "http://ai.omnirepair.online/downloads/ai-companion-v1.0.62.apk"
# Debe retornar 200 OK con Content-Length
```

---

## 🛠️ Troubleshooting

### Error: "File size exceeds GitHub limit"

**Problema**: Archivos APK en Git

**Solución**:
```bash
# Remover APK del control de versión
git rm --cached public/downloads/*.apk
echo 'public/downloads/*.apk' >> .gitignore
git commit -m "Remove APK files from git"
```

### Versión no se actualiza en BD

**Problema**: Cache de aplicación

**Solución**:
```bash
php artisan cache:clear
php artisan config:cache
```

### Mobile no detecta actualización

**Problema**: Endpoint retorna versión incorrecta

**Solución**:
```bash
# Verificar BD
php artisan tinker
>>> DB::table('app_versions')->where('platform', 'android')->get()
>>> DB::table('app_versions')->truncate()  # Resetear si es necesario
```

### APK no descarga en Mobile

**Problema**: URL incorrecta o recurso no existe

**Solución**:
1. Verificar URL en BD: `download_url`
2. Verificar archivo existe: `ssh ... ls /var/www/.../public/downloads/`
3. Verificar permisos: `chmod 644 public/downloads/*.apk`

---

## 📊 Queries Útiles

```sql
-- Ver última versión de cada plataforma
SELECT platform, version, version_code, is_required, updated_at
FROM app_versions
WHERE (platform, version_code) IN (
    SELECT platform, MAX(version_code) FROM app_versions GROUP BY platform
);

-- Ver todas las versiones de Android ordenadas
SELECT version, version_code, is_required, changelog, updated_at
FROM app_versions
WHERE platform = 'android'
ORDER BY version_code DESC;

-- Marcar versión como obligatoria
UPDATE app_versions
SET is_required = true
WHERE platform = 'android' AND version = '1.0.62';

-- Actualizar URL de descarga
UPDATE app_versions
SET download_url = 'http://new-url/ai-companion-v1.0.62.apk'
WHERE platform = 'android' AND version = '1.0.62';

-- Eliminar versión (si fue un error)
DELETE FROM app_versions
WHERE platform = 'android' AND version = '1.0.62';
```

---

## 🔐 Autenticación y Autorización

### Endpoint Público (GET)

```
GET /api/app/version
- No requiere autenticación
- Rate limit: ninguno especial
- Visible a todos (incluyendo versiones antiguas de la app)
```

### Endpoint Admin (POST)

```
POST /admin/app/version
- Requiere: Authorization: Bearer {ADMIN_TOKEN}
- Middleware: auth:sanctum, is_admin
- Solo usuarios con rol 'admin' pueden publicar versiones
```

### Verificar Token Admin

```bash
php artisan tinker
>>> $user = User::find(1)
>>> $user->roles()->attach('admin')  # Hacer admin
>>> $token = $user->createToken('admin-token')->plainTextToken
```

---

## 📱 Respuesta API Detallada

### GET /api/app/version - Cuando hay actualización

```json
{
  "update_available": true,
  "version": "1.0.62",
  "version_code": 62,
  "changelog": [
    "FIXES v1.0.62: NotificationListenerService",
    "Fix: WakeWord initialization"
  ],
  "download_url": "http://ai.omnirepair.online/downloads/ai-companion-v1.0.62.apk",
  "is_required": false
}
```

### GET /api/app/version - Cuando NO hay actualización

```json
{
  "update_available": false,
  "version": "1.0.61",
  "version_code": 61,
  "changelog": ["Previous changelog..."],
  "download_url": "http://...",
  "is_required": false
}
```

### GET /api/app/version - Sin versiones publicadas

```json
{
  "update_available": false
}
```

---

## 📋 Checklist para Publicar Nueva Versión

```
□ 1. Compilar APK en mobile: npm run build:prod
□ 2. Copiar APK a servidor: scp ... public/downloads/
□ 3. Verificar permisos del archivo: chmod 644
□ 4. Publicar en BD: php artisan app:release X.X.XX
□ 5. Verificar endpoint: curl api/app/version
□ 6. Probar en mobile antigua: debería mostrar modal
□ 7. Descargar e instalar: verificar que funciona
□ 8. Confirmar app nuevo funciona correctamente
```

---

## 🚨 Casos Especiales

### Actualización Obligatoria

```bash
php artisan app:release 1.0.62 --platform=android --required

# Mobile no mostrará opción "Recordarme después"
# Usuario DEBE actualizar para continuar usando la app
```

### Multiple Plataformas (Android + iOS)

```bash
# Android
php artisan app:release 1.0.62 --platform=android

# iOS (si disponible)
php artisan app:release 1.0.62 --platform=ios \
  --url="http://ai.omnirepair.online/downloads/ai-companion-v1.0.62.ipa"
```

### Rollback de Versión

```bash
# Si hay un bug crítico, volver a versión anterior:
php artisan tinker
>>> AppVersion::where('platform', 'android')->latest('version_code')->first()->delete()

# O simplemente dejar como no obligatoria y publicar versión arreglada
```

---

**Última actualización**: 2026-06-12
**Versión ejemplo**: 1.0.62
