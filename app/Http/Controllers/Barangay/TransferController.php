<?php

namespace App\Http\Controllers\Barangay;

use App\Models\EvacuationCenter;
use App\Models\Household;
use App\Models\ShelterTransfer;
use App\Services\TransferService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * PHASE 2 ITEM 8 -- Barangay Personnel's Shelter Transfers screen.
 *
 * Thin on purpose: validate, hand to TransferService, redirect. Every rule
 * about who may do what and what happens to occupancy lives in the service, so
 * this controller and its City Admin twin cannot drift apart.
 *
 * Separate from CityAdmin\TransferController for the same reason the shelter
 * screens are separate -- see the note at the top of routes/city.php. Neither
 * controller branches on role, because neither can be reached by another one.
 */
class TransferController extends BarangayController
{
    public function __construct(private TransferService $transfers)
    {
    }

    public function index(Request $request)
    {
        $user = $request->user();

        $filters = [
            'status' => $request->input('status', 'open'),
            'direction' => $request->input('direction'),
            'q' => $request->input('q'),
        ];

        $transfers = $this->transfers->listQuery($user, $filters)->paginate(15)->withQueryString();

        return view('barangay.transfers.index', [
            'transfers' => $transfers,
            'filters' => $filters,
            'centers' => $this->transfers->centerOptions(),
            'overdueMinutes' => ShelterTransfer::overdueMinutes(),
        ]);
    }

    /** Household picker inside the New Transfer modal. */
    public function searchHouseholds(Request $request)
    {
        $term = trim((string) $request->input('q'));

        return response()->json($this->transfers->searchHouseholds($term));
    }

    /** Arrival checklist for the Receive modal. */
    public function members(ShelterTransfer $transfer)
    {
        abort_if(! $transfer->canBeReceivedBy(auth()->user()), 403,
            'Only the destination shelter can receive this transfer.');

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

        $this->transfers->request($household, $destination, $request->user(), $data['reason'] ?? null);

        return redirect()->route('barangay.transfers.index')
            ->with('success', "Transfer requested. {$destination->name} has been asked to confirm.");
    }

    public function confirm(ShelterTransfer $transfer, Request $request)
    {
        $this->transfers->confirm($transfer, $request->user());

        return back()->with('success', 'Transfer confirmed. The origin shelter can now record the departure.');
    }

    public function refuse(ShelterTransfer $transfer, Request $request)
    {
        $data = $request->validate([
            'refusal_reason' => ['required', 'string', 'max:255'],
        ], [
            'refusal_reason.required' => 'Give a reason so the origin shelter knows what to do next.',
        ]);

        $this->transfers->refuse($transfer, $request->user(), $data['refusal_reason']);

        return back()->with('success', 'Transfer refused. City Admin has been alerted.');
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

        /* DROP 2. Point at the panel rather than interrupting with a modal.

           A modal here would be a ONE-SHOT decision at the worst possible
           moment: staff are mid-arrival, possibly with a queue behind them, and
           once dismissed there would be no second chance -- both households are
           now at the same shelter, so no future transfer would ever raise it
           again. "Not now" would silently mean "never".

           The panel on Evacuee Profiling is always there, so declining costs
           nothing and needs no stored decision. */
        $msg = 'Arrival recorded. Both shelter headcounts have been updated.';

        if ($transfer->household?->fresh()?->is_separated) {
            $msg .= ' This household is marked separated from their family -- you can reunite'
                . ' the records from the Separated Households panel on Evacuee Profiling.';
        }

        return back()->with('success', $msg);
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

    public function cancel(ShelterTransfer $transfer, Request $request)
    {
        $data = $request->validate([
            'cancellation_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $this->transfers->cancel($transfer, $request->user(), $data['cancellation_reason'] ?? null);

        return back()->with('success', 'Transfer cancelled. The household stays checked in at the origin shelter.');
    }
}
