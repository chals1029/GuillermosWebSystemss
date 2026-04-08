<?php

class SystemStatusStore
{
    private const FILE_PATH = __DIR__ . '/../storage/order_intake_status.json';

    /**
     * Retrieve the current system status with safe defaults.
     *
     * @return array{orders_paused:bool,customer_message:string,updated_at:?string,updated_by:?int,updated_by_name:?string}
     */
    public static function get(): array
    {
        $defaults = [
            'orders_paused' => false,
            'customer_message' => '',
            'updated_at' => null,
            'updated_by' => null,
            'updated_by_name' => null,
        ];

        $path = self::FILE_PATH;
        if (!is_file($path)) {
            return $defaults;
        }

        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return $defaults;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $defaults;
        }

        return [
            'orders_paused' => !empty($decoded['orders_paused']),
            'customer_message' => isset($decoded['customer_message']) ? (string)$decoded['customer_message'] : '',
            'updated_at' => isset($decoded['updated_at']) && $decoded['updated_at'] !== '' ? (string)$decoded['updated_at'] : null,
            'updated_by' => isset($decoded['updated_by']) ? (int)$decoded['updated_by'] : null,
            'updated_by_name' => isset($decoded['updated_by_name']) && $decoded['updated_by_name'] !== ''
                ? (string)$decoded['updated_by_name']
                : null,
        ];
    }

    /**
     * Persist a new status snapshot.
     *
     * @param array{orders_paused?:bool|int|string,customer_message?:string,updated_at?:string,updated_by?:int,updated_by_name?:string} $overrides
     * @return array{orders_paused:bool,customer_message:string,updated_at:?string,updated_by:?int,updated_by_name:?string}
     */
    public static function set(array $overrides): array
    {
        $current = self::get();
        $next = [
            'orders_paused' => !empty($overrides['orders_paused']),
            'customer_message' => self::sanitizeMessage($overrides['customer_message'] ?? $current['customer_message']),
            'updated_at' => isset($overrides['updated_at']) && $overrides['updated_at'] !== ''
                ? (string)$overrides['updated_at']
                : gmdate('c'),
            'updated_by' => isset($overrides['updated_by'])
                ? (int)$overrides['updated_by']
                : ($current['updated_by'] ?? null),
            'updated_by_name' => isset($overrides['updated_by_name']) && $overrides['updated_by_name'] !== ''
                ? trim((string)$overrides['updated_by_name'])
                : ($current['updated_by_name'] ?? null),
        ];

        $path = self::FILE_PATH;
        $directory = dirname($path);
        if (!is_dir($directory)) {
            @mkdir($directory, 0755, true);
        }

        $payload = json_encode($next, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return $current;
        }

        $tempPath = $path . '.tmp';
        if (@file_put_contents($tempPath, $payload, LOCK_EX) === false) {
            return $current;
        }

        if (!@rename($tempPath, $path)) {
            // Attempt to clean up temp file if rename fails
            @unlink($tempPath);
            return $current;
        }

        return $next;
    }

    private static function sanitizeMessage($value): string
    {
        $message = trim((string)$value);
        if ($message === '') {
            return '';
        }

        $message = str_replace(["\r", "\n"], ' ', $message);
        $message = preg_replace('/\s+/', ' ', $message);

        // Limit to 240 ASCII characters to avoid overly long banners
        if (strlen($message) > 240) {
            $message = substr($message, 0, 240);
        }

        return $message;
    }
}
