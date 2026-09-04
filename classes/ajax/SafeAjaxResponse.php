<?php namespace Logingrupa\StoreExtender\Classes\Ajax;

use Larajax\Classes\AjaxResponse;
use Larajax\Contracts\AjaxExceptionInterface;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use October\Rain\Exception\ApplicationException;
use System\Classes\ErrorHandler;

/**
 * Larajax echoes the raw exception message into the AJAX envelope regardless
 * of debug mode, so a failed query hands its SQL text to the browser.
 * Exceptions written for the user keep their message; everything else goes
 * through the October error policy, which stays generic with debug off.
 */
class SafeAjaxResponse extends AjaxResponse
{
    public function exception($exception): static
    {
        if ($this->isUserFacing($exception)) {
            return parent::exception($exception);
        }

        return $this->fatal(ErrorHandler::getDetailedMessage($exception), 500);
    }

    protected function isUserFacing($exception): bool
    {
        if ($exception instanceof HttpException) {
            return $exception->getStatusCode() < 500;
        }

        return $exception instanceof AjaxExceptionInterface
            || $exception instanceof ValidationException
            || $exception instanceof ModelNotFoundException
            || $exception instanceof ApplicationException;
    }
}
