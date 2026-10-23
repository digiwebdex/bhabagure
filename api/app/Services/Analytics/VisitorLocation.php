<?php

namespace App\Services\Analytics;

use Illuminate\Http\Request;

/**
 * Where a visitor is, approximately (docs/visitor-analytics.md): from the location Cloudflare adds to each request —
 * country always; region, city and time zone once "Add visitor location headers" is switched on in Cloudflare. Read
 * only when nginx says the request really came through Cloudflare (BH_FROM_CLOUDFLARE, set from the connecting
 * address, which a visitor can't fake), so a direct request with made-up headers counts as unknown. Never an address,
 * and never the latitude, longitude or postcode Cloudflare also sends.
 */
final class VisitorLocation
{
    /** @return array{country_code: ?string, continent: ?string, region: ?string, city: ?string, timezone: ?string} */
    public static function fromRequest(Request $request): array
    {
        $unknown = ['country_code' => null, 'continent' => null, 'region' => null, 'city' => null, 'timezone' => null];
        if ((string) $request->server('BH_FROM_CLOUDFLARE') !== '1') {
            return $unknown;
        }
        $header = fn (string $name, int $max) => ($value = trim((string) $request->header($name))) !== '' ? mb_substr(self::decode($value), 0, $max) : null;
        $country = strtoupper((string) $request->header('CF-IPCountry'));

        return [
            // XX: unknown · T1: Tor — neither is a country.
            'country_code' => preg_match('/^[A-Z]{2}$/', $country) && ! in_array($country, ['XX', 'T1'], true) ? $country : null,
            'continent' => preg_match('/^[A-Z]{2}$/', $c = strtoupper((string) $request->header('CF-IPContinent'))) ? $c : null,
            'region' => $header('CF-Region', 100),
            'city' => $header('CF-IPCity', 100),
            'timezone' => preg_match('#^[A-Za-z_]+(/[A-Za-z0-9_+\-]+){0,2}$#', $tz = (string) $request->header('CF-Timezone')) ? $tz : null,
        ];
    }

    /** Cloudflare sends non-ASCII names percent-encoded in some setups ("S%C3%A3o Paulo"). */
    private static function decode(string $value): string
    {
        $decoded = rawurldecode($value);

        return mb_check_encoding($decoded, 'UTF-8') ? strip_tags($decoded) : strip_tags($value);
    }
}
