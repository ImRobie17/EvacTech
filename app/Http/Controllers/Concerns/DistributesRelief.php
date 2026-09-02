<?php

namespace App\Http\Controllers\Concerns;

use App\Models\EvacuationCenter;
use App\Models\Household;
use App\Models\ReliefGood;
use App\Models\ReliefInventory;
use App\Models\ReliefTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * DROP B2 -- everything the two distribute paths have in common.
 *
 * WHY THIS EXISTS. There are exactly two:
 *
 *   Barangay\ReliefController::distribute()
 *   CityAdmin\ShelterDetailController::distributeRelief()
 *
 * They were copies of one another and they HAD ALREADY DRIFTED: the City Admin
 * copy carried an `abort_if` tying the household to this shelter and the
 * barangay copy did not, for a whole phase, in silence. That is gotcha 19 in
 * its purest form. Drop B2 changes five things about distribution, and five
 * changes times two hand-written copies is how the next drift starts.
 *
 * So the rules and the write live here once. Same reasoning as
 * RecordsReliefReceipt in Drop B1, which did the same job for the three
 * stock-in paths.
 *
 * NOTE gotcha 14: a trait constant cannot be read as `Trait::CONST`, so the
 * quantity bound stays on the ReliefTransaction model.
 */
trait DistributesRelief
{
    protected function reliefDistributionRules(): array
    {
        return [
            'household_id' => ['required', 'exists:households,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.relief_good_id' => ['required', 'exists:relief_goods,id'],
            // BOUNDED AT BOTH ENDS. It was min:1 with no max against an
            // unsignedInteger column, so a slipped keystroke could try to hand
            // one family four billion units. The stock check would have caught
            // that particular case, but only by luck -- the bound is what makes
            // it a validation message instead of an accident.
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:' . ReliefTransaction::MAX_QUANTITY],
            'remarks' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Sum quantities per relief good BEFORE anything is checked or written.
     *
     * THE BUG THIS FIXES. Add "Rice 5" twice against a stock of 8 and the old
     * loop checked each row on its own: the first iteration passed and
     * decremented to 3, the second failed with "on hand: 3" -- a number the
     * operator had never seen on screen and could not reconcile with the 8 in
     * front of them. The transaction rolled back, so the data was fine, but the
     * message was nonsense and the work was lost.
     *
     * Aggregating first means the check is against the number actually being
     * asked for, and the message quotes the stock the operator can see.
     */
    protected function aggregateReliefItems(array $items): array
    {
        $totals = [];

        foreach ($items as $item) {
            $id = (int) $item['relief_good_id'];
            $totals[$id] = ($totals[$id] ?? 0) + (int) $item['quantity'];
        }

        return $totals;
    }

    /**
     * Perform the distribution, or fail with a message that lands on the form.
     *
     * WHY ValidationException AND NOT abort(422). To be clear about what was
     * NOT wrong: `abort()` inside a DB::transaction() closure DOES roll back --
     * the exception propagates, the transaction unwinds, and the database stays
     * consistent. There was never a partial-write bug here and nobody should go
     * looking for one.
     *
     * What was wrong is what the operator saw: a bare 422 error page. The
     * household they had searched for, every item row they had added and their
     * remarks were all gone, and the only way forward was the back button.
     * A ValidationException redirects back with the input intact and the
     * message on the field, which is what every other form in the system does.
     *
     * ONE WRITE, ONE ROW PER GOOD. Because the items are aggregated first, two
     * rows of "Rice 5" become a single transaction of 10 rather than two rows
     * of 5. That is also what makes the Distribution Log readable.
     */
    protected function distributeReliefTo(EvacuationCenter $center, Household $household, array $data): void
    {
        $totals = $this->aggregateReliefItems($data['items']);

        DB::transaction(function () use ($totals, $center, $household, $data) {
            $shortages = [];
            $rows = [];

            foreach ($totals as $goodId => $quantity) {
                /* lockForUpdate() holds this inventory row for the life of the
                   transaction, closing the race where two camp managers
                   distribute the last of an item at the same moment and both
                   read the same "on hand" before either writes. Without it the
                   second decrement can drive quantity_on_hand negative, and
                   because occupancy-style repair does not exist for relief,
                   nothing would ever correct it.

                   Ordered by id so two concurrent multi-item distributions
                   always take their locks in the same sequence. Locking two
                   rows in opposite orders is a deadlock, and it would surface
                   as a random failure under exactly the load a defence demo
                   does not have but a real typhoon does. */
                $inventory = ReliefInventory::where('evacuation_center_id', $center->id)
                    ->where('relief_good_id', $goodId)
                    ->lockForUpdate()
                    ->first();

                $onHand = $inventory?->quantity_on_hand ?? 0;

                if ($onHand < $quantity) {
                    $name = ReliefGood::find($goodId)?->name ?? 'that item';
                    $shortages[] = "{$name}: asked for {$quantity}, only {$onHand} on hand.";
                    continue;
                }

                $rows[] = [$inventory, $goodId, $quantity];
            }

            /* EVERY shortage is collected and reported at once. Failing on the
               first one makes an operator with three short items resubmit three
               times, discovering one problem per attempt. */
            if ($shortages) {
                throw ValidationException::withMessages([
                    'items' => array_merge(['Not enough stock to complete this distribution.'], $shortages),
                ]);
            }

            foreach ($rows as [$inventory, $goodId, $quantity]) {
                $inventory->decrement('quantity_on_hand', $quantity);
                $inventory->update(['last_updated_at' => now()]);

                ReliefTransaction::create([
                    'evacuation_center_id' => $center->id,
                    'relief_good_id' => $goodId,
                    'type' => 'distributed',
                    'quantity' => $quantity,
                    'household_id' => $household->id,
                    'recorded_by' => auth()->id(),
                    'transaction_date' => now()->toDateString(),
                    'remarks' => $data['remarks'] ?? null,
                ]);
            }
        });
    }

    /**
     * The household must be CHECKED IN AT THIS SHELTER to receive relief here.
     *
     * Both distribute paths call this, which is the point: the City Admin copy
     * already had a version of it and the barangay copy had none.
     *
     * WHY authorizeHousehold() IS NOT ENOUGH on the barangay side.
     * ResolvesCenter::canManageHousehold() returns TRUE for a household whose
     * evacuation_center_id is null, deliberately -- registration is
     * shelter-agnostic and any assigned staff member may complete a
     * pre-check-in record. That is correct for registration and wrong here: it
     * let the endpoint accept a family who was registered but not checked in,
     * and a family sitting at another shelter.
     *
     * The picker has always refused to offer either, so nothing in normal use
     * changes. But a rule the interface enforces and the server does not is not
     * enforced (gotcha 38), and this endpoint is reachable without the picker.
     *
     * This is a BEHAVIOUR CHANGE, approved explicitly. Phase 8 left it alone on
     * purpose because Phase 9 was about to rewrite this screen; Phase 9 is done.
     */
    protected function assertHouseholdIsHere(Household $household, EvacuationCenter $center): void
    {
        if ($household->evacuation_center_id !== $center->id || $household->status !== 'checked_in') {
            throw ValidationException::withMessages([
                'household_id' => 'Relief can only be given to a family who is checked in at this shelter. Check them in first, then distribute.',
            ]);
        }
    }
}
