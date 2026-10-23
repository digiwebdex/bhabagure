<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use App\Services\Analytics\AnalyticsFilters;
use App\Services\Analytics\AnalyticsReports;
use App\Services\Analytics\AnalyticsSettings;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin → Visitor analytics (docs/visitor-analytics.md). Reading needs analytics.view, a CSV analytics.export, and the
 * settings analytics.manage (held by no role: the super admin's). Every answer is aggregated: no IP address, no
 * session id, no person.
 */
class AnalyticsController extends Controller
{
    public function __construct(private readonly AnalyticsReports $reports) {}

    public function summary(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->reports->summary(AnalyticsFilters::fromRequest($request))]);
    }

    public function breakdown(Request $request, string $dimension): JsonResponse
    {
        abort_unless(self::known($dimension), 404);
        // per_page: the map asks for every country at once.
        $paging = $request->validate(['page' => ['nullable', 'integer', 'min:1', 'max:1000'], 'per_page' => ['nullable', 'integer', 'between:1,500']]);

        return response()->json(['data' => $this->reports->breakdown(AnalyticsFilters::fromRequest($request), $dimension, (int) ($paging['page'] ?? 1), (int) ($paging['per_page'] ?? AnalyticsReports::PER_PAGE))]);
    }

    public function live(): JsonResponse
    {
        return response()->json(['data' => $this->reports->live()]);
    }

    /** A report as CSV, with the same filters as the screen: the daily trend, or any breakdown (up to 5,000 rows). */
    public function export(Request $request, AuditLogger $audit, string $report): StreamedResponse
    {
        abort_unless($report === 'daily' || self::known($report), 404);
        $filters = AnalyticsFilters::fromRequest($request);
        $audit->record('analytics.exported', $request->user('staff'), changes: ['report' => $report] + $filters->toArray());
        $name = "bhabaghure-analytics-{$report}-{$filters->from->toDateString()}-{$filters->to->toDateString()}.csv";

        return response()->streamDownload(function () use ($filters, $report) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\u{FEFF}");
            if ($report === 'daily') {
                fputcsv($out, ['Date', 'Visitors', 'Sessions', 'Page views']);
                foreach ($this->reports->series($filters) as $day) {
                    fputcsv($out, [$day['date'], $day['visitors'], $day['sessions'], $day['pageViews']]);
                }
            } else {
                $rows = $this->reports->breakdown($filters, $report, 1, 5000)['rows'];
                $headers = array_keys($rows[0] ?? ['label' => null, 'sessions' => null]);
                // "pageViews" → "Page views".
                fputcsv($out, array_map(fn (string $header) => ucfirst(mb_strtolower(preg_replace('/(?<!^)[A-Z]/', ' $0', $header) ?? $header)), array_values(array_diff($headers, ['key']))));
                foreach ($rows as $row) {
                    unset($row['key']);
                    // A cell starting = + - @ is text, not a formula, when the file is opened in a spreadsheet.
                    fputcsv($out, array_map(fn ($value) => is_string($value) && preg_match('/^[=+\-@]/', $value) ? "'".$value : $value, array_values($row)));
                }
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function settings(): JsonResponse
    {
        return response()->json(['data' => AnalyticsSettings::get()]);
    }

    public function updateSettings(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'retentionMonths' => ['required', 'integer', 'between:1,36'],
            'activeMinutes' => ['required', 'integer', 'between:1,30'],
            'sessionMinutes' => ['required', 'integer', 'between:5,240'],
            'excludedPaths' => ['present', 'array', 'max:30'],
            'excludedPaths.*' => ['string', 'max:120', 'starts_with:/', Rule::notIn(['/'])],
        ]);
        $value = AnalyticsSettings::normalize($data);
        SiteSetting::query()->updateOrCreate(['key' => AnalyticsSettings::SETTING], ['value' => $value, 'updated_by_staff_id' => $request->user('staff')->id]);
        $audit->record('analytics.settings_updated', $request->user('staff'), changes: $value);

        return response()->json(['data' => $value]);
    }

    private static function known(string $dimension): bool
    {
        return isset(AnalyticsReports::SESSION_DIMENSIONS[$dimension]) || in_array($dimension, AnalyticsReports::EVENT_DIMENSIONS, true);
    }
}
