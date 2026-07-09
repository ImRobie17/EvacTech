<?php

namespace App\Http\Controllers\Barangay;

use App\Models\Household;
use App\Models\HouseholdTransfer;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShelterController extends BarangayController
{
    public function index(Request $request)
    {
        $center = $this->center();

        $households = collect();
        if ($center) {
            $query = Household::with(['headMember'])
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
                    \App\Models\HouseholdMember::select('full_name')
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

        return view('barangay.shelter.index', compact('center', 'households', 'recent'));
    }

    /** Check in an existing (already registered) household. */
    public function checkIn(Request $request, Household $household)
    {
        abort_if($household->origin_barangay_id !== auth()->user()->barangay_id, 403);
        $center = $this->centerOrFail();

        $data = $request->validate([
            'present' => ['required', 'array', 'min:1'],
            'present.*' => ['integer'],
        ]);

        if ($household->status === 'checked_in') {
            return back()->withErrors(['household' => "This household is already checked in at {$household->evacuationCenter?->name}. Use Transfer instead."]);
        }

        DB::transaction(function () use ($household, $center, $data) {
            $household->members()->update(['is_present' => false]);
            $household->members()->whereIn('id', $data['present'])->update(['is_present' => true]);
            $present = count($data['present']);

            $household->update([
                'evacuation_center_id' => $center->id,
                'status' => 'checked_in',
                'checked_in_at' => now(),
                'checked_out_at' => null,
                'members_present' => $present,
            ]);

            $center->increment('current_occupancy', $present);
            $this->refreshCenterStatus($center);
        });

        AuditLogger::log('updated', $household, "Checked in household {$household->household_code} ({$household->members_present} present)");

        return redirect()->route('barangay.shelter.index')->with('success', "Household {$household->household_code} checked in.");
    }

    public function checkOut(Household $household)
    {
        abort_if($household->origin_barangay_id !== auth()->user()->barangay_id, 403);

        if ($household->status !== 'checked_in') {
            return back()->withErrors(['household' => 'This household is not currently checked in.']);
        }

        $center = $household->evacuationCenter;

        DB::transaction(function () use ($household, $center) {
            $center?->decrement('current_occupancy', min($household->members_present, $center->current_occupancy));
            $household->members()->update(['is_present' => false]);
            $household->update([
                'status' => 'checked_out',
                'checked_out_at' => now(),
                'members_present' => 0,
            ]);
            if ($center) {
                $this->refreshCenterStatus($center);
            }
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
        abort_if($household->origin_barangay_id !== auth()->user()->barangay_id, 403);

        $data = $request->validate([
            'new_head_member_id' => ['required', 'integer'],
            'confirmation' => ['required', 'in:transfer,Transfer,TRANSFER'],
        ], [
            'confirmation.in' => 'Type "transfer" exactly to confirm.',
        ]);

        $newHead = $household->members()->whereKey($data['new_head_member_id'])->firstOrFail();

        DB::transaction(function () use ($household, $newHead) {
            $household->members()->update(['is_household_head' => false, 'family_role' => 'member']);
            $newHead->update(['is_household_head' => true, 'family_role' => 'head']);
            $household->update(['head_member_id' => $newHead->id]);

            HouseholdTransfer::create([
                'household_id' => $household->id,
                'from_center_id' => $household->evacuation_center_id,
                'to_center_id' => $household->evacuation_center_id ?? $this->centerOrFail()->id,
                'new_head_member_id' => $newHead->id,
                'reason' => 'Family head transfer',
                'transferred_by' => auth()->id(),
                'transferred_at' => now(),
            ]);
        });

        AuditLogger::log('updated', $household, "Transferred head of {$household->household_code} to {$newHead->full_name}");

        return back()->with('success', "Family head transferred to {$newHead->full_name}.");
    }

    protected function refreshCenterStatus($center): void
    {
        if ($center->capacity > 0 && $center->current_occupancy >= $center->capacity) {
            $center->update(['status' => 'full']);
        } elseif ($center->status === 'full') {
            $center->update(['status' => 'active']);
        }
    }

    private function recentActivity($center)
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
