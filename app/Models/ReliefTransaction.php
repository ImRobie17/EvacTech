<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReliefTransaction extends Model
{
    /**
     * DROP B1 -- donor categories.
     *
     * A constant on the model rather than a database enum, so adding a fifth
     * category later costs no migration. The KEY is what is stored and what
     * validation checks; the label is display only. Same code-not-name rule the
     * vulnerable classifications already follow.
     *
     * A class constant, not a trait constant -- gotcha 14: `Trait::CONST` is not
     * readable, which is why ShelterContext and MemberRules are plain classes.
     */
    public const DONOR_TYPES = [
        'lgu' => 'LGU',
        'dswd' => 'DSWD',
        'ngo' => 'NGOs',
        'other' => 'Others',
    ];

    /**
     * Stock-in types. Both of these ADD to inventory and both may now carry a
     * donor and a value:
     *
     *   received     -- goods arriving at the shelter directly
     *   allocated_in -- goods arriving via an approved city restock
     *
     * The second one gained donor fields in Drop B1 because a donation does not
     * always reach the shelter directly: it sometimes passes through the CSWD
     * Office first and is then allocated onward. Recording the donor only on
     * the direct path would have lost half the donations from the value totals.
     */
    public const STOCK_IN_TYPES = ['received', 'allocated_in'];

    /**
     * Sanity bounds, not business rules.
     *
     * `quantity` is an unsignedInteger, so before Drop B a slipped keystroke
     * could write four billion sacks of rice and the only way back was editing
     * the database by hand. Ten million is high enough for Financial
     * Assistance, whose quantity is a peso amount, and low enough that a
     * fat-fingered entry is caught at the form rather than at the audit.
     *
     * MAX_VALUE is the largest figure decimal(12,2) holds with room to spare.
     */
    public const MAX_QUANTITY = 10000000;

    public const MAX_VALUE = 999999999.99;

    protected $fillable = [
        'evacuation_center_id', 'relief_good_id', 'type', 'quantity', 'household_id',
        'source_or_recipient', 'recorded_by', 'transaction_date', 'remarks',
        // DROP B1. Not fillable means silently dropped on create() with no error
        // anywhere -- the form looks like it worked and the column stays null.
        // That is the headcount-stuck-at-0 bug, and it costs an hour every time.
        'donor_type', 'donor_name', 'monetary_value',
        /* DROP C. Same rule, and here it would have been especially quiet: a
           batch would have written every row correctly, decremented stock
           correctly, credited every household correctly, and simply never
           marked itself as a batch. Nothing would error and the only symptom
           would be a missing badge.

           NOTE that nothing passes this key to create(). Batch rows are written
           by DistributesRelief::distributeReliefTo(), whose create() payload is
           fixed and is deliberately not edited by this drop, and are stamped
           immediately afterwards by an update() inside the same transaction.
           Fillable still matters, because mass assignment guards update() too. */
        'batch_id',
    ];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date',
            // decimal:2 rather than float: money compared as a float is how
            // totals end up a centavo out and nobody can say why.
            'monetary_value' => 'decimal:2',
        ];
    }

    /**
     * Human label for the stored donor key, for tables and report rows.
     *
     * An allocated_in row with no donor is city stock moved between two places
     * the city already owns, so it says so rather than printing a dash that
     * looks like missing data.
     */
    public function donorTypeLabel(): string
    {
        if ($this->donor_type !== null) {
            return self::DONOR_TYPES[$this->donor_type] ?? $this->donor_type;
        }

        return $this->type === 'allocated_in' ? 'City allocation' : '-';
    }

    public function evacuationCenter(): BelongsTo
    {
        return $this->belongsTo(EvacuationCenter::class);
    }

    public function reliefGood(): BelongsTo
    {
        return $this->belongsTo(ReliefGood::class);
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
