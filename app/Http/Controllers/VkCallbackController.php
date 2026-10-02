<?php

namespace App\Http\Controllers;

use App\Models\ActivityEvent;
use App\Models\VkGroup;
use App\Services\Vk\CallbackProcessor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class VkCallbackController extends Controller
{
    public function __construct(private CallbackProcessor $processor)
    {
    }

    public function handle(Request $request)
    {
        $payload = $request->json()->all();

        if ($payload === []) {
            $payload = $request->all();
        }

        $type = $payload['type'] ?? null;
        $groupId = $payload['group_id'] ?? null;

        if (!is_string($type) || $groupId === null || $groupId === '') {
            return $this->plain('bad request', 400);
        }

        $group = VkGroup::where('vk_id', $groupId)->first();

        if ($group === null) {
            return $this->plain('unknown group', 404);
        }

        $secret = (string) ($payload['secret'] ?? '');

        if (!hash_equals((string) $group->secret_key, $secret)) {
            return $this->plain('forbidden', 403);
        }

        if ($type === 'confirmation') {
            return $this->plain($this->confirmationCode($group));
        }

        $eventId = isset($payload['event_id']) ? (string) $payload['event_id'] : null;

        if ($eventId !== null && ActivityEvent::where('vk_event_id', $eventId)->exists()) {
            return $this->plain('ok');
        }

        $deliver = null;

        try {
            DB::transaction(function () use ($group, $payload, &$deliver) {
                $deliver = $this->processor->handle($group, $payload);
            });
        } catch (UniqueConstraintViolationException $e) {
            return $this->plain('ok');
        } catch (Throwable $e) {
            Log::error('vk callback failed', [
                'type' => $type,
                'group_id' => $groupId,
                'error' => $e->getMessage(),
            ]);

            return $this->plain('error', 500);
        }

        return $this->acknowledge($group, $payload, $deliver);
    }

    private function confirmationCode(VkGroup $group): string
    {
        if (env('APP_ENV') !== 'testing') {
            $fresh = $this->confirmationCodeFromEnvFile();

            if ($fresh !== '') {
                return $fresh;
            }
        }

        $configured = trim((string) config('vk.confirmation_code', ''));

        if ($configured !== '') {
            return $configured;
        }

        return (string) $group->confirmation_code;
    }

    private function confirmationCodeFromEnvFile(): string
    {
        $path = (string) env('VK_CONFIRMATION_FILE', base_path('.env'));

        if (!is_readable($path)) {
            return '';
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            return '';
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (!str_starts_with($line, 'VK_CONFIRMATION_CODE=')) {
                continue;
            }

            $value = trim(substr($line, strlen('VK_CONFIRMATION_CODE=')));

            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"'))
                || (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            return trim($value);
        }

        return '';
    }

    private function acknowledge(VkGroup $group, array $payload, ?string $deliver)
    {
        $response = $this->plain('ok');
        $after = function () use ($group, $payload, $deliver) {
            try {
                $this->processor->enrichFromPayload($group, $payload);
            } catch (Throwable $e) {
                Log::warning('dialog enrich failed', ['error' => $e->getMessage()]);
            }

            if ($deliver === null) {
                return;
            }

            try {
                $this->processor->deliver($group, $payload, $deliver);
            } catch (Throwable $e) {
                Log::error('vk delayed reply failed', ['error' => $e->getMessage()]);
            }
        };

        if (PHP_SAPI === 'fpm-fcgi' && function_exists('fastcgi_finish_request')) {
            ignore_user_abort(true);
            $response->send();
            fastcgi_finish_request();
            $after();
            exit;
        }

        $after();

        return $response;
    }

    private function plain(string $body, int $status = 200)
    {
        return response($body, $status, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
