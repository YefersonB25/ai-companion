<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Catálogo de Licencias — AI Companion</title>
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f4f4f8; color: #1a1a2e; }
    .wrapper { max-width: 640px; margin: 0 auto; padding: 24px 16px; }

    /* Header */
    .header { background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%); border-radius: 16px 16px 0 0; padding: 36px 32px; text-align: center; }
    .header .logo { display: inline-flex; align-items: center; gap: 10px; margin-bottom: 20px; }
    .header .logo-icon { width: 44px; height: 44px; background: rgba(255,255,255,0.2); border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 22px; }
    .header h1 { color: #fff; font-size: 26px; font-weight: 700; line-height: 1.3; }
    .header p { color: rgba(255,255,255,0.85); font-size: 15px; margin-top: 8px; }

    /* Body */
    .body { background: #fff; padding: 32px; border-left: 1px solid #e5e7eb; border-right: 1px solid #e5e7eb; }
    .greeting { font-size: 16px; color: #374151; margin-bottom: 8px; }
    .intro { font-size: 14px; color: #6b7280; line-height: 1.6; margin-bottom: 28px; }

    /* Plans */
    .plans { display: flex; gap: 16px; margin-bottom: 32px; flex-wrap: wrap; }
    .plan { flex: 1; min-width: 220px; border: 2px solid #e5e7eb; border-radius: 12px; padding: 24px; position: relative; overflow: hidden; }
    .plan.popular { border-color: #6366f1; }
    .badge-popular { position: absolute; top: 12px; right: -20px; background: #6366f1; color: #fff; font-size: 10px; font-weight: 700; padding: 3px 28px; transform: rotate(35deg); letter-spacing: 0.5px; }

    .plan-label { font-size: 11px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; color: #9ca3af; margin-bottom: 8px; }
    .plan-name { font-size: 20px; font-weight: 700; color: #111827; margin-bottom: 6px; }
    .plan-price { font-size: 32px; font-weight: 800; color: #6366f1; line-height: 1; }
    .plan-price span { font-size: 14px; font-weight: 500; color: #9ca3af; }
    .plan-billing { font-size: 13px; color: #6b7280; margin-top: 4px; margin-bottom: 16px; }
    .savings-tag { display: inline-block; background: #d1fae5; color: #065f46; font-size: 11px; font-weight: 700; padding: 3px 10px; border-radius: 20px; margin-bottom: 16px; }

    /* Features list */
    .features { list-style: none; margin-bottom: 20px; }
    .features li { font-size: 13px; color: #374151; padding: 5px 0; display: flex; align-items: flex-start; gap: 8px; }
    .features li::before { content: '✓'; color: #10b981; font-weight: 700; flex-shrink: 0; margin-top: 1px; }

    /* CTA Button */
    .btn { display: block; text-align: center; padding: 13px 20px; border-radius: 10px; font-size: 14px; font-weight: 700; text-decoration: none; color: #fff; margin-top: 4px; }
    .btn-monthly { background: #6366f1; }
    .btn-yearly  { background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%); }

    /* Divider */
    .divider { border: none; border-top: 1px solid #e5e7eb; margin: 28px 0; }

    /* Info box */
    .info-box { background: #f9fafb; border-radius: 10px; padding: 18px 20px; font-size: 13px; color: #6b7280; line-height: 1.7; }
    .info-box strong { color: #374151; }

    /* Footer */
    .footer { background: #f9fafb; border: 1px solid #e5e7eb; border-top: none; border-radius: 0 0 16px 16px; padding: 20px 32px; text-align: center; }
    .footer p { font-size: 12px; color: #9ca3af; line-height: 1.6; }
    .footer a { color: #6366f1; text-decoration: none; }
  </style>
</head>
<body>
  <div class="wrapper">

    <!-- Header -->
    <div class="header">
      <div class="logo">
        <div class="logo-icon">✨</div>
      </div>
      <h1>Elige tu plan de AI Companion</h1>
      <p>Tu asistente personal de inteligencia artificial</p>
    </div>

    <!-- Body -->
    <div class="body">
      <p class="greeting">Hola, <strong>{{ $request->name }}</strong> 👋</p>
      <p class="intro">
        Gracias por tu interés en AI Companion. A continuación encontrarás nuestros planes de licencia
        con precios en pesos colombianos. Elige el que mejor se adapte a tus necesidades y haz clic en
        el botón para solicitar tu licencia por WhatsApp.
      </p>

      <!-- Plans -->
      <div class="plans">

        <!-- Plan Mensual -->
        <div class="plan">
          <p class="plan-label">Plan</p>
          <p class="plan-name">Mensual</p>
          <p class="plan-price">
            ${{ $priceMonthly }}
            <span>COP</span>
          </p>
          <p class="plan-billing">por mes · facturación mensual</p>

          <ul class="features">
            @foreach($features as $feature)
            <li>{{ $feature }}</li>
            @endforeach
          </ul>

          <a href="{{ config('app.url') }}/api/license/whatsapp/{{ $request->id }}/monthly"
             class="btn btn-monthly"
             target="_blank">
            📲 Adquirir Plan Mensual
          </a>
        </div>

        <!-- Plan Anual -->
        <div class="plan popular">
          <div class="badge-popular">POPULAR</div>
          <p class="plan-label">Plan</p>
          <p class="plan-name">Anual</p>
          <p class="plan-price">
            ${{ $priceYearly }}
            <span>COP</span>
          </p>
          <p class="plan-billing">por año · facturación anual</p>
          <span class="savings-tag">🎉 Ahorras ${{ $yearlySavings }} COP</span>

          <ul class="features">
            @foreach($features as $feature)
            <li>{{ $feature }}</li>
            @endforeach
          </ul>

          <a href="{{ config('app.url') }}/api/license/whatsapp/{{ $request->id }}/yearly"
             class="btn btn-yearly"
             target="_blank">
            📲 Adquirir Plan Anual
          </a>
        </div>

      </div><!-- /plans -->

      <hr class="divider" />

      <div class="info-box">
        <strong>¿Cómo funciona?</strong><br />
        1. Haz clic en el botón del plan que deseas.<br />
        2. Se abrirá WhatsApp con un mensaje predefinido.<br />
        3. Envía el mensaje y nuestro equipo te contactará en menos de 24 horas.<br />
        4. Una vez confirmado el pago, tu licencia se activa de inmediato.<br /><br />
        <strong>¿Tienes preguntas?</strong> Responde a este correo o escríbenos directamente al WhatsApp.
      </div>
    </div>

    <!-- Footer -->
    <div class="footer">
      <p>
        Este correo fue enviado porque solicitaste información sobre licencias de AI Companion.<br />
        &copy; {{ date('Y') }} AI Companion · <a href="https://ai.omnirepair.online">ai.omnirepair.online</a>
      </p>
    </div>

  </div>
</body>
</html>
