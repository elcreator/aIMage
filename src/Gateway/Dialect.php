<?php

namespace EvolutionCMS\aIMage\Gateway;

/**
 * Translation between one canonical transcript and the gateway's two dialects.
 *
 * The gateway serves every model on both /chat/completions and /messages and
 * converts between them, but the conversion drops tool calls in both
 * directions: a Claude reply on /chat/completions keeps only its text blocks,
 * and a non-Claude reply on /messages is rebuilt as a single text block. The
 * planner is a tool-calling loop, so the route is chosen by model family and
 * never by preference — that is the whole reason this class exists.
 *
 * The canonical transcript is a list of:
 *
 *   ['role' => 'user',      'text' => string]
 *   ['role' => 'assistant', 'text' => ?string, 'tool_calls' => [['id','name','input']]]
 *   ['role' => 'tool',      'results' => [['id' => string, 'content' => string]]]
 *
 * and a canonical reply is ['text' => string, 'tool_calls' => [...], 'stop_reason', 'usage'].
 */
final class Dialect
{
    public const ANTHROPIC = 'messages';
    public const OPENAI = 'chat/completions';

    /**
     * Models whose tool calls only survive on /messages.
     *
     * Matched on the gateway's own model ids, which prefix every Anthropic
     * model with `claude-`. A model this misses degrades to /chat/completions
     * and loses its tool calls, which the planner reports as "the model
     * answered without choosing an action" rather than failing silently.
     */
    public static function isAnthropic(string $model): bool
    {
        return str_starts_with(strtolower(trim($model)), 'claude-');
    }

    public static function routeFor(string $model): string
    {
        return self::isAnthropic($model) ? self::ANTHROPIC : self::OPENAI;
    }

    /**
     * Canonical request → the dialect of the route it will be sent to.
     *
     * @param array{model:string,messages:array,system?:string,tools?:array,max_tokens?:int,temperature?:float} $body
     */
    public static function encodeRequest(array $body): array
    {
        $model = (string) ($body['model'] ?? '');
        $anthropic = self::isAnthropic($model);

        $encoded = [
            'model' => $model,
            'max_tokens' => (int) ($body['max_tokens'] ?? 4096),
        ];

        if (isset($body['temperature'])) {
            $encoded['temperature'] = (float) $body['temperature'];
        }

        $system = trim((string) ($body['system'] ?? ''));
        $messages = self::encodeMessages((array) ($body['messages'] ?? []), $anthropic);

        if ($system !== '') {
            if ($anthropic) {
                // Anthropic's top-level field. The gateway would also lift a
                // system message into it, but sending it where it belongs
                // avoids depending on that.
                $encoded['system'] = $system;
            } else {
                array_unshift($messages, ['role' => 'system', 'content' => $system]);
            }
        }

        $encoded['messages'] = $messages;

        if (!empty($body['tools'])) {
            $encoded['tools'] = self::encodeTools((array) $body['tools'], $anthropic);

            if (!$anthropic) {
                $encoded['tool_choice'] = 'auto';
            }
        }

        return $encoded;
    }

    /**
     * @param array<int,array{name:string,description:string,input_schema:array}> $tools
     */
    private static function encodeTools(array $tools, bool $anthropic): array
    {
        $encoded = [];

        foreach ($tools as $tool) {
            if ($anthropic) {
                $encoded[] = [
                    'name' => $tool['name'],
                    'description' => $tool['description'],
                    'input_schema' => $tool['input_schema'],
                ];
                continue;
            }

            $encoded[] = [
                'type' => 'function',
                'function' => [
                    'name' => $tool['name'],
                    'description' => $tool['description'],
                    'parameters' => $tool['input_schema'],
                ],
            ];
        }

        return $encoded;
    }

    private static function encodeMessages(array $messages, bool $anthropic): array
    {
        $encoded = [];

        foreach ($messages as $message) {
            $role = (string) ($message['role'] ?? 'user');

            if ($role === 'user') {
                $encoded[] = ['role' => 'user', 'content' => (string) ($message['text'] ?? '')];
                continue;
            }

            if ($role === 'assistant') {
                $encoded[] = self::encodeAssistant($message, $anthropic);
                continue;
            }

            if ($role === 'tool') {
                foreach (self::encodeToolResults($message, $anthropic) as $part) {
                    $encoded[] = $part;
                }
            }
        }

        return $encoded;
    }

    private static function encodeAssistant(array $message, bool $anthropic): array
    {
        $text = (string) ($message['text'] ?? '');
        $calls = (array) ($message['tool_calls'] ?? []);

        if ($anthropic) {
            $blocks = [];

            if ($text !== '') {
                $blocks[] = ['type' => 'text', 'text' => $text];
            }

            foreach ($calls as $call) {
                $blocks[] = [
                    'type' => 'tool_use',
                    'id' => (string) $call['id'],
                    'name' => (string) $call['name'],
                    'input' => (object) ((array) ($call['input'] ?? [])),
                ];
            }

            // Anthropic rejects an assistant turn with no content at all.
            if ($blocks === []) {
                $blocks[] = ['type' => 'text', 'text' => '(no content)'];
            }

            return ['role' => 'assistant', 'content' => $blocks];
        }

        $encoded = ['role' => 'assistant', 'content' => $text !== '' ? $text : null];

        if ($calls !== []) {
            $encoded['tool_calls'] = array_map(static fn (array $call) => [
                'id' => (string) $call['id'],
                'type' => 'function',
                'function' => [
                    'name' => (string) $call['name'],
                    'arguments' => json_encode((object) ((array) ($call['input'] ?? [])), JSON_UNESCAPED_UNICODE),
                ],
            ], $calls);
        }

        return $encoded;
    }

    /**
     * Anthropic carries every result of one assistant turn in a single user
     * message; OpenAI wants one `tool` message per call. Hence the list return.
     */
    private static function encodeToolResults(array $message, bool $anthropic): array
    {
        $results = (array) ($message['results'] ?? []);

        if ($anthropic) {
            $blocks = [];

            foreach ($results as $result) {
                $blocks[] = [
                    'type' => 'tool_result',
                    'tool_use_id' => (string) $result['id'],
                    'content' => (string) $result['content'],
                ];
            }

            return $blocks === [] ? [] : [['role' => 'user', 'content' => $blocks]];
        }

        $encoded = [];

        foreach ($results as $result) {
            $encoded[] = [
                'role' => 'tool',
                'tool_call_id' => (string) $result['id'],
                'content' => (string) $result['content'],
            ];
        }

        return $encoded;
    }

    // ------------------------------------------------------------------
    // Decoding
    // ------------------------------------------------------------------

    /**
     * A raw gateway reply → the canonical assistant turn.
     *
     * The shape is detected from the payload rather than from the model, so a
     * gateway that answers one dialect on the other's route still decodes.
     *
     * @return array{text:string,tool_calls:array<int,array{id:string,name:string,input:array}>,stop_reason:?string,usage:array}
     */
    public static function decodeReply(array $response): array
    {
        if (isset($response['content']) && is_array($response['content'])) {
            return self::decodeAnthropic($response);
        }

        return self::decodeOpenAi($response);
    }

    private static function decodeAnthropic(array $response): array
    {
        $text = '';
        $calls = [];

        foreach ($response['content'] as $block) {
            if (!is_array($block)) {
                continue;
            }

            $type = (string) ($block['type'] ?? '');

            if ($type === 'text') {
                $text .= (string) ($block['text'] ?? '');
            } elseif ($type === 'tool_use') {
                $calls[] = [
                    'id' => (string) ($block['id'] ?? uniqid('call_', true)),
                    'name' => (string) ($block['name'] ?? ''),
                    'input' => (array) ($block['input'] ?? []),
                ];
            }
        }

        return [
            'text' => trim($text),
            'tool_calls' => $calls,
            'stop_reason' => isset($response['stop_reason']) ? (string) $response['stop_reason'] : null,
            'usage' => (array) ($response['usage'] ?? []),
        ];
    }

    private static function decodeOpenAi(array $response): array
    {
        $message = $response['choices'][0]['message'] ?? [];
        $text = (string) ($message['content'] ?? '');
        $calls = [];

        foreach ((array) ($message['tool_calls'] ?? []) as $call) {
            if (!is_array($call)) {
                continue;
            }

            // `arguments` is a JSON *string* here. A model occasionally emits
            // one that will not parse; an empty input is a better outcome than
            // a fatal, because the tool's own validation then reports which
            // argument is missing.
            $arguments = json_decode((string) ($call['function']['arguments'] ?? '{}'), true);

            $calls[] = [
                'id' => (string) ($call['id'] ?? uniqid('call_', true)),
                'name' => (string) ($call['function']['name'] ?? ''),
                'input' => is_array($arguments) ? $arguments : [],
            ];
        }

        return [
            'text' => trim($text),
            'tool_calls' => $calls,
            'stop_reason' => isset($response['choices'][0]['finish_reason'])
                ? (string) $response['choices'][0]['finish_reason']
                : null,
            'usage' => (array) ($response['usage'] ?? []),
        ];
    }
}
