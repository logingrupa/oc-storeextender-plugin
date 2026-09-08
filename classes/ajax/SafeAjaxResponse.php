<?php namespace Logingrupa\StoreExtender\Classes\Ajax;

use Larajax\Classes\AjaxResponse;
use System\Classes\ErrorHandler;
use Twig\Error\RuntimeError;

/**
 * AJAX envelope on top of Larajax 3.0, which already reports unintended
 * exceptions and hides their message with debug off. Two gaps remain: an
 * exception thrown while rendering a partial reaches Larajax wrapped by Twig
 * and would be classified as the wrapper, and the generic replacement text is
 * a hard-coded English string, while October's error policy carries the
 * translated one.
 */
class SafeAjaxResponse extends AjaxResponse
{
    /**
     * @param \Throwable $obException
     * @return static
     */
    public function exception($obException): static
    {
        return parent::exception($this->unwrapTwig($obException));
    }

    /**
     * October's policy is Larajax's plus the translated fallback text: it
     * returns getSafeMessage() when the exception carries one, the real
     * message with debug on, and system::lang.page.custom_error.help otherwise.
     * @param \Throwable $obException
     * @return string
     */
    protected function safeMessage(\Throwable $obException): string
    {
        return ErrorHandler::getDetailedMessage($obException);
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
