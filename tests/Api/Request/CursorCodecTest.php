<?php

declare(strict_types=1);

namespace App\Tests\Api\Request;

use App\Api\Request\CursorCodec;
use App\Api\Request\InvalidCursorException;
use PHPUnit\Framework\TestCase;

final class CursorCodecTest extends TestCase
{
    public function testEncodeDecodeRoundTripsExactly(): void
    {
        $codec = new CursorCodec();

        $cursor = $codec->encode('9.3', 'a1b2c3d4-e5f6-7890-abcd-ef1234567890', ['sort' => 'acceleration']);
        $decoded = $codec->decode($cursor);

        self::assertSame('9.3', $decoded->afterValue);
        self::assertSame('a1b2c3d4-e5f6-7890-abcd-ef1234567890', $decoded->afterPublicId);
        self::assertSame(['sort' => 'acceleration'], $decoded->context);
    }

    public function testEncodedCursorIsUrlSafe(): void
    {
        $codec = new CursorCodec();

        $cursor = $codec->encode('9.3', 'a1b2c3d4-e5f6-7890-abcd-ef1234567890', []);

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $cursor);
    }

    public function testEmptyContextIsOmittedAndDecodesToEmptyArray(): void
    {
        $codec = new CursorCodec();

        $decoded = $codec->decode($codec->encode('name', 'id', []));

        self::assertSame([], $decoded->context);
    }

    public function testDecodingGarbageThrowsInvalidCursorException(): void
    {
        $codec = new CursorCodec();

        $this->expectException(InvalidCursorException::class);
        $codec->decode('not-valid-base64!!!');
    }

    public function testDecodingWellFormedBase64ThatIsNotJsonThrows(): void
    {
        $codec = new CursorCodec();
        $notJson = rtrim(strtr(base64_encode('plain text, not json'), '+/', '-_'), '=');

        $this->expectException(InvalidCursorException::class);
        $codec->decode($notJson);
    }

    public function testDecodingJsonMissingRequiredFieldsThrows(): void
    {
        $codec = new CursorCodec();
        $incomplete = rtrim(strtr(base64_encode('{"a":"9.3"}'), '+/', '-_'), '=');

        $this->expectException(InvalidCursorException::class);
        $codec->decode($incomplete);
    }
}
