<?php

namespace App\Support;

/**
 * The release deployed: the repository's commit, which the website and the API are built from together
 * (deploy/deploy.sh). A stored PDF made from the website's design is made again after a release (docs/tour-landing-pages.md §7).
 */
final class Release
{
    public static function id(): string
    {
        $configured = config('bhabaghure.release');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $git = base_path('../.git');
        $head = @file_get_contents("{$git}/HEAD");
        if ($head === false) {
            return 'unknown';
        }
        $head = trim($head);
        if (! str_starts_with($head, 'ref: ')) {
            return $head;
        }
        $ref = substr($head, 5);
        $hash = @file_get_contents("{$git}/{$ref}");
        if ($hash !== false) {
            return trim($hash);
        }
        // A packed ref: "<hash> refs/heads/main".
        foreach (@file("{$git}/packed-refs") ?: [] as $line) {
            if (str_ends_with(trim($line), " {$ref}")) {
                return strtok(trim($line), ' ');
            }
        }

        return 'unknown';
    }
}
