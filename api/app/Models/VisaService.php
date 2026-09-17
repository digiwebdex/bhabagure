<?php

namespace App\Models;

use App\Enums\ContentStatus;
use App\Models\Concerns\HasLocalizedFields;
use App\Models\Concerns\HasPublicationStatus;
use Illuminate\Database\Eloquent\Model;

/** A visa the agency processes: one country and visa type (docs/phase-8-visa-quotes-pricing-downloads.md §4.C). */
class VisaService extends Model
{
    use HasLocalizedFields, HasPublicationStatus;

    protected $fillable = [
        'slug', 'country_code', 'country_bn', 'country_en', 'visa_type_bn', 'visa_type_en', 'price', 'processing_bn', 'processing_en',
        'stay_bn', 'stay_en', 'requirements_bn', 'requirements_en', 'notes_bn', 'notes_en', 'status', 'sort_order',
    ];

    protected function casts(): array
    {
        return ['status' => ContentStatus::class, 'sort_order' => 'integer', 'price' => 'decimal:2'];
    }

    /** @return list<string> one requirement per non-blank line */
    public static function lines(?string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) $text) ?: []), fn (string $line) => $line !== ''));
    }

    /**
     * Requirements in groups: a line ending in a colon — "For business person:" — heads the lines under it, and any lines
     * before the first heading form a group with none. A heading with nothing under it is dropped. The website reads the
     * same rule from the same lines (web/src/lib/visa-requirements.ts).
     *
     * @param  list<string>  $lines
     * @return list<array{heading: ?string, items: list<string>}>
     */
    public static function groups(array $lines): array
    {
        $groups = [['heading' => null, 'items' => []]];
        foreach ($lines as $line) {
            if (preg_match('/^(.*\S)\s*[:：]$/u', $line, $heading) === 1) {
                $groups[] = ['heading' => $heading[1], 'items' => []];

                continue;
            }
            $groups[array_key_last($groups)]['items'][] = $line;
        }

        return array_values(array_filter($groups, fn (array $group) => $group['items'] !== []));
    }

    /** @return array{bn: list<string>, en: list<string>} each language falls back to the other when it has none */
    public function requirementLists(): array
    {
        $bn = self::lines($this->requirements_bn);
        $en = self::lines($this->requirements_en);

        return ['bn' => $bn ?: $en, 'en' => $en ?: $bn];
    }
}
