<?php

namespace Tests\Unit;

use App\Services\AI\AIRouter;
use PHPUnit\Framework\TestCase;

/**
 * Tests de la heurística de clasificación de tareas usada para el enrutamiento
 * inteligente de proveedores. Pura (sin DB ni red).
 */
class TaskClassifierTest extends TestCase
{
    private AIRouter $router;

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = new AIRouter();
    }

    public function test_code_blocks_are_classified_as_code(): void
    {
        $content = "Arregla esto:\n```php\necho 'hola';\n```";
        $this->assertSame('code', $this->router->classifyTask($content));
    }

    public function test_programming_keywords_are_classified_as_code(): void
    {
        $this->assertSame('code', $this->router->classifyTask('¿Cómo creo una función en Python?'));
        $this->assertSame('code', $this->router->classifyTask('Tengo un bug en mi controlador de Laravel'));
        $this->assertSame('code', $this->router->classifyTask('Escríbeme una consulta SQL para usuarios'));
    }

    public function test_analysis_keywords_are_classified_as_analysis(): void
    {
        $this->assertSame('analysis', $this->router->classifyTask('Analiza las ventajas de este enfoque'));
        $this->assertSame('analysis', $this->router->classifyTask('Compara estas dos estrategias por favor'));
        $this->assertSame('analysis', $this->router->classifyTask('Resume el documento adjunto'));
    }

    public function test_long_content_is_classified_as_analysis(): void
    {
        $content = str_repeat('palabra ', 100); // > 600 chars, sin keywords
        $this->assertSame('analysis', $this->router->classifyTask($content));
    }

    public function test_short_messages_are_classified_as_chat(): void
    {
        $this->assertSame('chat', $this->router->classifyTask('Hola Aria'));
        $this->assertSame('chat', $this->router->classifyTask('¿Qué hora es?'));
        $this->assertSame('chat', $this->router->classifyTask('Gracias'));
    }

    public function test_medium_neutral_content_is_general(): void
    {
        // Entre 80 y 600 chars, sin señales de código ni análisis
        $content = 'Cuéntame algo interesante sobre el espacio y los planetas del sistema solar que conocemos hoy en día por la ciencia.';
        $this->assertSame('general', $this->router->classifyTask($content));
    }

    public function test_code_takes_priority_over_length(): void
    {
        // Mensaje corto pero con señal de código gana 'code' sobre 'chat'
        $this->assertSame('code', $this->router->classifyTask('error en regex'));
    }
}
