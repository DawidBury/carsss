<?php

declare(strict_types=1);

namespace App\Api\Request;

use JsonException;

final class CursorCodec
{
    /**
     * @param array<string, string> $context
     */
    public function encode(string $afterValue, string $afterPublicId, array $context): string
    {
        $payload = ['a' => $afterValue, 'p' => $afterPublicId];
        if ($context !== []) {
            $payload['f'] = $context;
        }

        $json = json_encode($payload, \JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    public function decode(string $cursor): DecodedCursor
    {
        $padded = strtr($cursor, '-_', '+/');
        $padded .= str_repeat('=', (4 - \strlen($padded) % 4) % 4);

        $json = base64_decode($padded, true);
        if ($json === false) {
            throw InvalidCursorException::malformed();
        }

        try {
            $data = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw InvalidCursorException::malformed();
        }

        if (!\is_array($data) || !isset($data['a'], $data['p']) || !\is_string($data['a']) || !\is_string($data['p'])) {
            throw InvalidCursorException::malformed();
        }

        $context = [];
        if (isset($data['f'])) {
            if (!\is_array($data['f'])) {
                throw InvalidCursorException::malformed();
            }
            foreach ($data['f'] as $key => $value) {
                if (!\is_string($key) || !\is_string($value)) {
                    throw InvalidCursorException::malformed();
                }
                $context[$key] = $value;
            }
        }

        return new DecodedCursor($data['a'], $data['p'], $context);
    }
}
