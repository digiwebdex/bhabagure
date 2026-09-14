<?php

namespace App\Services\Passports;

use Aws\Exception\AwsException;
use Aws\Textract\TextractClient;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * AWS Textract DetectDocumentText (synchronous; JPEG, PNG or single-page PDF up to 5 MB). Only the LINE blocks are
 * used — MrzParser finds the machine-readable zone among them. Any error or timeout means "unavailable".
 */
final class TextractPassportTextReader implements PassportTextReader
{
    public function __construct(private readonly TextractClient $client) {}

    public function name(): string
    {
        return 'textract';
    }

    public function read(string $bytes, string $mime): ?string
    {
        try {
            $result = $this->client->detectDocumentText(['Document' => ['Bytes' => $bytes]]);
        } catch (AwsException $e) {
            Log::warning('Textract could not read a passport scan', ['code' => $e->getAwsErrorCode(), 'message' => $e->getAwsErrorMessage()]);

            return null;
        } catch (Throwable $e) {
            Log::warning('Textract unreachable', ['error' => $e->getMessage()]);

            return null;
        }

        $lines = [];
        foreach ($result->get('Blocks') ?? [] as $block) {
            if (($block['BlockType'] ?? null) === 'LINE' && isset($block['Text'])) {
                $lines[] = $block['Text'];
            }
        }

        return $lines === [] ? null : implode("\n", $lines);
    }
}
