<?php

namespace App\Services\Inbox;

use App\Models\ConversationMessage;
use App\Services\Documents\TravellerDocuments;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Photos, documents and voice notes in the inbox (docs/admin-inbox.md): customers send passports and payment slips, so
 * files are encrypted on the private disk like traveller documents. Staff open them through the admin; WhatsApp and
 * Messenger fetch a staff attachment once through a short-lived signed link.
 */
final class InboxFiles
{
    /** What staff may attach: photos, PDFs and voice notes / audio. */
    public const MIMES = ['jpg', 'jpeg', 'png', 'webp', 'pdf', 'mp3', 'ogg', 'm4a', 'aac', 'mp4'];

    public static function store(string $bytes): string
    {
        $path = sprintf('inbox/%s/%s.enc', now('Asia/Dhaka')->format('Y/m'), Str::uuid());
        Storage::disk(TravellerDocuments::DISK)->put($path, Crypt::encryptString($bytes));

        return $path;
    }

    public static function contents(ConversationMessage $message): string
    {
        return Crypt::decryptString((string) Storage::disk(TravellerDocuments::DISK)->get((string) $message->attachment_path));
    }

    public static function delete(?string $path): void
    {
        if ($path !== null) {
            Storage::disk(TravellerDocuments::DISK)->delete($path);
        }
    }

    /** The link WhatsApp or Messenger fetches a staff attachment from: valid for 30 minutes. */
    public static function signedUrl(ConversationMessage $message): string
    {
        $name = $message->attachment_name ?: 'file';

        // Signed over the path only: behind Cloudflare and nginx the scheme and host the API sees may differ from APP_URL.
        $path = URL::temporarySignedRoute('inbox.file', now()->addMinutes(30), ['message' => $message->id, 'name' => Str::slug(pathinfo($name, PATHINFO_FILENAME)).'.'.(pathinfo($name, PATHINFO_EXTENSION) ?: 'bin')], absolute: false);

        return rtrim((string) config('app.url'), '/').$path;
    }

    /** image | video | audio | document, from a MIME type. */
    public static function kind(string $mime): string
    {
        return match (true) {
            str_starts_with($mime, 'image/') => 'image',
            str_starts_with($mime, 'video/') => 'video',
            str_starts_with($mime, 'audio/') => 'audio',
            default => 'document',
        };
    }
}
