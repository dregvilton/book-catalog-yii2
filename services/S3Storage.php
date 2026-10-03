<?php

declare(strict_types=1);

namespace app\services;

use RuntimeException;
use yii\web\UploadedFile;

/**
 * Адаптер загрузки файлов в S3-compatible хранилище.
 */
class S3Storage
{
    /**
     * @param array{endpoint: string, publicEndpoint: string, bucket: string, region: string, accessKey: string, secretKey: string} $config
     */
    public function __construct(
        private readonly array $config
    ) {
    }

    /**
     * Загружает файл в S3-compatible хранилище.
     *
     * @param UploadedFile $file
     * @param string $directory
     * @return string
     */
    public function upload(UploadedFile $file, string $directory): string
    {
        $key = trim($directory, '/') . '/' . date('Y/m') . '/' . bin2hex(random_bytes(16)) . '.' . $file->extension;
        $content = file_get_contents($file->tempName);

        if ($content === false) {
            throw new RuntimeException(\Yii::t('app', 'Не удалось прочитать загруженный файл.'));
        }

        $this->putObject($key, $content, $file->type ?: 'application/octet-stream');

        return $this->publicUrl($key);
    }

    /** Удаляет только объект из нашего bucket; чужие URL не затрагивает. */
    public function delete(string $publicUrl): void
    {
        $prefix = rtrim((string) $this->config['publicEndpoint'], '/')
            . '/' . rawurlencode((string) $this->config['bucket']) . '/';

        if (!str_starts_with($publicUrl, $prefix)) {
            return;
        }

        $key = rawurldecode(substr($publicUrl, strlen($prefix)));

        if ($key === '' || str_contains($key, '..') || str_starts_with($key, '/')) {
            return;
        }

        $bucket = (string) $this->config['bucket'];
        $url = rtrim((string) $this->config['endpoint'], '/') . '/'
            . rawurlencode($bucket) . '/' . str_replace('%2F', '/', rawurlencode($key));
        $headers = $this->signedHeaders('DELETE', $bucket, $key, '', 'application/octet-stream');
        $context = stream_context_create(['http' => [
            'method' => 'DELETE',
            'header' => $this->formatHeaders($headers),
            'ignore_errors' => true,
            'timeout' => 15,
        ]]);
        $result = file_get_contents($url, false, $context);
        $status = (int) preg_replace('/^HTTP\/\S+\s+(\d+).*$/', '$1', $http_response_header[0] ?? '');

        if ($result === false || !in_array($status, [200, 204, 404], true)) {
            throw new RuntimeException(\Yii::t('app', 'Не удалось удалить обложку из хранилища.'));
        }
    }

    /**
     * Отправляет объект в bucket.
     *
     * @param string $key
     * @param string $content
     * @param string $contentType
     * @return void
     */
    private function putObject(string $key, string $content, string $contentType): void
    {
        $endpoint = rtrim((string) $this->config['endpoint'], '/');
        $bucket = (string) $this->config['bucket'];
        $url = $endpoint . '/' . rawurlencode($bucket) . '/' . str_replace('%2F', '/', rawurlencode($key));

        $headers = $this->signedHeaders('PUT', $bucket, $key, $content, $contentType);
        $context = stream_context_create([
            'http' => [
                'method' => 'PUT',
                'header' => $this->formatHeaders($headers),
                'content' => $content,
                'ignore_errors' => true,
                'timeout' => 15,
            ],
        ]);

        $result = file_get_contents($url, false, $context);
        $statusLine = $http_response_header[0] ?? '';

        if ($result === false || !str_contains($statusLine, '200')) {
            throw new RuntimeException(\Yii::t('app', 'S3 не принял файл: {status}', [
                'status' => $statusLine ?: \Yii::t('app', 'нет ответа'),
            ]));
        }
    }

    /**
     * Формирует подписанные заголовки AWS Signature V4.
     *
     * @param string $method
     * @param string $bucket
     * @param string $key
     * @param string $content
     * @param string $contentType
     * @return array<string, string>
     */
    private function signedHeaders(string $method, string $bucket, string $key, string $content, string $contentType): array
    {
        $endpoint = parse_url((string) $this->config['endpoint']);
        $host = $endpoint['host'] . (isset($endpoint['port']) ? ':' . $endpoint['port'] : '');
        $region = (string) $this->config['region'];
        $accessKey = (string) $this->config['accessKey'];
        $secretKey = (string) $this->config['secretKey'];
        $amzDate = gmdate('Ymd\THis\Z');
        $date = gmdate('Ymd');
        $payloadHash = hash('sha256', $content);
        $canonicalUri = '/' . rawurlencode($bucket) . '/' . str_replace('%2F', '/', rawurlencode($key));
        $credentialScope = $date . '/' . $region . '/s3/aws4_request';

        $canonicalHeaders = implode("\n", [
            'content-type:' . $contentType,
            'host:' . $host,
            'x-amz-content-sha256:' . $payloadHash,
            'x-amz-date:' . $amzDate,
            '',
        ]);
        $signedHeaders = 'content-type;host;x-amz-content-sha256;x-amz-date';
        $canonicalRequest = implode("\n", [
            $method,
            $canonicalUri,
            '',
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);
        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $amzDate,
            $credentialScope,
            hash('sha256', $canonicalRequest),
        ]);

        $signingKey = $this->signingKey($secretKey, $date, $region);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        return [
            'Authorization' => 'AWS4-HMAC-SHA256 Credential=' . $accessKey . '/' . $credentialScope
                . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature,
            'Content-Type' => $contentType,
            'Host' => $host,
            'X-Amz-Content-Sha256' => $payloadHash,
            'X-Amz-Date' => $amzDate,
        ];
    }

    /**
     * Возвращает ключ подписи AWS Signature V4.
     *
     * @param string $secretKey
     * @param string $date
     * @param string $region
     * @return string
     */
    private function signingKey(string $secretKey, string $date, string $region): string
    {
        $dateKey = hash_hmac('sha256', $date, 'AWS4' . $secretKey, true);
        $dateRegionKey = hash_hmac('sha256', $region, $dateKey, true);
        $dateRegionServiceKey = hash_hmac('sha256', 's3', $dateRegionKey, true);

        return hash_hmac('sha256', 'aws4_request', $dateRegionServiceKey, true);
    }

    /**
     * Преобразует массив заголовков в HTTP-строку.
     *
     * @param array<string, string> $headers
     * @return string
     */
    private function formatHeaders(array $headers): string
    {
        $lines = [];

        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        return implode("\r\n", $lines);
    }

    /**
     * Возвращает публичный URL загруженного объекта.
     *
     * @param string $key
     * @return string
     */
    private function publicUrl(string $key): string
    {
        return rtrim((string) $this->config['publicEndpoint'], '/')
            . '/' . rawurlencode((string) $this->config['bucket'])
            . '/' . str_replace('%2F', '/', rawurlencode($key));
    }
}
