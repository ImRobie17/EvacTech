<?php

namespace App\Http\Controllers\Concerns;

use App\Models\EvacuationCenter;
use App\Models\ReliefGood;
use App\Models\ReliefInventory;
use App\Models\ReliefTransaction;
use Illuminate\Support\Carbon;

/**
 * DROP B1 -- everything the three stock-in paths have in common.
 *
 * WHY THIS EXISTS. Stock enters a shelter three ways:
 *
 *   1. Barangay\ReliefController::receive()                  (camp manager)
 *   2. CityAdmin\ShelterDetailController::receiveRelief()    (CSWD Office)
 *   3. CityAdmin\ReliefController::approveRestock()          (allocation)
 *
 * The first two were already IDENTICAL BODIES copied twice, and the two
 * DISTRIBUTE paths beside them had genuinely drifted -- one carried a shelter
 * check the other did not, for a whole phase, in silence. Drop B adds donor
 * capture, a monetary value, remarks, a quantity bound and on-the-fly item
 * creation to all three. That is five new rules times three call sites, and a
 * rule written out three times is a rule that will be three different rules by
 * the next phase.
 *
 * So the rules and the write live here once, and the controllers compose them.
 * Same reasoning as FiltersReports owning the report filters.
 *
 * NOT a service, because there is no state transition to own -- TransferService
 * and PresenceService guard lifecycles, this only assembles a validated form
 * into a row. It follows the Concerns pattern the report controllers already
 * use.
 *
 * NOTE gotcha 14: a trait constant cannot be read as `Trait::CONST` from
 * outside, so the bounds live on the ReliefTransaction model instead.
 */
trait RecordsReliefReceipt
{
    /**
     * Validation for the two Receive Stock forms.
     *
     * relief_good_id and new_good_name are each required_without the other, so
     * the operator must either pick from the catalogue or name a new item, and
     * a blank submit names both fields rather than failing on one.
     */
    protected function reliefReceiptRules(): array
    {
        return array_merge([
            'relief_good_id' => ['required_without:new_good_name', 'nullable', 'exists:relief_goods,id'],
            // On-the-fly catalogue entry. Camp managers can do this, by
            // decision: a donor at the door at 2am who brings something not on
            // the list must be recordable, or the receipt is filed under
            // "Others" with the truth buried in the remarks -- which is the
            // problem the sixteen-item list was meant to solve.
            'new_good_name' => ['required_without:relief_good_id', 'nullable', 'string', 'max:100'],
            'new_good_unit' => ['required_with:new_good_name', 'nullable', 'string', 'max:20'],
            // Bounded at BOTH ends. min:1 with no max against an
            // unsignedInteger column let a mis-keyed quantity write 4 billion
            // units of rice and there was no way back except editing the
            // database by hand.
            'quantity' => ['required', 'integer', 'min:1', 'max:' . ReliefTransaction::MAX_QUANTITY],
            'remarks' => ['nullable', 'string', 'max:500'],
        ], $this->reliefDonorRules(true));
    }

    /**
     * Donor and value rules, shared with the restock approval screen.
     *
     * $required is true on the two direct receive paths, where the goods are in
     * front of the operator and somebody handed them over. It is false on
     * restock approval, where the stock may simply be city supplies moving
     * between two places the city already owns and there is no donor at all.
     */
    protected function reliefDonorRules(bool $required): array
    {
        $keys = implode(',', array_keys(ReliefTransaction::DONOR_TYPES));

        return [
            'donor_type' => [$required ? 'required' : 'nullable', 'nullable', 'in:' . $keys],
            // Optional by the client's decision. The CATEGORY is what they
            // report on; the name is a courtesy, and a private donor who does
            // not want naming must not block the receipt.
            'donor_name' => ['nullable', 'string', 'max:255'],
            'monetary_value' => [
                $required ? 'required' : 'nullable',
                'nullable', 'numeric', 'min:0', 'max:' . ReliefTransaction::MAX_VALUE,
            ],
        ];
    }

    /**
     * The good this receipt is for: an existing catalogue row, or a new one.
     *
     * A typed name WINS over the dropdown when both are filled, because typing
     * a name is the more deliberate act -- nobody types "Sopas (pot)" by
     * accident, but a dropdown can be left on a stale value from a previous
     * open.
     *
     * A typed name matching an existing item REUSES that item rather than
     * erroring or creating a twin. MySQL's default collation is
     * case-insensitive, so "rice 5kg" finds "Rice 5kg" without any
     * raw SQL. This is the guard against the catalogue splitting one commodity
     * across two near-identical rows, which would split its stock with it.
     */
    protected function resolveReliefGood(array $data): ReliefGood
    {
        $typed = trim((string) ($data['new_good_name'] ?? ''));

        if ($typed !== '') {
            $existing = ReliefGood::where('name', $typed)->first();
            if ($existing) {
                return $existing;
            }

            return ReliefGood::create([
                'name' => $typed,
                'unit' => trim((string) ($data['new_good_unit'] ?? 'piece')) ?: 'piece',
                // Never read anywhere -- see the seeder. A new item cannot be
                // classified by anyone at the point of receipt anyway.
                'category' => 'other',
                // A staff-created item is a physical count. Only Financial
                // Assistance is monetary, and it is seeded.
                'is_monetary' => false,
            ]);
        }

        return ReliefGood::findOrFail($data['relief_good_id']);
    }

    /**
     * Increment inventory and write the stock-in row.
     *
     * MUST be called inside a DB::transaction() by the caller -- the increment
     * and the ledger row have to land together or the ledger stops explaining
     * the stock.
     *
     * $type is 'received' for a direct arrival and 'allocated_in' for an
     * approved city restock. Both add to inventory; both may now carry a donor,
     * because a donation does not always reach a shelter directly.
     */
    protected function applyReliefReceipt(
        EvacuationCenter $center,
        ReliefGood $good,
        array $data,
        string $type = 'received',
        ?string $sourceLabel = null
    ): ReliefTransaction {
        $inventory = ReliefInventory::firstOrCreate(
            ['evacuation_center_id' => $center->id, 'relief_good_id' => $good->id],
            ['quantity_on_hand' => 0, 'reorder_level' => 0]
        );
        $inventory->increment('quantity_on_hand', $data['quantity']);
        $inventory->update(['last_updated_at' => now()]);

        return ReliefTransaction::create([
            'evacuation_center_id' => $center->id,
            'relief_good_id' => $good->id,
            'type' => $type,
            'quantity' => $data['quantity'],
            'source_or_recipient' => $sourceLabel,
            'donor_type' => $data['donor_type'] ?? null,
            'donor_name' => $this->blankToNull($data['donor_name'] ?? null),
            'monetary_value' => $this->resolveMonetaryValue($good, $data),
            'recorded_by' => auth()->id(),
            'transaction_date' => now()->toDateString(),
            'remarks' => $this->blankToNull($data['remarks'] ?? null),
        ]);
    }

    /**
     * For a MONETARY good the quantity is already the peso amount, one unit per
     * peso, so a blank value field is filled from it rather than left null.
     *
     * Without this the operator has to key 5000 into two boxes and the two can
     * disagree, and a receipt whose ledger quantity and reported value disagree
     * is a receipt nobody can reconcile.
     */
    protected function resolveMonetaryValue(ReliefGood $good, array $data): ?string
    {
        $value = $data['monetary_value'] ?? null;

        if (($value === null || $value === '') && $good->is_monetary) {
            return (string) $data['quantity'];
        }

        return ($value === null || $value === '') ? null : (string) $value;
    }

    /**
     * Total peso value that ARRIVED at a shelter, or city-wide when $center is
     * null.
     *
     * Sums stock-in types only. A `distributed` row never carries a value, but
     * filtering on the type here rather than trusting the column to be null is
     * what stops a later feature that reuses the column from quietly inflating
     * every donation total on the system.
     */
    protected function reliefValueReceived(?EvacuationCenter $center = null): float
    {
        return (float) ReliefTransaction::whereIn('type', ReliefTransaction::STOCK_IN_TYPES)
            ->when($center, fn ($q) => $q->where('evacuation_center_id', $center->id))
            ->sum('monetary_value');
    }

    /**
     * The four unit figures on both relief screens, plus the peso total.
     *
     * MONETARY GOODS ARE EXCLUDED FROM ALL FOUR UNIT FIGURES. Financial
     * Assistance stores pesos in `quantity` by the client's decision, so
     * counting it here would make "Received" read 5,200 where 200 packs
     * arrived, and would make days_left -- remaining divided by the daily burn
     * rate -- arithmetic on two incompatible units.
     *
     * Computed here rather than in each controller because Barangay\
     * ReliefController::index() and CityAdmin\ShelterDetailController::
     * reliefData() were already two hand-written copies of the same four
     * figures, which is precisely how the two distribute paths drifted.
     */
    protected function reliefStockStats(EvacuationCenter $center): array
    {
        $countableIds = ReliefGood::countable()->pluck('id');

        $received = ReliefTransaction::where('evacuation_center_id', $center->id)
            ->whereIn('type', ReliefTransaction::STOCK_IN_TYPES)
            ->whereIn('relief_good_id', $countableIds)
            ->sum('quantity');

        $distributed = ReliefTransaction::where('evacuation_center_id', $center->id)
            ->where('type', 'distributed')
            ->whereIn('relief_good_id', $countableIds)
            ->sum('quantity');

        $remaining = ReliefInventory::where('evacuation_center_id', $center->id)
            ->whereIn('relief_good_id', $countableIds)
            ->sum('quantity_on_hand');

        // Projection: 7-day rolling average of distribution rate. An estimate,
        // not a promise -- and now an estimate of PHYSICAL stock only.
        $last7 = ReliefTransaction::where('evacuation_center_id', $center->id)
            ->where('type', 'distributed')
            ->whereIn('relief_good_id', $countableIds)
            ->where('transaction_date', '>=', Carbon::today()->subDays(6))
            ->sum('quantity');
        $dailyRate = $last7 / 7;

        return [
            'received' => $received,
            'distributed' => $distributed,
            'remaining' => $remaining,
            'days_left' => $dailyRate > 0 ? (int) floor($remaining / $dailyRate) : null,
            'value_received' => $this->reliefValueReceived($center),
        ];
    }

    /**
     * Stock-in rows for the Stock Receipts panel.
     *
     * Deliberately NOT paginated. Both relief screens already paginate the
     * Distribution Log, and two paginators on one page share the `page` query
     * parameter -- turning to page 2 of the receipts would silently turn the
     * distribution log too. The heading carries the true total so the fifteen
     * shown never read as the whole story.
     */
    protected function reliefReceipts(EvacuationCenter $center, int $limit = 15)
    {
        return ReliefTransaction::with(['reliefGood', 'recordedBy'])
            ->where('evacuation_center_id', $center->id)
            ->whereIn('type', ReliefTransaction::STOCK_IN_TYPES)
            ->latest('transaction_date')
            ->latest('id')
            ->take($limit)
            ->get();
    }

    protected function reliefReceiptCount(EvacuationCenter $center): int
    {
        return ReliefTransaction::where('evacuation_center_id', $center->id)
            ->whereIn('type', ReliefTransaction::STOCK_IN_TYPES)
            ->count();
    }

    /** An empty text input posts "", which is not the same fact as "not given". */
    protected function blankToNull(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
