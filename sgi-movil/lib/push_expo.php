<?php
declare(strict_types=1);

/** Envío de notificaciones vía Expo Push API (https://docs.expo.dev/push-notifications/sending-notifications/). */
function expo_push_send_batch(array $messages): array
{
    if ($messages === []) {
        return ['ok' => true, 'sent' => 0];
    }

    $chunks = array_chunk($messages, 100);
    $errors = [];

    foreach ($chunks as $chunk) {
        $payload = json_encode($chunk, JSON_THROW_ON_ERROR);
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
                'content' => $payload,
                'timeout' => 15,
            ],
        ]);

        $raw = @file_get_contents('https://exp.host/--/api/v2/push/send', false, $ctx);
        if ($raw === false) {
            $errors[] = 'No se pudo contactar al servicio Expo Push';
            continue;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $errors[] = 'Respuesta inválida de Expo Push';
            continue;
        }

        foreach ($decoded['data'] ?? [] as $item) {
            if (isset($item['status']) && $item['status'] === 'error') {
                $errors[] = (string) ($item['message'] ?? 'Error Expo Push');
            }
        }
    }

    return [
        'ok' => $errors === [],
        'sent' => count($messages),
        'errors' => $errors,
    ];
}

/**
 * @param list<string> $expoTokens
 * @return list<array<string, mixed>>
 */
function expo_push_build_novedad_messages(
    array $expoTokens,
    string $novedadId,
    string $titulo,
    string $cuerpo
): array {
    $messages = [];
    foreach ($expoTokens as $token) {
        $token = trim($token);
        if ($token === '' || !str_starts_with($token, 'ExponentPushToken[')) {
            continue;
        }
        $messages[] = [
            'to' => $token,
            'title' => $titulo,
            'body' => mb_strlen($cuerpo) > 180 ? mb_substr($cuerpo, 0, 177) . '...' : $cuerpo,
            'sound' => 'default',
            'priority' => 'high',
            'channelId' => 'novedades',
            'data' => [
                'novedadId' => $novedadId,
                'screen' => 'novedades',
            ],
        ];
    }

    return $messages;
}
