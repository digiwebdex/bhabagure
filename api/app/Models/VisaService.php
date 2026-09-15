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

    /** @return array{bn: list<string>, en: list<string>} each language falls back to the other when it has none */
    public function requirementLists(): array
    {
        $bn = self::lines($this->requirements_bn);
        $en = self::lines($this->requirements_en);

        return ['bn' => $bn ?: $en, 'en' => $en ?: $bn];
    }
}
