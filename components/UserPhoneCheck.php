<?php namespace Logingrupa\StoreExtender\Components;

use Request;
use Cms\Classes\ComponentBase;
use System\Classes\RateLimiter;
use ApplicationException;

use Lovata\Toolbox\Classes\Helper\UserHelper;
use Logingrupa\StoreExtender\Classes\Helper\UserPhoneLookup;

/**
 * Class UserPhoneCheck
 * @package Logingrupa\StoreExtender\Components
 *
 * Tells the checkout form whether the phone number or email just typed already belongs to
 * an account, so the visitor is offered a login instead of silently creating a duplicate.
 *
 * Each response is a bare boolean. Because the endpoint is public it is also rate limited per
 * address: without that it would be a phone number and email enumeration oracle.
 */
class UserPhoneCheck extends ComponentBase
{
    const RATE_LIMIT_ATTEMPTS = 30;
    const RATE_LIMIT_DECAY_SECONDS = 60;

    /**
     * @return array
     */
    public function componentDetails()
    {
        return [
            'name' => 'User phone check',
            'description' => 'Answers whether a phone number or email already belongs to an account',
        ];
    }

    /**
     * @return array
     */
    public function onCheckPhone()
    {
        $sPhone = (string) input('phone');
        $obLookup = UserPhoneLookup::instance();

        // Short input is not an answer worth rate limiting, and it is never a match.
        if (!$obLookup->isLongEnough($obLookup->normalize($sPhone))) {
            return ['exists' => false];
        }

        return $this->answer(fn (): bool => $obLookup->exists($sPhone));
    }

    /**
     * @return array
     */
    public function onCheckEmail()
    {
        $sEmail = trim((string) input('email'));

        // Not an address yet: never a match, not worth an attempt.
        if (filter_var($sEmail, FILTER_VALIDATE_EMAIL) === false) {
            return ['exists' => false];
        }

        return $this->answer(fn (): bool => !empty(UserHelper::instance()->findUserByEmail($sEmail)));
    }

    /**
     * @param callable $fnExists
     * @return array
     */
    protected function answer(callable $fnExists): array
    {
        // A signed in visitor is not about to create a duplicate account.
        if (!empty(UserHelper::instance()->getUser())) {
            return ['exists' => false];
        }

        $this->assertNotThrottled();

        return ['exists' => (bool) $fnExists()];
    }

    /**
     * @return void
     */
    protected function assertNotThrottled()
    {
        $obLimiter = new RateLimiter('account-check:'.Request::ip());

        if ($obLimiter->tooManyAttempts(self::RATE_LIMIT_ATTEMPTS)) {
            throw new ApplicationException('Too many lookups. Please try again in '.$obLimiter->availableIn().' seconds.');
        }

        $obLimiter->increment(self::RATE_LIMIT_DECAY_SECONDS);
    }
}
