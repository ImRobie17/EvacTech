<?php

namespace App\Http\Controllers\Barangay;

use App\Models\EvacuationCenter;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\HouseholdTransfer;
use App\Models\ShelterTransfer;
use App\Services\AuditLogger;
use App\Services\PresenceService;
use App\Services\TransferService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShelterController extends BarangayController
{
    public function index(Request $request, ?EvacuationCenter $center = null)
    {
        $center = $this->center($center);

        $households = collect();
        if ($center) {
            $query = Household::with(['headMember', 'originBarangay'])
                ->where('evacuation_center_id', $center->id);

            if ($search = trim((string) $request->input('q'))) {
                $query->whereHas('members', fn ($q) => $q
                    ->where('is_household_head', true)
                    ->where('full_name', 'like', "%{$search}%"));
            }

            if ($status = $request->input('status')) {
                $query->where('status', $status);
            }

            $sort = $request->input('sort', 'recent');
            if ($sort === 'name') {
                $query->orderBy(
                    HouseholdMember::select('full_name')
                        ->whereColumn('household_members.household_id', 'households.id')
                        ->where('is_household_head', true)
                        ->limit(1)
                );
            } else {
                $query->latest('checked_in_at');
            }

            $households = $query->paginate(15)->withQueryString();
        }

        $recent = $this->recentActivity($center);

        // PHASE 2 ITEM 8: destinations for the "Move to Shelter" modal. The full
        // active list goes to the browser and transfers.js filters out whichever
        // shelter the family is currently in.
        $transferCenters = app(TransferService::class)->centerOptions();

        // ONE query for the whole page rather than an openTransfer() call per
        // row, so the table can show "Transfer in progress" instead of offering
        // a button the service would only reject.
        $openTransferHouseholdIds = ShelterTransfer::whereIn('household_id', $households->pluck('id'))
            ->open()
            ->pluck('household_id')
            ->all();

        // PHASE 5 ITEM 8b: people this shelter has not accounted for after a
        // transfer. Derived on read, like everything else here.
        $unaccounted = $center
            ? app(TransferService::class)->unaccountedCountFor($center)
            : 0;

        return view('barangay.shelter.index', compact(
            'center', 'households', 'recent', 'transferCenters', 'openTransferHouseholdIds', 'unaccounted'
        ));
    }

    /**
     * Check in an existing (already registered) household.
     *
     * The household is placed in whichever shelter the actor is operating: the
     * barangay staff member's active shelter, or (for City Admin) the shelter
     * passed as ?center=. Authorisation follows the shelter, not the staff
     * member's barangay -- see ResolvesCenter::canManageHousehold.
     */
    public function checkIn(Request $request, Household $household)
    {
        $this->authorizeHousehold($household);
        $center = $this->centerOrFail();

        $data = $request->validate([
            'present' => ['required', 'array', 'min:1'],
            'present.*' => ['integer'],
        ]);

        if ($household->status === 'checked_in') {
            return back()->withErrors([
                'household' => "This household is already checked in at {$household->evacuationCenter?->name}. Use Transfer instead.",
            ]);
        }

        $previousCenter = $household->evacuationCenter;

        DB::transaction(function () use ($household, $center, $previousCenter, $data) {
            $household->members()->update(['is_present' => false]);
            $household->members()->whereIn('id', $data['present'])->update(['is_present' => true]);
            $present = $household->members()->where('is_present', true)->count();

            $household->update([
                'evacuation_center_id' => $center->id,
                'status' => 'checked_in',
                'checked_in_at' => now(),
                'checked_out_at' => null,
                'members_present' => $present,
            ]);

            // Derived, not incremented. If the household came from another
            // shelter, that shelter's count is corrected too.
            $center->recalcOccupancy();
            if ($previousCenter && $previousCenter->id !== $center->id) {
                $previousCenter->recalcOccupancy();
            }
        });

        $household->refresh();

        AuditLogger::log('updated', $household,
            "Checked in household {$household->household_code} at {$center->name} ({$household->members_present} present)");

        return $this->backToShelter($center, "Household {$household->household_code} checked in.");
    }

    /**
     * PHASE 5 ITEM 8b -- the tick list for the Update Presence modal.
     *
     * Returns the reason the household cannot be corrected rather than aborting,
     * so the modal can explain itself instead of showing a form the server would
     * only reject. PresenceService::update() re-checks the same conditions under
     * a lock, so this is presentation, never the guard.
     */
    public function presence(Household $household)
    {
        $this->authorizeHousehold($household);

        $service = app(PresenceService::class);

        return response()->json([
            'id' => $household->id,
            'code' => $household->household_code,
            'head' => $household->headMember?->full_name,
            'center' => $household->evacuationCenter?->name,
            'present' => (int) $household->members_present,
            'total' => $household->members()->count(),
            'blocked' => $service->blockedReason($household, auth()->user()),
            'members' => $service->checklist($household),
        ]);
    }

    /** PHASE 5 ITEM 8b -- write the corrected presence. */
    public function updatePresence(Request $request, Household $household)
    {
        $this->authorizeHousehold($household);

        $data = $request->validate([
            'present' => ['required', 'array', 'min:1'],
            'present.*' => ['integer'],
        ], [
            'present.required' => 'Tick at least one person who is present at the shelter.',
        ]);

        app(PresenceService::class)->update($household, $data['present'], $request->user());

        $household->refresh();

        return back()->with('success',
            "Presence updated. {$household->household_code} now has {$household->members_present} present.");
    }

    public function checkOut(Household $household)
    {
        // FIX: previously compared $household->origin_barangay_id against
        // auth()->user()->barangay_id. City Admin has no barangay_id, so this
        // always aborted 403 -- the "City Admin check-out gives 403" bug.
        $this->authorizeHousehold($household);

        if ($household->status !== 'checked_in') {
            return back()->withErrors(['household' => 'This household is not currently checked in.']);
        }

        // PHASE 2 ITEM 8: a family committed to a shelter transfer must not be
        // checked out from underneath it. The transfer counts on finding them
        // still checked in at the origin -- that is the whole basis of the
        // occupancy design -- so receiving one that had been checked out would
        // resurrect a household nobody had counted.
        if ($open = $household->openTransfer()) {
            return back()->withErrors([
                'household' => "This household has a shelter transfer in progress ({$open->statusLabel()}). Cancel or complete the transfer first.",
            ]);
        }

        $center = $household->evacuationCenter;

        DB::transaction(function () use ($household, $center) {
            $household->members()->update(['is_present' => false]);
            $household->update([
                'status' => 'checked_out',
                'checked_out_at' => now(),
                'members_present' => 0,
            ]);
            $center?->recalcOccupancy();
        });

        AuditLogger::log('updated', $household, "Checked out household {$household->household_code}");

        return back()->with('success', "Household {$household->household_code} checked out.");
    }

    /**
     * Transfer household head role. Requires typing "transfer" to confirm
     * (Confirm Transfer modal). Records to household_transfers.
     */
    public function transferHead(Request $request, Household $household)
    {
        $this->authorizeHousehold($household);

        $data = $request->validate([
            'new_head_member_id' => ['required', 'integer'],
            'confirmation' => ['required', 'in:transfer,Transfer,TRANSFER'],
        ], [
            'confirmation.in' => 'Type "transfer" exactly to confirm.',
        ]);

        $newHead = $household->members()->whereKey($data['new_head_member_id'])->firstOrFail();

        // A head transfer is not a shelter move: from and to are the same place.
        // Shelter-to-shelter movement gets its own OUT/IN record in Phase 2 #8.
        $centerId = $household->evacuation_center_id ?? $this->centerOrFail()->id;

        DB::transaction(function () use ($household, $newHead, $centerId) {
            $household->members()->update(['is_household_head' => false, 'family_role' => 'member']);
            $newHead->update(['is_household_head' => true, 'family_role' => 'head']);
            $household->update(['head_member_id' => $newHead->id]);

            HouseholdTransfer::create([
                'household_id' => $household->id,
                'from_center_id' => $centerId,
                'to_center_id' => $centerId,
                'new_head_member_id' => $newHead->id,
                'reason' => 'Family head transfer',
                'transferred_by' => auth()->id(),
                'transferred_at' => now(),
            ]);
        });

        AuditLogger::log('updated', $household,
            "Transferred head of {$household->household_code} to {$newHead->full_name}");

        $message = "Family head transferred to {$newHead->full_name}.";

        /* PHASE 6. Confirming a head transfer used to navigate, which closed the
           Edit Family modal it was launched from and dropped the operator back
           on the list. Answering JSON to an XHR lets the browser stay where it
           is, so the transfer completes and editing continues in the same modal.

           The redirect below is kept for a non-XHR post -- a submit with
           JavaScript unavailable still works exactly as it did. This is an
           ADDITIONAL response shape, not a replacement. */
        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'message' => $message,
                'household_id' => $household->id,
                'new_head_member_id' => $newHead->id,
                'new_head_name' => $newHead->full_name,
            ]);
        }

        return back()->with('success', $message);
    }

    /**
     * Barangay-only screen, so there is exactly one destination. City Admin has
     * its own detail page and never reaches this controller.
     */
    private function backToShelter(EvacuationCenter $center, string $message)
    {
        return redirect()->route('barangay.shelter.index')->with('success', $message);
    }

    private function recentActivity(?EvacuationCenter $center)
    {
        if (! $center) {
            return collect();
        }

        return Household::with('headMember')
            ->where('evacuation_center_id', $center->id)
            ->where(fn ($q) => $q->whereNotNull('checked_in_at')->orWhereNotNull('checked_out_at'))
            ->get()
            ->flatMap(function ($h) {
                $events = [];
                if ($h->checked_in_at) {
                    $events[] = ['type' => 'check_in', 'household' => $h, 'at' => $h->checked_in_at];
                }
                if ($h->checked_out_at) {
                    $events[] = ['type' => 'check_out', 'household' => $h, 'at' => $h->checked_out_at];
                }

                return $events;
            })
            ->sortByDesc('at')
            ->take(5);
    }
}
