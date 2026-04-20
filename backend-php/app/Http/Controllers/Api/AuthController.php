<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SessionTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function __construct(private readonly SessionTokenService $sessionTokenService)
    {
    }

    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'username' => ['required', 'string', 'min:3', 'max:191'],
            'password' => ['required', 'string', 'min:4', 'max:191'],
        ]);

        $username = trim((string) $validated['username']);
        $user = User::query()->where('name', $username)->first();
        if ($user) {
            return response()->json(['message' => 'Username already taken'], 422);
        }

        $email = strtolower($username) . '@example.local';
        $user = User::create([
            'name' => $username,
            'email' => $email,
            'password' => bcrypt((string) $validated['password']),
        ]);

        $sessionToken = $this->sessionTokenService->issueToken((int) $user->id, $user->name);

        return response()->json([
            'sessionToken' => $sessionToken,
            'username' => $user->name,
        ]);
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'username' => ['required', 'string', 'min:1', 'max:191'],
            'password' => ['required', 'string', 'min:1', 'max:191'],
        ]);

        $username = trim((string) $validated['username']);
        $user = User::query()->where('name', $username)->first();
        if (!$user || !Hash::check((string) $validated['password'], (string) $user->password)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        $sessionToken = $this->sessionTokenService->issueToken((int) $user->id, $user->name);

        return response()->json([
            'sessionToken' => $sessionToken,
            'username' => $user->name,
        ]);
    }
}
