<?php

namespace App\Http\Controllers\PublicSite;

use App\Http\Controllers\Controller;
use App\Models\EmergencyHotline;

class HotlineController extends Controller
{
    public function index()
    {
        $groups = EmergencyHotline::where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->groupBy('category');

        // Fixed display order for categories
        $order = ['disaster', 'police', 'fire', 'medical', 'utilities', 'other'];
        $groups = collect($order)
            ->filter(fn ($cat) => $groups->has($cat))
            ->mapWithKeys(fn ($cat) => [$cat => $groups[$cat]]);

        return view('public.hotlines', compact('groups'));
    }
}
