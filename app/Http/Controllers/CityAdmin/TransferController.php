<?php

namespace App\Http\Controllers\CityAdmin;

use App\Http\Controllers\Controller;
use App\Models\EvacuationCenter;
use App\Models\Household;
use App\Models\ShelterTransfer;
use App\Services\TransferService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * PHASE 2 ITEM 8 -- City Admin's city-wide Shelter Transfers screen.
 *
 * Same service, same state machine, its own views. City Admin sees every
 * transfer between every shelter and can act at both ends, because
 * User::canAccessCenter() returns true for any non-barangay role.
 *
 * The one power that is City-Admin-only: cancelling a transfer that is already
 * in transit. See ShelterTransfer::canBeCancelledBy().
 */
class TransferController extends Controller
{
    public function __construct(private TransferService $transfers)
    {
    }

    public function index(Request $request)
    {
        $filters = [
            'status' => $request->input('status', 'open'),
            'direction' => null, // Meaningless city-wide: every shelter is "ours".
            'q' => $request->input('q'),
        ];

        $transfers = $this->transfers->listQuery($request->user(), $filters)->paginate(15)->withQueryString();

        return view('cityadmin.transfers.index', [
            'transfers' => $transfers,
            'filters' => $filters,
            'centers' => $this->transfers->centerOptions(),
            'overdueMinutes' => ShelterTransfer::overdueMinutes(),
        ]);
    }

    public function searchHouseholds(Request $request)
    {
        $term = trim((string) $request->input('q'));

        return response()->json($this->transfers->searchHouseholds($term));
    }

    public function members(ShelterTransfer $transfer)
    {
        abort_if(! $transfer->canBeReceivedBy(auth()->user()), 403,
            'This transfer is not in transit.');

        return response()->json([
            'code' => $transfer->household?->household_code,
            'head' => $transfer->household?->headMember?->full_name,
            'expected' => (int) $transfer->members_expected,
            'members' => $this->transfers->arrivalChecklist($transfer),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'household_id' => ['required', 'integer'],
            'to_center_id' => ['required', 'integer'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $household = Household::findOrFail($data['household_id']);
        $destination = EvacuationCenter::findOrFail($data['to_center_id']);

        /* PHASE 8 ITEM 4 -- a transfer City Admin raises is approved on the spot.
           
           The pending state exists to collect City Admin's decision. When City
           Admin is the one asking, that decision has already been made, and
           parking the request in a queue for its own author to approve is
           ceremony that delays a family standing in a shelter doorway.
           
           BOTH calls go through TransferService, not around it. That matters
           more than the saved click: the service owns every transition, the
           row lock, and the two occupancy recalculations, and it writes its own
           audit entry per step. So the trail still reads request THEN approval,
           as two rows -- exactly as it would if a human had confirmed it -- and
           the $auto flag makes the second row say why no separate review
           happened. Reproducing either step inline here would be the start of a
           second lifecycle implementation. */
        $transfer = $this->transfers->request($household, $destination, $request->user(), $data['reason'] ?? null);
        $this->transfers->confirm($transfer, $request->user(), true);

        /* The ORIGIN records departure -- canBeDepartedBy() checks
           from_center_id -- so the next action belongs to the shelter the family
           is leaving, not the one they are going to. */
        return redirect()->route('city.transfers.index')->with(
            'success',
            sprintf(
                'Transfer to %s approved. %s can now record the family leaving.',
                $destination->name,
                $transfer->fromCenter?->name ?? 'The origin shelter'
            )
        );
    }

    public function confirm(ShelterTransfer $transfer, Request $request)
    {
        $this->transfers->confirm($transfer, $request->user());

        return back()->with('success', 'Transfer confirmed on behalf of the destination shelter.');
    }

    public function refuse(ShelterTransfer $transfer, Request $request)
    {
        $data = $request->validate([
            'refusal_reason' => ['required', 'string', 'max:255'],
        ]);

        $this->transfers->refuse($transfer, $request->user(), $data['refusal_reason']);

        return back()->with('success', 'Transfer refused.');
    }

    public function depart(ShelterTransfer $transfer, Request $request)
    {
        $this->transfers->depart($transfer, $request->user());

        return back()->with('success', 'Departure recorded. The family is now in transit.');
    }

    public function receive(ShelterTransfer $transfer, Request $request)
    {
        // PHASE 5 ITEM 8b: reasons[<member_id>] -- ONE bracket pair. PHP closes a
        // form key at the first ], so a nested name like reasons[<id>[]] would
        // arrive as the literal string key "reasons[" and nothing would ever
        // reach the server. That shipped once already and cost a whole phase.
        $data = $request->validate([
            'present' => ['required', 'array', 'min:1'],
            'present.*' => ['integer'],
            'reasons' => ['nullable', 'array'],
            'reasons.*' => ['string', Rule::in(array_keys(ShelterTransfer::ABSENCE_REASONS))],
        ], [
            'present.required' => 'Tick at least one person who arrived at the shelter.',
            'reasons.*.in' => 'Choose a valid reason for anyone who did not arrive.',
        ]);

        $this->transfers->receive(
            $transfer,
            $request->user(),
            $data['present'],
            $data['reasons'] ?? []
        );

        return back()->with('success', 'Arrival recorded. Both shelter headcounts have been updated.');
    }

    /**
     * PHASE 5 ITEM 8b -- record what happened to someone who did not arrive.
     *
     * "Arrived" changes the headcount through PresenceService. The other two
     * change NO counts at all: the person was already absent from
     * members_present and from occupancy, and the only thing that changes is
     * that the system stops asking.
     */
    public function resolveAbsence(ShelterTransfer $transfer, Request $request)
    {
        $data = $request->validate([
            'member_id' => ['required', 'integer'],
            'resolution' => ['required', 'string', Rule::in(array_merge(
                [ShelterTransfer::RESOLUTION_ARRIVED],
                array_keys(ShelterTransfer::RECORDED_RESOLUTIONS)
            ))],
        ], [
            'resolution.required' => 'Choose what happened to this person.',
        ]);

        $this->transfers->resolveAbsence(
            $transfer,
            $request->user(),
            (int) $data['member_id'],
            $data['resolution']
        );

        return back()->with('success', $data['resolution'] === ShelterTransfer::RESOLUTION_ARRIVED
            ? 'Marked as arrived. The shelter headcount has been updated.'
            : 'Recorded. This does not change any headcount.');
    }

    /**
     * A reason is REQUIRED once the family has departed. Closing the record on
     * people who left one shelter and never reached another is exactly the
     * event a panelist will ask about, so it does not get to be silent.
     */
    public function cancel(ShelterTransfer $transfer, Request $request)
    {
        $rules = $transfer->status === ShelterTransfer::IN_TRANSIT
            ? ['cancellation_reason' => ['required', 'string', 'max:255']]
            : ['cancellation_reason' => ['nullable', 'string', 'max:255']];

        $data = $request->validate($rules, [
            'cancellation_reason.required' => 'Record what happened to this family before closing the transfer.',
        ]);

        $this->transfers->cancel($transfer, $request->user(), $data['cancellation_reason'] ?? null);

        return back()->with('success', 'Transfer cancelled. The household stays checked in at the origin shelter.');
    }
}
