<?php

namespace App\Services\Inbox;

use App\Models\Conversation;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Exception\RuntimeException as ProcessRuntimeException;

/**
 * A voice note recorded in the reply box, made into what the customer's app plays (docs/admin-inbox.md §9). Browsers
 * record different formats (Chrome and Edge WebM, Firefox Ogg or WebM, Safari MP4) and WaSender doesn't list WebM, so
 * every recording is converted rather than sent as it came.
 */
final class VoiceNotes
{
    /** What each channel gets: WhatsApp's own voice note format (one channel of Opus in Ogg), and AAC in M4A for Messenger. */
    private const OUTPUTS = [
        Conversation::WHATSAPP => ['extension' => 'ogg', 'mime' => 'audio/ogg', 'encode' => ['-c:a', 'libopus', '-b:a', '32k', '-ar', '48000', '-f', 'ogg']],
        Conversation::MESSENGER => ['extension' => 'm4a', 'mime' => 'audio/mp4', 'encode' => ['-c:a', 'aac', '-b:a', '64k', '-ar', '44100', '-movflags', '+faststart', '-f', 'ipod']],
    ];

    /** A browser's timer can run a moment past the limit; only a recording sent around the recorder is cut here. */
    private const GRACE_SECONDS = 5;

    /**
     * @return array{bytes: string, mime: string, name: string, size: int}
     *
     * @throws ValidationException when it isn't a recording, or can't be converted
     */
    public function convert(string $recording, string $channel): array
    {
        $container = self::containerOf($recording);
        if ($container === null) {
            throw self::refused();
        }
        $output = self::OUTPUTS[$channel];
        $target = (string) tempnam(sys_get_temp_dir(), 'voice');

        try {
            $result = Process::timeout(45)->run([
                (string) config('bhabaghure.notifications.inbox.ffmpeg'),
                '-nostdin', '-hide_banner', '-loglevel', 'error',
                // The file itself and nothing it might point at, read as the container its first bytes say it is.
                '-protocol_whitelist', 'file', '-f', $container, '-i', $recording,
                '-map', '0:a:0', '-map_metadata', '-1', '-threads', '1',
                '-t', (string) ((int) config('bhabaghure.notifications.inbox.voice_note_max_seconds') + self::GRACE_SECONDS),
                '-ac', '1', '-avoid_negative_ts', 'make_zero',
                ...$output['encode'],
                '-y', $target,
            ]);
            $bytes = $result->successful() ? (string) file_get_contents($target) : '';
            if ($bytes === '') {
                Log::warning('Inbox voice note not converted', ['channel' => $channel, 'container' => $container, 'exit' => $result->exitCode(), 'error' => Str::limit($result->errorOutput(), 500)]);
                throw self::refused();
            }
        } catch (ProcessRuntimeException $e) {
            // ffmpeg missing from the server, or taking too long.
            Log::warning('Inbox voice note not converted', ['channel' => $channel, 'container' => $container, 'error' => Str::limit($e->getMessage(), 500)]);
            throw self::refused();
        } finally {
            @unlink($target);
        }

        return [
            'bytes' => $bytes,
            'mime' => $output['mime'],
            'name' => 'voice-note-'.now('Asia/Dhaka')->format('Ymd-His').'.'.$output['extension'],
            'size' => strlen($bytes),
        ];
    }

    /** The container by its first bytes, never the file's name or what the browser called it; null for anything else. */
    private static function containerOf(string $path): ?string
    {
        $head = (string) file_get_contents($path, false, null, 0, 12);

        return match (true) {
            str_starts_with($head, "\x1A\x45\xDF\xA3") => 'matroska', // WebM
            str_starts_with($head, 'OggS') => 'ogg',
            substr($head, 4, 4) === 'ftyp' => 'mov', // MP4 and M4A
            default => null,
        };
    }

    private static function refused(): ValidationException
    {
        return ValidationException::withMessages(['audio' => [__('inbox.voice_note_unreadable')]]);
    }
}
