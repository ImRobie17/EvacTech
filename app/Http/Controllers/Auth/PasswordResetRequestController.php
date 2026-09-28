<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\PasswordResetRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;


class PasswordResetRequestController extends Controller
{
   
    private const REQUESTABLE_ROLES = [Role::BARANGAY_PERSONNEL, Role::CITY_ADMIN];

    private const ACKNOWLEDGEMENT = 'If that email address belongs to an EvacTech account, your request has been sent to the administrator who manages it. They will contact you to confirm the request before resetting your password.';

    public function create()
    {
        return view('auth.password-request');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'contact_number' => ['nullable', 'string', 'max:20'],
        ], [
            'email.required' => 'Enter the email address you sign in with.',
        ]);

        $user = User::with('role')
            ->where('email', $data['email'])
            ->where('status', 'active')
            ->whereHas('role', fn ($q) => $q->whereIn('name', self::REQUESTABLE_ROLES))
            ->first();

        
        if (! $user) {
            return redirect()->route('login')->with('success', self::ACKNOWLEDGEMENT);
        }

        
        $existing = $user->passwordResetRequests()->pending()->first();

        if ($existing) {
            $existing->fill([
                'contact_number' => $data['contact_number'] ?? $existing->contact_number,
                'requested_ip' => $request->ip(),
            ])->touch();
        } else {
            PasswordResetRequest::create([
                'user_id' => $user->id,
                'contact_number' => $data['contact_number'] ?? null,
                'status' => PasswordResetRequest::STATUS_PENDING,
                'requested_ip' => $request->ip(),
            ]);
        }

        
        AuditLogger::log('password_reset_requested', $user,
            "Password reset requested for {$user->name}");

        return redirect()->route('login')->with('success', self::ACKNOWLEDGEMENT);
    }
}
