<?php

namespace App\Services\Vk;

class VkClient
{
    public function __construct(private string $token)
    {
    }

    public function call(string $method, array $params = []): array
    {
        $params['v'] = config('vk.api_version');
        $params['lang'] = 'ru';
        $params['access_token'] = $this->token;

        $url = rtrim((string) config('vk.api_url'), '/').'/'.$method;
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", [
                    'Authorization: Bearer '.$this->token,
                    'Accept: application/json',
                    'Content-Type: application/x-www-form-urlencoded',
                ]),
                'content' => http_build_query($params),
                'timeout' => 20,
                'ignore_errors' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);

        if ($raw === false) {
            $error = error_get_last();

            throw new VkApiException('Не удалось связаться с API ВКонтакте: '.($error['message'] ?? 'сеть недоступна'));
        }

        $data = json_decode($raw, true);

        if (!is_array($data)) {
            throw new VkApiException('API ВКонтакте вернуло не JSON.');
        }

        if (isset($data['error'])) {
            $message = $data['error']['error_msg'] ?? 'Ошибка API ВКонтакте';
            $code = (int) ($data['error']['error_code'] ?? 0);

            throw new VkApiException($message, $code, $data['error']);
        }

        $payload = $data['response'] ?? [];

        return is_array($payload) ? $payload : ['value' => $payload];
    }
}
