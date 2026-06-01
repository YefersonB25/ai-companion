<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Tu licencia está activa — AI Companion</title>
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f4f4f8; color: #1a1a2e; }
    .wrapper { max-width: 600px; margin: 0 auto; padding: 24px 16px; }

    .header { background: linear-gradient(135deg, #059669 0%, #10b981 100%); border-radius: 16px 16px 0 0; padding: 36px 32px; text-align: center; }
    .header .icon { font-size: 48px; margin-bottom: 12px; }
    .header h1 { color: #fff; font-size: 24px; font-weight: 700; }
    .header p  { color: rgba(255,255,255,0.85); font-size: 15px; margin-top: 6px; }

    .body { background: #fff; padding: 32px; border-left: 1px solid #e5e7eb; border-right: 1px solid #e5e7eb; }
    .greeting { font-size: 16px; color: #374151; margin-bottom: 6px; }
    .intro { font-size: 14px; color: #6b7280; line-height: 1.6; margin-bottom: 24px; }

    /* License card */
    .license-card { background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%); border: 2px solid #6ee7b7; border-radius: 16px; padding: 24px; margin-bottom: 24px; }
    .license-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
    .license-title { font-size: 16px; font-weight: 700; color: #065f46; }
    .badge { background: #059669; color: #fff; font-size: 11px; font-weight: 700; padding: 4px 12px; border-radius: 20px; }

    .key-box { background: #fff; border: 1.5px solid #6ee7b7; border-radius: 10px; padding: 14px 18px; margin-bottom: 16px; text-align: center; }
    .key-label { font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px; }
    .key-value { font-family: 'Courier New', monospace; font-size: 22px; font-weight: 800; color: #059669; letter-spacing: 4px; }

    .details { display: flex; gap: 12px; flex-wrap: wrap; }
    .detail-cell { flex: 1; min-width: 120px; }
    .detail-label { font-size: 10px; font-weight: 600; color: #9ca3af; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 3px; }
    .detail-value { font-size: 14px; font-weight: 600; color: #065f46; }
    .detail-days { color: #059669; font-size: 16px; font-weight: 700; }

    .divider { border: none; border-top: 1px solid #e5e7eb; margin: 24px 0; }

    /* CTA */
    .cta-section { text-align: center; margin: 24px 0; }
    .cta-title { font-size: 15px; font-weight: 600; color: #374151; margin-bottom: 12px; }
    .btn { display: inline-block; background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%); color: #fff; text-decoration: none; padding: 14px 32px; border-radius: 12px; font-size: 15px; font-weight: 700; }

    /* Info steps */
    .steps { background: #f9fafb; border-radius: 12px; padding: 18px 20px; }
    .steps-title { font-size: 13px; font-weight: 700; color: #374151; margin-bottom: 10px; }
    .step { display: flex; align-items: flex-start; gap: 10px; margin-bottom: 8px; font-size: 13px; color: #6b7280; line-height: 1.5; }
    .step:last-child { margin-bottom: 0; }
    .step-num { background: #6366f1; color: #fff; font-size: 10px; font-weight: 700; width: 20px; height: 20px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; margin-top: 1px; }

    .footer { background: #f9fafb; border: 1px solid #e5e7eb; border-top: none; border-radius: 0 0 16px 16px; padding: 20px 32px; text-align: center; }
    .footer p { font-size: 12px; color: #9ca3af; line-height: 1.6; }
    .footer a { color: #6366f1; text-decoration: none; }
  </style>
</head>
<body>
  <div class="wrapper">

    <div class="header">
      <div class="icon">🎉</div>
      <h1>¡Tu licencia está activa!</h1>
      <p>Ya puedes usar AI Companion sin restricciones</p>
    </div>

    <div class="body">
      <p class="greeting">Hola, <strong>{{ $request->name }}</strong> 👋</p>
      <p class="intro">
        Tu solicitud fue aprobada y tu licencia de <strong>AI Companion Plan {{ $typeLabel }}</strong>
        ya está activa. Guarda los detalles de tu licencia en un lugar seguro.
      </p>

      <!-- License card -->
      <div class="license-card">
        <div class="license-header">
          <span class="license-title">Plan {{ $typeLabel }}</span>
          <span class="badge">✓ ACTIVA</span>
        </div>

        <div class="key-box">
          <p class="key-label">Clave de licencia</p>
          <p class="key-value">{{ $license->key }}</p>
        </div>

        <div class="details">
          <div class="detail-cell">
            <p class="detail-label">Activación</p>
            <p class="detail-value">{{ $startsAt }}</p>
          </div>
          <div class="detail-cell">
            <p class="detail-label">Vencimiento</p>
            <p class="detail-value">{{ $expiresAt }}</p>
          </div>
          <div class="detail-cell">
            <p class="detail-label">Días restantes</p>
            <p class="detail-value detail-days">{{ $daysRemaining }} días</p>
          </div>
        </div>
      </div>

      <!-- CTA -->
      <div class="cta-section">
        <p class="cta-title">¡Empieza a usar tu asistente personal ahora!</p>
        <a href="{{ $appUrl }}" class="btn">Abrir AI Companion →</a>
      </div>

      <hr class="divider" />

      <!-- Steps -->
      <div class="steps">
        <p class="steps-title">¿Qué sigue?</p>
        <div class="step">
          <div class="step-num">1</div>
          <span>Inicia sesión en <strong>AI Companion</strong> con tu correo y contraseña.</span>
        </div>
        <div class="step">
          <div class="step-num">2</div>
          <span>Tu acceso ya está habilitado automáticamente — no necesitas ingresar la clave.</span>
        </div>
        <div class="step">
          <div class="step-num">3</div>
          <span>Configura tu proveedor de IA favorito en <strong>Proveedores IA</strong> y comienza a chatear.</span>
        </div>
        <div class="step">
          <div class="step-num">4</div>
          <span>Guarda tu clave <strong>{{ $license->key }}</strong> — la necesitarás si cambias de dispositivo.</span>
        </div>
      </div>
    </div>

    <div class="footer">
      <p>
        Recibirás un aviso antes del vencimiento de tu licencia el <strong>{{ $expiresAt }}</strong>.<br />
        &copy; {{ date('Y') }} AI Companion · <a href="{{ $appUrl }}">{{ $appUrl }}</a>
      </p>
    </div>

  </div>
</body>
</html>
