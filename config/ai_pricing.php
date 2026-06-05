<?php

/*
|--------------------------------------------------------------------------
| Precios de modelos de IA (USD por 1,000,000 de tokens)
|--------------------------------------------------------------------------
|
| Usado por App\Services\AI\PricingService para estimar el costo de cada
| mensaje a partir de input_tokens / output_tokens. Los precios son
| aproximados y EDITABLES — ajústalos cuando cambien las tarifas.
|
| El match es: clave exacta → prefijo más largo que coincida → 'default'.
| Esto permite que variantes con fecha (ej. "claude-haiku-4-5-20251001")
| hereden el precio de su familia ("claude-haiku-4-5").
|
*/

return [

    'models' => [
        // Anthropic Claude
        'claude-opus-4'     => ['input' => 15.0, 'output' => 75.0],
        'claude-sonnet-4'   => ['input' => 3.0,  'output' => 15.0],
        'claude-haiku-4'    => ['input' => 1.0,  'output' => 5.0],

        // OpenAI
        'gpt-4o-mini'       => ['input' => 0.15, 'output' => 0.60],
        'gpt-4o'            => ['input' => 2.5,  'output' => 10.0],
        'gpt-4-turbo'       => ['input' => 10.0, 'output' => 30.0],
        'o1-mini'           => ['input' => 1.1,  'output' => 4.4],
        'o1'                => ['input' => 15.0, 'output' => 60.0],

        // DeepSeek
        'deepseek-chat'     => ['input' => 0.27, 'output' => 1.10],
        'deepseek-reasoner' => ['input' => 0.55, 'output' => 2.19],

        // Google Gemini
        'gemini-2.5-pro'    => ['input' => 1.25, 'output' => 10.0],
        'gemini-2.5-flash'  => ['input' => 0.30, 'output' => 2.50],
        'gemini-2.0-flash'  => ['input' => 0.10, 'output' => 0.40],
        'gemini-1.5-pro'    => ['input' => 1.25, 'output' => 5.0],

        // Mistral
        'mistral-large'     => ['input' => 2.0,  'output' => 6.0],
        'mistral-small'     => ['input' => 0.20, 'output' => 0.60],
    ],

    // Tarifa por defecto cuando el modelo no está en la tabla.
    'default' => ['input' => 1.0, 'output' => 3.0],

];
