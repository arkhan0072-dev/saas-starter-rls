<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    /** Sign up a new company: its tenant and first user are created together or not at all. */
    public function register(Request $request): JsonResponse
    {
        $request->validate([
            'company' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = DB::transaction(function () use ($request) {
            $tenant = Tenant::create(['name' => $request->string('company')->value()]);

            $user = new User([
                'name' => $request->string('name')->value(),
                'email' => $request->string('email')->value(),
                'password' => $request->string('password')->value(),
            ]);
            $user->tenant()->associate($tenant)->save();

            return $user;
        });

        return response()->json(['token' => $user->createToken('api')->plainTextToken, 'user' => $user], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = [
            'email' => $request->string('email')->value(),
            'password' => $request->string('password')->value(),
        ];

        // validate() takes the same time for unknown emails and wrong passwords.
        if (! Auth::guard('web')->validate($credentials)) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        $user = User::where('email', $credentials['email'])->firstOrFail();

        return response()->json(['token' => $user->createToken('api')->plainTextToken]);
    }

    public function logout(Request $request): Response
    {
        PersonalAccessToken::findToken((string) $request->bearerToken())?->delete();

        return response()->noContent();
    }
}
