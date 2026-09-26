<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Support\AgeScreen;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\ValidationException;

/**
 * Adds the age screen to a signup component. The birth month and year are
 * only checked here and never stored; the guardian box is required for
 * 13–17. The fields and messages never mention the cutoff, so the screen
 * doesn't hint at which date gets through.
 */
trait ScreensAge
{
    public int|string $birth_month = '';

    public int|string $birth_year = '';

    public bool $guardian_consent = false;

    /**
     * @throws ValidationException when the visitor may not sign up
     */
    protected function screenAge(): void
    {
        if (request()->cookie(AgeScreen::BLOCK_COOKIE)) {
            $this->refuseSignup();
        }

        $this->validate([
            'birth_month' => ['required', 'integer', 'between:1,12'],
            'birth_year' => ['required', 'integer', 'min:1900', 'max:'.now()->year],
        ]);

        $month = (int) $this->birth_month;
        $year = (int) $this->birth_year;

        if (AgeScreen::isUnder13($month, $year, now())) {
            Cookie::queue(AgeScreen::BLOCK_COOKIE, '1', 60 * 24 * 365);
            $this->refuseSignup();
        }

        if (AgeScreen::needsGuardian($month, $year, now()) && ! $this->guardian_consent) {
            throw ValidationException::withMessages([
                'guardian_consent' => 'Please confirm a parent or guardian agrees.',
            ]);
        }
    }

    private function refuseSignup(): never
    {
        throw ValidationException::withMessages([
            'birth_year' => 'We can’t create an account for you.',
        ]);
    }
}
