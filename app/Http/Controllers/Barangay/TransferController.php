<?php

namespace App\Http\Controllers\Barangay;

use App\Models\EvacuationCenter;
use App\Models\Household;
use App\Models\ShelterTransfer;
use App\Services\TransferService;
use Illuminate\Http\Request;

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
        $data = $request->validate([
            'present' => ['required', 'array', 'min:1'],
            'present.*' => ['integer'],
        ], [
            'present.required' => 'Tick at least one person who arrived at the shelter.',
        ]);

        $this->transfers->receive($transfer, $request->user(), $data['present']);

        return back()->with('success', 'Arrival recorded. Both shelter headcounts have been updated.');
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
