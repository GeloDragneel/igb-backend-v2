<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Header → AI Assistant (messenger style).
 * The browser never sees the API key: React → this controller → OpenAI or Anthropic.
 *
 * .env   AI_PROVIDER=openai | anthropic
 *        AI_API_KEY=...            (never commit .env)
 *        AI_MODEL=gpt-4o-mini      (optional; anthropic default claude-haiku-4-5-20251001)
 * config/services.php → 'ai' => [...]   (see ADD_TO_services.php.txt)
 *
 * GET    /ai/messages   my last 50 messages
 * POST   /ai/messages   { message, context: { page, branch, period } } → saves both sides, returns the reply
 * DELETE /ai/messages   new conversation
 */
class AiAssistantController extends Controller
{
    private const HISTORY = 20;        // messages sent back to the AI as memory
    private const MAX_CHARS = 4000;    // per user message

    /** [user_type, user_id, display name] of the login */
    private function who(Request $request): array
    {
        $u = $request->user();
        $type = class_basename($u);   // UserClient | UserStaff | UserClientStaff
        $type = ['UserClient' => 'Client', 'UserStaff' => 'Staff', 'UserClientStaff' => 'ClientStaff'][$type] ?? $type;
        $name = (string) ($u->username ?? '');
        if ($type === 'Staff' && !empty($u->comid)) {
            $e = DB::table('m_employee')->where('com_id', $u->comid)->first(['first_name', 'last_name']);
            if ($e) $name = trim("{$e->first_name} {$e->last_name}");
        }
        return [$type, (int) $u->id, $name];
    }

    public function index(Request $request)
    {
        [$type, $id] = $this->who($request);
        $rows = DB::table('t_ai_message')->where('user_type', $type)->where('user_id', $id)
            ->orderByDesc('id')->limit(50)->get(['id', 'role', 'content', 'created_at'])->reverse()->values();
        return response()->json(['messages' => $rows, 'ready' => (bool) config('services.ai.key')]);
    }

    public function destroy(Request $request)
    {
        [$type, $id] = $this->who($request);
        DB::table('t_ai_message')->where('user_type', $type)->where('user_id', $id)->delete();
        return response()->json(['message' => 'Conversation cleared.']);
    }

    public function store(Request $request)
    {
        $d = $request->validate([
            'message'         => ['required', 'string', 'max:' . self::MAX_CHARS],
            'context'         => ['nullable', 'array'],
            'context.page'    => ['nullable', 'string', 'max:120'],
            'context.branch'  => ['nullable', 'string', 'max:160'],
            'context.period'  => ['nullable', 'string', 'max:60'],
            'context.role'    => ['nullable', 'string', 'max:60'],
        ]);
        if (!config('services.ai.key')) {
            return response()->json(['message' => 'The AI Assistant is not set up yet (AI_API_KEY in .env).'], 503);
        }
        [$type, $id, $name] = $this->who($request);

        // max 20 questions a minute per login (done here: the throttle middleware needs getAuthIdentifier())
        $limit = "ai:{$type}:{$id}";
        if (RateLimiter::tooManyAttempts($limit, 20)) {
            return response()->json(['message' => 'Too many questions. Try again in ' . RateLimiter::availableIn($limit) . ' seconds.'], 429);
        }
        RateLimiter::hit($limit, 60);
        $now = now();

        $userMsgId = DB::table('t_ai_message')->insertGetId([
            'user_type' => $type, 'user_id' => $id, 'role' => 'user', 'content' => trim($d['message']),
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $history = DB::table('t_ai_message')->where('user_type', $type)->where('user_id', $id)
            ->orderByDesc('id')->limit(self::HISTORY)->get(['role', 'content'])->reverse()
            ->map(fn ($m) => ['role' => $m->role, 'content' => $m->content])->values()->all();
        while ($history && $history[0]['role'] !== 'user') array_shift($history);   // must start with the user

        try {
            [$reply, $tokens] = $this->ask($this->systemPrompt($name, $type, $d['context'] ?? []), $history);
        } catch (Throwable $e) {
            Log::warning('[ai] ' . $e->getMessage());
            DB::table('t_ai_message')->where('id', $userMsgId)->delete();   // let them send it again
            return response()->json(['message' => $this->reason($e)], 502);
        }

        $replyId = DB::table('t_ai_message')->insertGetId([
            'user_type' => $type, 'user_id' => $id, 'role' => 'assistant', 'content' => $reply, 'tokens' => $tokens,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json([
            'user'  => ['id' => $userMsgId, 'role' => 'user', 'content' => trim($d['message']), 'created_at' => (string) $now],
            'reply' => ['id' => $replyId, 'role' => 'assistant', 'content' => $reply, 'created_at' => (string) now()],
        ]);
    }

    private function systemPrompt(string $name, string $type, array $ctx): string
    {
        $lines = array_filter([
            'You are "IGB Assistant", the helper inside IGB FEMS, an accounting system of IGB Accounting Consultancy in the Philippines.',
            'Help with: using the system (Task → Sales, Purchases, Expenses, Importation, Exportation, Inventory, Banking, General Journal, Fixed Assets, Loans; Setup; BIR & Tax; Books & Ledger; Financial Reports; Other Reports), bookkeeping and Philippine BIR tax (VAT 2550M/2550Q, percentage tax, withholding 2307 / 1601-EQ / 0619-E, income tax 1701/1702, books of accounts).',
            'Amounts are in Philippine peso (₱). Be short and practical: steps or bullet points when it helps.',
            'You cannot see or change the records in the system. If they ask for specific numbers, tell them which report or page shows it.',
            'For tax deadlines or rates that may have changed, say to confirm with the latest BIR issuance. Do not give legal advice.',
            "User: {$name} ({$type}" . (!empty($ctx['role']) ? ", {$ctx['role']}" : '') . ').',
            !empty($ctx['branch']) ? "Current client/branch: {$ctx['branch']}." : null,
            !empty($ctx['period']) ? "Selected period: {$ctx['period']}." : null,
            !empty($ctx['page']) ? "They are on page: {$ctx['page']}." : null,
            'Today is ' . now('Asia/Manila')->format('F j, Y') . '.',
        ]);
        return implode("\n", $lines);
    }

    /** → [reply text, tokens used] */
    private function ask(string $system, array $messages): array
    {
        $key = config('services.ai.key');
        $provider = strtolower((string) config('services.ai.provider', 'openai'));

        if ($provider === 'anthropic') {
            $r = Http::timeout(60)->withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => config('services.ai.model') ?: 'claude-haiku-4-5-20251001',
                    'max_tokens' => 1024, 'system' => $system, 'messages' => $messages,
                ])->throw()->json();
            $text = collect($r['content'] ?? [])->where('type', 'text')->pluck('text')->implode("\n");
            return [trim($text), (int) (($r['usage']['input_tokens'] ?? 0) + ($r['usage']['output_tokens'] ?? 0))];
        }

        $r = Http::timeout(60)->withToken($key)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => config('services.ai.model') ?: 'gpt-4o-mini',
                'max_tokens' => 1024,
                'messages' => array_merge([['role' => 'system', 'content' => $system]], $messages),
            ])->throw()->json();
        return [trim((string) ($r['choices'][0]['message']['content'] ?? '')), (int) ($r['usage']['total_tokens'] ?? 0)];
    }

    /** a readable reason for the chat (never shows the key) */
    private function reason(Throwable $e): string
    {
        $msg = $e->getMessage();
        if ($e instanceof \Illuminate\Http\Client\RequestException) {
            $status = $e->response->status();
            $api = (string) ($e->response->json('error.message') ?? $e->response->json('error.type') ?? '');
            $code = (string) ($e->response->json('error.code') ?? $e->response->json('error.type') ?? '');
            $why = match (true) {
                $status === 401 => 'The AI_API_KEY is wrong or was deleted. Create a new key and update .env.',
                $code === 'insufficient_quota' || str_contains(strtolower($api), 'credit') => 'No credits left on the AI account. Add credits under Billing.',
                $status === 429 => 'The AI service is busy (rate limit). Try again in a minute.',
                $status === 404 || $code === 'model_not_found' => 'The AI_MODEL in .env does not exist for this account.',
                $status >= 500 => 'The AI service is having problems. Try again later.',
                default => "AI error {$status}",
            };
            return $why . ($api !== '' ? " ({$api})" : '');
        }
        if (str_contains($msg, 'cURL error 60') || str_contains($msg, 'SSL certificate')) {
            return 'SSL problem on this PC (cURL error 60): set curl.cainfo / openssl.cafile to cacert.pem in php.ini, then restart.';
        }
        if (str_contains($msg, 'cURL error 6') || str_contains($msg, 'cURL error 7') || str_contains($msg, 'timed out')) {
            return 'Could not reach the AI service (no internet / blocked / timeout).';
        }
        return "The assistant couldn't answer: " . mb_substr($msg, 0, 200);
    }
}