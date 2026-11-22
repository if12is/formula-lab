<?php

namespace App\Actions\Decisions;

use App\Exceptions\Domain\DecisionOverrideException;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/** The e-signature step: the signer re-enters their password. Returns the id of the signature event (the engine record's signatureRef). */
final class VerifyElectronicSignatureAction
{
    /**
     * @throws DecisionOverrideException When the password is wrong or too many wrong tries were made.
     */
    public function __invoke(User $user, string $password): string
    {
        $key = 'esign:'.$user->getKey();
        $max = (int) config('decisions.signature_max_attempts');

        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw DecisionOverrideException::tooManyAttempts(RateLimiter::availableIn($key));
        }
        if (! Hash::check($password, (string) $user->getAuthPassword())) {
            RateLimiter::hit($key, 60);

            throw DecisionOverrideException::wrongPassword();
        }
        RateLimiter::clear($key);

        return (string) Str::uuid();
    }
}
