<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Customer;
use App\Support\Phone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /admin/search?q= — the header search (docs/phase-5-admin-core.md §4.1): the top five of each kind the staff
 * member may see, through the same visibility scopes as the lists. Kinds without permission are left out entirely.
 */
class SearchController extends Controller
{
    private const LIMIT = 5;

    public function __invoke(Request $request): JsonResponse
    {
        $q = trim((string) $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']])['q']);
        $staff = $request->user('staff');
        $like = '%'.addcslashes($q, '%_\\').'%';
        // "01711-000001" or "+880 1711…" finds 8801711000001.
        $phone = Phone::normalizeBdMobile($q) ?? (preg_match('/^\+?[\d\s-]{4,}$/', $q) === 1 ? preg_replace('/\D/', '', $q) : null);
        $phoneLike = $phone !== null ? '%'.preg_replace('/^0/', '', $phone).'%' : null;

        $results = [];

        if (Booking::seesAll($staff) || Booking::seesOwn($staff)) {
            $results['bookings'] = Booking::query()->visibleTo($staff)->with('customer')
                ->where(fn (Builder $w) => $w->where('reference', 'like', $like)
                    ->orWhereHas('customer', fn (Builder $c) => $c->where('name', 'like', $like)
                        ->when($phoneLike, fn (Builder $p) => $p->orWhere('phone', 'like', $phoneLike))))
                ->latest('id')->limit(self::LIMIT)->get()
                ->map(fn (Booking $b) => ['id' => $b->id, 'reference' => $b->reference, 'status' => $b->status->value, 'customer' => $b->customer?->name]);
        }

        if (Customer::seesOwn($staff)) {
            $results['customers'] = Customer::query()->visibleTo($staff)
                ->where(fn (Builder $w) => $w->where('name', 'like', $like)->orWhere('email', 'like', $like)
                    ->when($phoneLike, fn (Builder $p) => $p->orWhere('phone', 'like', $phoneLike)))
                ->latest('id')->limit(self::LIMIT)->get()
                ->map(fn (Customer $c) => ['id' => $c->id, 'name' => $c->name, 'phone' => $c->phone, 'stage' => $c->stage]);
        }

        return response()->json(['data' => $results]);
    }
}
