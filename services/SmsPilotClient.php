<?php

declare(strict_types=1);

namespace app\services;

use RuntimeException;
use Yii;

/**
 * Клиент API-1 SMSPilot.
 */
class SmsPilotClient
{
    /**
     * @param array{apiKey: string, test: bool, apiUrl: string} $config
     */
    public function __construct(
        private readonly array $config
    ) {
    }

    /**
     * Отправляет SMS через SMSPilot.
     *
     * @param string $phone
     * @param string $text
     * @return void
     */
    public function send(string $phone, string $text): void
    {
        $params = [
            'send' => $text,
            'to' => $phone,
            'apikey' => (string) $this->config['apiKey'],
            'format' => 'json',
        ];

        if (!empty($this->config['test'])) {
            $params['test'] = '1';
        }

        [$status, $response] = $this->request($params);

        if ($response === false || $status < 200 || $status >= 300) {
            throw new RuntimeException(Yii::t('app', 'Запрос SMSPilot завершился ошибкой HTTP.'));
        }

        $data = json_decode($response, true);

        if (!is_array($data)) {
            throw new RuntimeException(Yii::t('app', 'SMSPilot вернул некорректный JSON.'));
        }

        if (array_key_exists('error', $data)) {
            throw new RuntimeException(Yii::t('app', 'SMSPilot отклонил отправку.'));
        }

        $sent = $data['send'][0] ?? null;
        if (!is_array($sent)
            || !isset($sent['server_id'], $sent['status'])
            || !ctype_digit((string) $sent['server_id'])
            || !in_array((string) $sent['status'], ['0', '1', '2', '3'], true)
        ) {
            throw new RuntimeException(Yii::t('app', 'SMSPilot не подтвердил отправку.'));
        }
    }

    /** @return array{int, string|false} */
    protected function request(array $params): array
    {
        $response = file_get_contents((string) $this->config['apiUrl'], false, stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => 'Content-Type: application/x-www-form-urlencoded',
                'content' => http_build_query($params),
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]));

        $statusLine = $http_response_header[0] ?? '';
        $status = preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $statusLine, $matches)
            ? (int) $matches[1]
            : 0;

        return [$status, $response];
    }
}
