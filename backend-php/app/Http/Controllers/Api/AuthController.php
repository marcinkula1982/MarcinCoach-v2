<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\PasswordResetMail;
use App\Models\User;
use App\Services\SessionTokenService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private readonly SessionTokenService $sessionTokenService)
    {
    }

    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'username' => ['required', 'string', 'min:3', 'max:191'],
            'email' => ['nullable', 'email', 'max:191', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:191'],
        ]);

        $username = trim((string) $validated['username']);
        $user = User::query()->where('name', $username)->first();
        if ($user) {
            return response()->json(['message' => 'Username already taken'], 422);
        }

        $email = isset($validated['email'])
            ? Str::lower(trim((string) $validated['email']))
            : strtolower($username) . '@example.local';
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

    public function logout(Request $request): JsonResponse
    {
        $token = trim((string) $request->header('x-session-token', ''));

        if ($token !== '') {
            $this->sessionTokenService->revokeToken($token);
        }

        return response()->json(['ok' => true]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $identifier = $this->passwordResetIdentifier($request);

        $user = $this->findUserForPasswordReset($identifier);
        if ($user) {
            $token = Str::random(64);

            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $user->email],
                [
                    'token' => Hash::make($token),
                    'created_at' => now(),
                ],
            );

            $resetUrl = $this->passwordResetUrl($user, $token);

            Mail::to($user->email)->send(new PasswordResetMail($user, $token, $resetUrl));
        }

        return response()->json(['ok' => true]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $identifier = $this->passwordResetIdentifier($request);

        $validated = $request->validate([
            'token' => ['required', 'string', 'min:16', 'max:191'],
            'password' => ['required', 'string', 'min:8', 'max:191'],
        ]);

        $user = $this->findUserForPasswordReset($identifier);
        if (!$user) {
            return $this->invalidPasswordResetToken();
        }

        $resetRow = DB::table('password_reset_tokens')
            ->where('email', $user->email)
            ->first();

        if (!$resetRow || !$this->passwordResetTokenIsValid($resetRow, (string) $validated['token'])) {
            return $this->invalidPasswordResetToken();
        }

        $user->forceFill([
            'password' => Hash::make((string) $validated['password']),
        ])->save();

        DB::table('password_reset_tokens')->where('email', $user->email)->delete();

        return response()->json(['ok' => true]);
    }

    private function passwordResetIdentifier(Request $request): string
    {
        $request->validate([
            'identifier' => ['nullable', 'string', 'min:1', 'max:191'],
            'email' => ['nullable', 'string', 'min:1', 'max:191'],
        ]);

        $identifier = trim((string) ($request->input('identifier') ?? $request->input('email') ?? ''));
        if ($identifier === '') {
            throw ValidationException::withMessages([
                'identifier' => ['Identifier or email is required.'],
            ]);
        }

        return $identifier;
    }

    private function findUserForPasswordReset(string $identifier): ?User
    {
        $identifier = trim($identifier);
        $normalized = Str::lower($identifier);

        if (str_contains($normalized, '@')) {
            return User::query()->whereRaw('lower(email) = ?', [$normalized])->first();
        }

        return User::query()->where('name', $identifier)->first();
    }

    private function passwordResetUrl(User $user, string $token): string
    {
        $frontendUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        $query = http_build_query([
            'resetToken' => $token,
            'email' => $user->email,
        ]);

        return "{$frontendUrl}/?{$query}";
    }

    private function passwordResetTokenIsValid(object $resetRow, string $token): bool
    {
        $createdAt = CarbonImmutable::parse($resetRow->created_at);
        if ($createdAt->lt(now()->subHour())) {
            return false;
        }

        return Hash::check($token, (string) $resetRow->token);
    }

    private function invalidPasswordResetToken(): JsonResponse
    {
        return response()->json(['message' => 'Invalid or expired password reset token'], 422);
    }
}
