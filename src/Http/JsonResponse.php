<?php

declare(strict_types=1);

namespace Everoute\Http;

final class JsonResponse extends Response
{
    public function __construct(array $data, int $status = 200, array $headers = [])
    {
        $headers = array_merge([
            'Content-Type' => 'application/json; charset=utf-8',
        ], $headers);

        $jsonReady = $this->normalizeForJson($data);
        $json = json_encode($jsonReady, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            $json = '{"error":"json_encode_failed"}';
        }

        parent::__construct($status, $headers, $json);
    }

    /** @return mixed */
    private function normalizeForJson(mixed $value): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->normalizeForJson($item);
            }
            return $value;
        }

        if (is_float($value) && !is_finite($value)) {
            return null;
        }

        return $value;
    }
}
