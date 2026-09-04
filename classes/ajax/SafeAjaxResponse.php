<?php namespace Logingrupa\StoreExtender\Classes\Ajax;

use Larajax\Classes\AjaxResponse;
use Larajax\Contracts\AjaxExceptionInterface;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use System\Classes\ErrorHandler;
use Twig\Error\RuntimeError;

/**
 * AJAX envelope keeps Larajax severity and status, but the message of any
 * exception not written for the user goes through the October error policy,
 * which stays generic with debug off.
 */
class SafeAjaxResponse extends AjaxResponse
{
    /**
     * @param \Throwable $obException
     * @return static
     */
    public function exception($obException): static
    {
        $obResponse = parent::exception($obException);
        $obCause = $this->unwrapTwig($obException);

        if ($this->isStructured($obCause)) {
            return $obResponse;
        }

        $sMessage = ErrorHandler::getDetailedMessage($obCause);
        $iStatus = $obResponse->getStatusCode();

        return $obResponse->isFatal()
            ? $obResponse->fatal($sMessage, $iStatus)
            : $obResponse->error($sMessage, $iStatus);
    }

    /**
     * Exceptions whose envelope shape comes from Larajax itself.
     * @param \Throwable $obException
     * @return bool
     */
    protected function isStructured($obException): bool
    {
        if ($obException instanceof HttpException) {
            return $obException->getStatusCode() < 500;
        }

        return $obException instanceof AjaxExceptionInterface
            || $obException instanceof ValidationException
            || $obException instanceof ModelNotFoundException;
    }

    /**
     * Exceptions thrown while rendering a partial arrive wrapped by Twig.
     * @param \Throwable $obException
     * @return \Throwable
     */
    protected function unwrapTwig($obException)
    {
        if ($obException instanceof RuntimeError && $obException->getPrevious()) {
            return $obException->getPrevious();
        }

        return $obException;
    }
}
