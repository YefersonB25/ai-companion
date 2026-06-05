<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\MemoryNode;
use App\Models\Message;
use App\Models\User;
use App\Services\AI\AIRouter;
use App\Services\AI\PricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    // ─────────────────────────────────────────────
    // GET /api/admin/dashboard
    // ─────────────────────────────────────────────
    public function dashboard(): JsonResponse
    {
        $stats = cache()->remember('admin:dashboard:stats', 300, function () {
            $totalUsers = User::count();

            $activeToday = Message::where('role', 'user')
                ->whereDate('created_at', today())
                ->distinct('user_id')
                ->count('user_id');

            $activeWeek = Message::where('role', 'user')
                ->where('created_at', '>=', now()->subDays(7))
                ->distinct('user_id')
                ->count('user_id');

            $messagesToday = Message::where('role', 'user')
                ->whereDate('created_at', today())
                ->count();

            $messagesWeek = Message::where('role', 'user')
                ->where('created_at', '>=', now()->subDays(7))
                ->count();

            $totalMemoryNodes = MemoryNode::count();

            $memoryGrowthToday = MemoryNode::whereDate('created_at', today())->count();

            // Voice activations (messages with role=user that came from voice channel or contain voice metadata)
            $voiceActivationsWeek = Message::where('role', 'user')
                ->where('created_at', '>=', now()->subDays(7))
                ->whereHas('conversation', function ($q) {
                    $q->where('channel', 'voice');
                })
                ->count();

            $tokenStats = Message::where('role', 'assistant')
                ->selectRaw('SUM(input_tokens) as total_input, SUM(output_tokens) as total_output')
                ->first();

            return [
                'total_users'            => $totalUsers,
                'active_today'           => $activeToday,
                'active_week'            => $activeWeek,
                'messages_today'         => $messagesToday,
                'messages_week'          => $messagesWeek,
                'total_memory_nodes'     => $totalMemoryNodes,
                'memory_growth_today'    => $memoryGrowthToday,
                'voice_activations_week' => $voiceActivationsWeek,
                'total_input_tokens'     => (int) ($tokenStats->total_input ?? 0),
                'total_output_tokens'    => (int) ($tokenStats->total_output ?? 0),
            ];
        });

        // Messages by day — last 30 days (1 min cache)
        $messagesByDay = cache()->remember('admin:dashboard:messages_by_day', 60, function () {
            return Message::where('role', 'user')
                ->where('created_at', '>=', now()->subDays(30))
                ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
                ->groupBy('date')
                ->orderBy('date')
                ->get()
                ->map(fn ($row) => ['date' => $row->date, 'count' => (int) $row->count]);
        });

        // Memory by day with cumulative — last 30 days (1 min cache)
        $memoryByDay = cache()->remember('admin:dashboard:memory_by_day', 60, function () {
            $memoryStartDate = now()->subDays(30);
            $memoryBase = MemoryNode::where('created_at', '<', $memoryStartDate)->count();

            $memoryRaw = MemoryNode::where('created_at', '>=', $memoryStartDate)
                ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
                ->groupBy('date')
                ->orderBy('date')
                ->get();

            $cumulative = $memoryBase;
            return $memoryRaw->map(function ($row) use (&$cumulative) {
                $cumulative += (int) $row->count;
                return [
                    'date'       => $row->date,
                    'count'      => (int) $row->count,
                    'cumulative' => $cumulative,
                ];
            });
        });

        // Messages by provider (1 min cache)
        $messagesByProvider = cache()->remember('admin:dashboard:messages_by_provider', 60, function () {
            return Message::where('role', 'assistant')
                ->whereNotNull('provider')
                ->selectRaw('provider, COUNT(*) as count, SUM(input_tokens) as input_tokens, SUM(output_tokens) as output_tokens')
                ->groupBy('provider')
                ->get()
                ->map(fn ($row) => [
                    'provider'      => $row->provider,
                    'count'         => (int) $row->count,
                    'input_tokens'  => (int) $row->input_tokens,
                    'output_tokens' => (int) $row->output_tokens,
                ]);
        });

        return response()->json([
            'stats'                => $stats,
            'messages_by_day'      => $messagesByDay,
            'memory_by_day'        => $memoryByDay,
            'messages_by_provider' => $messagesByProvider,
        ]);
    }

    // ─────────────────────────────────────────────
    // GET /api/admin/usage — costo estimado por proveedor/modelo/usuario
    // ─────────────────────────────────────────────
    public function usage(PricingService $pricing): JsonResponse
    {
        $payload = cache()->remember('admin:usage', 60, function () use ($pricing) {
            // Agregado por proveedor + modelo (el precio depende del modelo)
            $rows = Message::where('role', 'assistant')
                ->whereNotNull('provider')
                ->selectRaw('provider, model, COUNT(*) as count, SUM(input_tokens) as input_tokens, SUM(output_tokens) as output_tokens')
                ->groupBy('provider', 'model')
                ->get();

            $byProvider  = [];
            $byModel     = [];
            $totalCost   = 0.0;
            $totalInput  = 0;
            $totalOutput = 0;

            foreach ($rows as $row) {
                $in   = (int) $row->input_tokens;
                $out  = (int) $row->output_tokens;
                $cost = $pricing->costFor($row->model, $in, $out);

                $totalCost   += $cost;
                $totalInput  += $in;
                $totalOutput += $out;

                $p = $row->provider;
                $byProvider[$p] ??= ['provider' => $p, 'count' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'cost_usd' => 0.0];
                $byProvider[$p]['count']         += (int) $row->count;
                $byProvider[$p]['input_tokens']  += $in;
                $byProvider[$p]['output_tokens'] += $out;
                $byProvider[$p]['cost_usd']       = round($byProvider[$p]['cost_usd'] + $cost, 4);

                $byModel[] = [
                    'provider'      => $p,
                    'model'         => $row->model,
                    'count'         => (int) $row->count,
                    'input_tokens'  => $in,
                    'output_tokens' => $out,
                    'cost_usd'      => round($cost, 4),
                ];
            }

            usort($byModel, fn ($a, $b) => $b['cost_usd'] <=> $a['cost_usd']);

            // Costo diario — últimos 30 días
            $dailyRows = Message::where('role', 'assistant')
                ->whereNotNull('provider')
                ->where('created_at', '>=', now()->subDays(30))
                ->selectRaw('DATE(created_at) as date, model, SUM(input_tokens) as input_tokens, SUM(output_tokens) as output_tokens')
                ->groupBy('date', 'model')
                ->get();

            $daily = [];
            foreach ($dailyRows as $row) {
                $cost = $pricing->costFor($row->model, (int) $row->input_tokens, (int) $row->output_tokens);
                $daily[$row->date] = round(($daily[$row->date] ?? 0) + $cost, 4);
            }
            ksort($daily);
            $costByDay = collect($daily)->map(fn ($c, $d) => ['date' => $d, 'cost_usd' => $c])->values()->all();

            // Top usuarios por costo
            $userRows = Message::where('role', 'assistant')
                ->whereNotNull('provider')
                ->selectRaw('user_id, model, SUM(input_tokens) as input_tokens, SUM(output_tokens) as output_tokens')
                ->groupBy('user_id', 'model')
                ->get();

            $userCost = [];
            foreach ($userRows as $row) {
                $cost = $pricing->costFor($row->model, (int) $row->input_tokens, (int) $row->output_tokens);
                $userCost[$row->user_id] = ($userCost[$row->user_id] ?? 0) + $cost;
            }
            arsort($userCost);
            $topIds = array_slice(array_keys($userCost), 0, 10);
            $names  = User::whereIn('id', $topIds)->pluck('name', 'id');
            $topUsers = array_map(fn ($uid) => [
                'user_id'  => $uid,
                'name'     => $names[$uid] ?? '—',
                'cost_usd' => round($userCost[$uid], 4),
            ], $topIds);

            return [
                'totals' => [
                    'cost_usd'      => round($totalCost, 4),
                    'input_tokens'  => $totalInput,
                    'output_tokens' => $totalOutput,
                ],
                'by_provider' => array_values($byProvider),
                'by_model'    => $byModel,
                'cost_by_day' => $costByDay,
                'top_users'   => $topUsers,
            ];
        });

        return response()->json($payload);
    }

    // ─────────────────────────────────────────────
    // GET /api/admin/users
    // ─────────────────────────────────────────────
    public function users(): JsonResponse
    {
        $users = User::withCount(['messages', 'conversations', 'memoryNodes'])
            ->with([
                'messages'    => fn ($q) => $q->where('role', 'user')->latest()->limit(1)->select('user_id', 'created_at'),
                'memoryNodes' => fn ($q) => $q->select('user_id', 'type', 'created_at'),
            ])
            ->orderByDesc('created_at')
            ->paginate(50);

        $items = $users->getCollection()->map(function (User $user) {
            $brainScore = $this->computeBrainScore($user);

            return [
                'id'                  => $user->id,
                'name'                => $user->name,
                'email'               => $user->email,
                'is_admin'            => $user->is_admin,
                'created_at'          => $user->created_at,
                'messages_count'      => $user->messages_count,
                'conversations_count' => $user->conversations_count,
                'memory_nodes_count'  => $user->memory_nodes_count,
                'last_active_at'      => $user->messages->first()?->created_at,
                'brain_score'         => $brainScore,
            ];
        });

        return response()->json($items);
    }

    // ─────────────────────────────────────────────
    // GET /api/admin/users/{user}
    // ─────────────────────────────────────────────
    public function userDetail(User $user): JsonResponse
    {
        $user->loadCount(['messages', 'conversations', 'memoryNodes']);
        $user->load(['memoryNodes' => function ($q) {
            $q->select('id', 'user_id', 'type', 'label', 'importance', 'access_count', 'created_at');
        }]);

        $lastActiveAt = Message::where('user_id', $user->id)
            ->where('role', 'user')
            ->latest()
            ->value('created_at');

        // avg messages per day since account creation
        $daysSinceCreation = max(1, (int) $user->created_at->diffInDays(now()) + 1);
        $avgMessagesPerDay = round($user->messages_count / $daysSinceCreation, 2);

        $brainScore = $this->computeBrainScore($user);

        // Memory by type
        $byType = $user->memoryNodes->groupBy('type')->map->count();

        // Memory growth with cumulative — last 30 days
        $memoryStartDate = now()->subDays(30);
        $memoryBase = MemoryNode::where('user_id', $user->id)
            ->where('created_at', '<', $memoryStartDate)
            ->count();

        $memoryGrowthRaw = MemoryNode::where('user_id', $user->id)
            ->where('created_at', '>=', $memoryStartDate)
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $cumulative = $memoryBase;
        $memoryGrowthByDay = $memoryGrowthRaw->map(function ($row) use (&$cumulative) {
            $cumulative += (int) $row->count;
            return [
                'date'       => $row->date,
                'count'      => (int) $row->count,
                'cumulative' => $cumulative,
            ];
        });

        // Recent memory nodes
        $recentNodes = $user->memoryNodes
            ->sortByDesc('created_at')
            ->take(10)
            ->values()
            ->map(fn ($n) => [
                'id'         => $n->id,
                'type'       => $n->type,
                'label'      => $n->label,
                'importance' => $n->importance,
                'created_at' => $n->created_at,
            ]);

        // Most accessed
        $mostAccessed = $user->memoryNodes
            ->sortByDesc('access_count')
            ->take(10)
            ->values()
            ->map(fn ($n) => [
                'id'           => $n->id,
                'label'        => $n->label,
                'access_count' => $n->access_count,
            ]);

        // Messages by day — last 30 days
        $messagesByDay = Message::where('user_id', $user->id)
            ->where('role', 'user')
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn ($row) => ['date' => $row->date, 'count' => (int) $row->count]);

        return response()->json([
            'user' => [
                'id'         => $user->id,
                'name'       => $user->name,
                'email'      => $user->email,
                'is_admin'   => $user->is_admin,
                'created_at' => $user->created_at,
            ],
            'stats' => [
                'messages_count'      => $user->messages_count,
                'conversations_count' => $user->conversations_count,
                'memory_nodes_count'  => $user->memory_nodes_count,
                'brain_score'         => $brainScore,
                'avg_messages_per_day' => $avgMessagesPerDay,
                'last_active_at'      => $lastActiveAt,
            ],
            'memory' => [
                'by_type'       => $byType,
                'growth_by_day' => $memoryGrowthByDay,
                'recent'        => $recentNodes,
                'most_accessed' => $mostAccessed,
            ],
            'messages_by_day' => $messagesByDay,
        ]);
    }

    // ─────────────────────────────────────────────
    // GET /api/admin/memory
    // ─────────────────────────────────────────────
    public function globalMemory(): JsonResponse
    {
        $totalNodes = MemoryNode::count();

        $totalUsersWithMemory = MemoryNode::distinct('user_id')->count('user_id');

        $avgNodesPerUser = $totalUsersWithMemory > 0
            ? round($totalNodes / $totalUsersWithMemory, 2)
            : 0;

        // Growth by day — last 30 days with cumulative
        $memoryStartDate = now()->subDays(30);
        $memoryBase = MemoryNode::where('created_at', '<', $memoryStartDate)->count();

        $growthRaw = MemoryNode::where('created_at', '>=', $memoryStartDate)
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $cumulative = $memoryBase;
        $growthByDay = $growthRaw->map(function ($row) use (&$cumulative) {
            $cumulative += (int) $row->count;
            return [
                'date'       => $row->date,
                'count'      => (int) $row->count,
                'cumulative' => $cumulative,
            ];
        });

        // By type
        $byType = MemoryNode::selectRaw('type, COUNT(*) as count')
            ->groupBy('type')
            ->pluck('count', 'type')
            ->map(fn ($v) => (int) $v);

        // Top labels (most common)
        $topLabels = MemoryNode::selectRaw('label, COUNT(*) as count')
            ->groupBy('label')
            ->orderByDesc('count')
            ->limit(20)
            ->get()
            ->map(fn ($row) => ['label' => $row->label, 'count' => (int) $row->count]);

        // Growth rates
        $weekAgo = MemoryNode::where('created_at', '>=', now()->subDays(7))->count();
        $monthAgo = MemoryNode::where('created_at', '>=', now()->subDays(30))->count();

        return response()->json([
            'total_nodes'             => $totalNodes,
            'total_users_with_memory' => $totalUsersWithMemory,
            'avg_nodes_per_user'      => $avgNodesPerUser,
            'growth_by_day'           => $growthByDay,
            'by_type'                 => $byType,
            'top_labels'              => $topLabels,
            'growth_rate_week'        => $weekAgo,
            'growth_rate_month'       => $monthAgo,
        ]);
    }

    // ─────────────────────────────────────────────
    // GET /api/admin/insights
    // ─────────────────────────────────────────────
    public function insights(Request $request): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        // Gather quick metrics
        $totalUsers     = User::count();
        $activeToday    = Message::where('role', 'user')->whereDate('created_at', today())->distinct('user_id')->count('user_id');
        $activeWeek     = Message::where('role', 'user')->where('created_at', '>=', now()->subDays(7))->distinct('user_id')->count('user_id');
        $messagesToday  = Message::where('role', 'user')->whereDate('created_at', today())->count();
        $messagesWeek   = Message::where('role', 'user')->where('created_at', '>=', now()->subDays(7))->count();
        $totalMemory    = MemoryNode::count();
        $memoryThisWeek = MemoryNode::where('created_at', '>=', now()->subDays(7))->count();

        $providerStats = Message::where('role', 'assistant')
            ->whereNotNull('provider')
            ->selectRaw('provider, COUNT(*) as count')
            ->groupBy('provider')
            ->pluck('count', 'provider');

        $providerSummary = $providerStats->map(fn ($c, $p) => "{$p}: {$c} messages")->implode(', ');

        $prompt = <<<PROMPT
        Eres un analista de producto experto en aplicaciones de asistente personal con IA.
        Analiza las siguientes métricas de AI Companion y proporciona observaciones clave y sugerencias accionables en español.
        Sé conciso pero profundo. Máximo 300 palabras.

        Métricas del sistema (fecha: {$this->today()}):
        - Usuarios totales: {$totalUsers}
        - Usuarios activos hoy: {$activeToday}
        - Usuarios activos esta semana: {$activeWeek}
        - Mensajes enviados hoy: {$messagesToday}
        - Mensajes enviados esta semana: {$messagesWeek}
        - Nodos de memoria total: {$totalMemory}
        - Nodos de memoria creados esta semana: {$memoryThisWeek}
        - Distribución por proveedor IA: {$providerSummary}

        Proporciona:
        1. Salud general del sistema (1-2 frases)
        2. Patrones de uso destacados
        3. Alertas o preocupaciones (si las hay)
        4. 2-3 sugerencias para mejorar retención o engagement
        PROMPT;

        try {
            $router = app(AIRouter::class);
            $provider = $router->forUser($admin);

            $response = $provider->chat([
                ['role' => 'user', 'content' => $prompt],
            ]);

            $insightText = $response['content'] ?? $response['choices'][0]['message']['content'] ?? 'No se pudo generar el análisis.';
        } catch (\Throwable $e) {
            $insightText = 'Error al generar insights: ' . $e->getMessage();
        }

        return response()->json(['insights' => $insightText]);
    }

    // ─────────────────────────────────────────────
    // POST /api/admin/users/{user}/toggle-admin
    // ─────────────────────────────────────────────
    public function toggleAdmin(User $user): JsonResponse
    {
        $user->update(['is_admin' => ! $user->is_admin]);

        // B-12: audit log
        \Log::info('Admin role changed', [
            'by'      => request()->user()->email,
            'target'  => $user->email,
            'is_admin' => $user->is_admin,
            'ip'      => request()->ip(),
            'at'      => now()->toISOString(),
        ]);

        return response()->json(['is_admin' => $user->is_admin]);
    }

    // ─────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────

    /**
     * Compute brain score (0–100) for a user.
     * User must have memoryNodes already loaded.
     */
    private function computeBrainScore(User $user): int
    {
        $nodes = $user->memoryNodes;

        if ($nodes->isEmpty()) {
            return 0;
        }

        $types  = $nodes->pluck('type')->unique()->count();   // 0-7 types
        $total  = $nodes->count();
        $recent = $nodes->where('created_at', '>=', now()->subDays(7))->count();

        return min(100, ($types * 10) + min(50, $total) + min(20, $recent * 2));
    }

    private function today(): string
    {
        return now()->toDateString();
    }
}
