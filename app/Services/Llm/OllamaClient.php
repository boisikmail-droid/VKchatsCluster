<?php

namespace App\Services\Llm;

use Illuminate\Support\Facades\Log;

class OllamaClient
{
    public function reply(string $system, string $prompt): string
    {
        $url = rtrim((string) config('vk.llm_url'), '/').'/api/chat';
        $body = json_encode([
            'model' => (string) config('vk.llm_model'),
            'stream' => false,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $prompt],
            ],
            'think' => false,
            'options' => [
                'temperature' => 0.8,
                'num_predict' => max(40, (int) config('vk.llm_num_predict', 280)),
                'num_ctx' => 2048,
            ],
        ], JSON_UNESCAPED_UNICODE);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nAccept: application/json",
                'content' => $body,
                'timeout' => (int) config('vk.llm_timeout'),
                'ignore_errors' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);

        if ($raw === false) {
            $error = error_get_last();

            throw new LlmException('Модель не ответила: '.($error['message'] ?? 'сеть недоступна'));
        }

        $data = json_decode($raw, true);

        if (!is_array($data)) {
            throw new LlmException('Модель вернула не JSON.');
        }

        if (isset($data['error'])) {
            $message = is_string($data['error']) ? $data['error'] : 'Ошибка модели';

            throw new LlmException($message);
        }

        $text = trim((string) ($data['message']['content'] ?? ''));

        if ($text === '') {
            Log::warning('llm empty reply', ['model' => config('vk.llm_model')]);

            throw new LlmException('Модель вернула пустой текст.');
        }

        return $this->oneLine($text);
    }

    private function oneLine(string $text): string
    {
        $text = preg_replace('/<think>.*?<\/think>/su', '', $text) ?? $text;
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        $text = preg_replace('/^(?:вот\s+как\s+)?филипп(?:\s+киркоров)?\s*:\s*/iu', '', $text) ?? $text;
        $text = preg_replace('/^[\p{L}]{2,24}\s*:\s*/u', '', $text) ?? $text;
        $text = trim($text);

        if (preg_match('/^(["«])(.*)\1$/u', $text, $matches)) {
            $text = trim($matches[2]);
        }

        $line = trim($text, " \t\"'«»");

        if (preg_match_all('/.*?[.!?](?:\s|$)/u', $line, $matches) && $matches[0] !== []) {
            $limit = max(1, (int) config('vk.llm_max_sentences', 5));
            $line = trim(implode('', array_slice($matches[0], 0, $limit)));
        }

        if (mb_strlen($line) > 700) {
            $line = rtrim(mb_substr($line, 0, 700));
        }

        return $line;
    }
}
