<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Invitation;
use App\Models\User;
use App\Notifications\TeamInvitation;
use App\Tenancy\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class InvitationController extends Controller
{
    public function __construct(private Tenancy $tenancy) {}

    /** Invite someone by email. Inviting the same email again replaces the pending invitation. */
    public function store(Request $request): JsonResource
    {
        $request->validate([
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'role' => ['required', Rule::enum(Role::class)->only([Role::Admin, Role::Member])],
        ]);

        $inviter = $request->user();
        abort_unless($inviter instanceof User, 401);
        $token = Str::random(40);

        $invitation = Invitation::updateOrCreate(['email' => $request->string('email')->lower()->value()], [
            'role' => $request->enum('role', Role::class),
            'token_hash' => hash('sha256', $token),
            'invited_by' => $inviter->id,
            'expires_at' => now()->addDays(7),
            'accepted_at' => null,
        ]);

        Notification::route('mail', $invitation->email)->notify(new TeamInvitation($token, $inviter->tenant->name));

        return new JsonResource($invitation);
    }

    /** Create an account from an emailed invitation code. Public: the person is not signed in yet. */
    public function accept(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $hash = hash('sha256', $request->string('token')->value());
        $tenantId = DB::scalar('select invitation_tenant(?)', [$hash]);

        if (! is_int($tenantId)) {
            throw $this->invalidToken();
        }

        $user = $this->tenancy->run($tenantId, fn () => DB::transaction(function () use ($hash, $request) {
            // The row lock makes two simultaneous accepts of one invitation impossible.
            $invitation = Invitation::where('token_hash', $hash)->whereNull('accepted_at')->lockForUpdate()->first();

            if ($invitation === null || $invitation->expires_at->isPast()) {
                throw $this->invalidToken();
            }
            if (User::where('email', $invitation->email)->exists()) {
                throw ValidationException::withMessages(['email' => __('validation.unique', ['attribute' => 'email'])]);
            }

            $user = new User([
                'name' => $request->string('name')->value(),
                'email' => $invitation->email,
                'password' => $request->string('password')->value(),
            ]);
            $user->tenant_id = $invitation->tenant_id;
            $user->role = $invitation->role;
            $user->save();

            $invitation->update(['accepted_at' => now()]);

            return $user;
        }));

        return response()->json(['token' => $user->createToken('api')->plainTextToken, 'user' => $user], 201);
    }

    private function invalidToken(): ValidationException
    {
        return ValidationException::withMessages(['token' => 'This invitation is invalid or has expired.']);
    }
}
