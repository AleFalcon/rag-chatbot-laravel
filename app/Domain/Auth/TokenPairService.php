<?php

namespace App\Domain\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Owns the full lifecycle of the access/refresh token pair.
 *
 * Centralizing this here keeps the controller thin (SRP) and — critically —
 * makes the pair-naming convention live in exactly one place: this class both
 * writes it (see name()) and reads it back (see pairIdFromName()), so the two
 * can never drift apart.
 */
class TokenPairService
{
    private const ACCESS_TOKEN_TTL_MINUTES = 20;

    private const REFRESH_TOKEN_TTL_DAYS = 7;

    /**
     * Issue a fresh access+refresh pair. Both inserts run in one transaction so
     * a mid-flight failure can't leave the user with a half-issued pair.
     */
    public function issue(User $user): array
    {
        return DB::transaction(fn () => $this->buildPair($user));
    }

    /**
     * Revoke the pair the current token belongs to and issue a new one, as a
     * single atomic unit. Without the transaction, a failure between the revoke
     * and the re-issue would strand the user with zero valid tokens.
     */
    public function rotate(User $user, PersonalAccessToken $currentToken): array
    {
        return DB::transaction(function () use ($user, $currentToken) {
            $this->revokePairOf($user, $currentToken);

            return $this->buildPair($user);
        });
    }

    private function buildPair(User $user): array
    {
        $pairId = (string) Str::uuid();

        $accessToken = $user->createToken(
            $this->name('access', $pairId),
            ['access'],
            now()->addMinutes(self::ACCESS_TOKEN_TTL_MINUTES),
        );

        $refreshToken = $user->createToken(
            $this->name('refresh', $pairId),
            ['refresh'],
            now()->addDays(self::REFRESH_TOKEN_TTL_DAYS),
        );

        return [
            'access_token' => $accessToken->plainTextToken,
            'refresh_token' => $refreshToken->plainTextToken,
            'token_type' => 'Bearer',
            'expires_in' => self::ACCESS_TOKEN_TTL_MINUTES * 60,
        ];
    }

    private function revokePairOf(User $user, PersonalAccessToken $currentToken): void
    {
        $pairId = $this->pairIdFromName($currentToken->name);

        if ($pairId !== null) {
            $user->tokens()->where('name', 'like', '%:'.$pairId)->delete();
        } else {
            // Token issued outside this convention (shouldn't happen): revoke
            // just it rather than risk touching unrelated tokens.
            $currentToken->delete();
        }
    }

    private function name(string $type, string $pairId): string
    {
        return "{$type}:{$pairId}";
    }

    private function pairIdFromName(string $name): ?string
    {
        [, $pairId] = array_pad(explode(':', $name, 2), 2, null);

        return $pairId ?: null;
    }
}
