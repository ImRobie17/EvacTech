<?php

namespace App\Http\Controllers\PublicSite;

use App\Http\Controllers\Controller;
use App\Models\HouseholdMember;
use Illuminate\Http\Request;

class FindFamilyController extends Controller
{
    public function index()
    {
        return view('public.find-family');
    }

    /**
     * Exact-name-only lookup. Deliberately limited for privacy:
     * - exact match on the stored "Last, First [Middle]" name (case-insensitive)
     * - returns ONLY status + date/time, never the shelter or location
     * - throttled per IP in routes (10/min) to prevent scraping
     */
    public function search(Request $request)
    {
        $data = $request->validate([
            'last_name' => ['required', 'string', 'max:100'],
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
        ]);

        // Names are stored as "Last, First" or "Last, First Middle"
        // (see EvacueeProfilingController::syncMembers)
        $fullName = trim($data['last_name'] . ', ' . $data['first_name'] . ' ' . ($data['middle_name'] ?? ''));

        $matches = HouseholdMember::with('household')
            ->whereRaw('LOWER(full_name) = ?', [mb_strtolower($fullName)])
            ->get()
            ->map(function ($m) {
                $household = $m->household;
                $status = 'unknown';
                $at = null;

                if ($household) {
                    if ($household->status === 'checked_in' && $m->is_present) {
                        $status = 'checked_in';
                        $at = $household->checked_in_at;
                    } elseif ($household->checked_out_at) {
                        $status = 'checked_out';
                        $at = $household->checked_out_at;
                    } elseif ($household->status === 'checked_in' && ! $m->is_present) {
                        // Household is sheltered but this person was not marked present
                        $status = 'not_present';
                    } else {
                        $status = 'registered';
                    }
                }

                return [
                    'name' => $m->full_name,
                    'status' => $status,
                    'at' => $at?->format('M d, Y h:i A'),
                ];
            });

        return view('public.find-family', [
            'searched' => $fullName,
            'results' => $matches,
        ]);
    }
}
