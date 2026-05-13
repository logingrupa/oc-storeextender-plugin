<?php namespace Logingrupa\StoreExtender\Classes\Mail;

use Closure;
use Throwable;
use Illuminate\Support\Facades\Log;

/**
 * SafeMailer wraps October\Rain\Mail\Mailer at the boundary layer so SMTP / transport
 * / queue-dispatch failures cannot bubble up into business flows (e.g. order placement).
 *
 * Each of the 8 public delivery methods delegates to parent through a single guard()
 * helper that catches Throwable, logs it, and returns null. There is exactly one
 * intentional swallow site — see the inline reason comment in guard().
 *
 * Signatures MUST match October\Rain\Mail\Mailer verbatim. Adding return types would
 * break Liskov substitution because the parent declares none.
 *
 * @package Logingrupa\StoreExtender\Classes\Mail
 */
class SafeMailer extends \October\Rain\Mail\Mailer
{
    public function send($view, array $data = [], $callback = null)
    {
        return $this->guard('send', $view, function () use ($view, $data, $callback) {
            return parent::send($view, $data, $callback);
        });
    }

    public function queue($view, $data = null, $callback = null, $queue = null)
    {
        return $this->guard('queue', $view, function () use ($view, $data, $callback, $queue) {
            return parent::queue($view, $data, $callback, $queue);
        });
    }

    public function queueOn($queue, $view, $data = null, $callback = null)
    {
        return $this->guard('queueOn', $view, function () use ($queue, $view, $data, $callback) {
            return parent::queueOn($queue, $view, $data, $callback);
        });
    }

    public function later($delay, $view, $data = null, $callback = null, $queue = null)
    {
        return $this->guard('later', $view, function () use ($delay, $view, $data, $callback, $queue) {
            return parent::later($delay, $view, $data, $callback, $queue);
        });
    }

    public function laterOn($queue, $delay, $view, ?array $data = null, $callback = null)
    {
        return $this->guard('laterOn', $view, function () use ($queue, $delay, $view, $data, $callback) {
            return parent::laterOn($queue, $delay, $view, $data, $callback);
        });
    }

    public function raw($view, $callback)
    {
        return $this->guard('raw', $view, function () use ($view, $callback) {
            return parent::raw($view, $callback);
        });
    }

    public function sendTo($recipients, $view, array $data = [], $callback = null, $options = [])
    {
        return $this->guard('sendTo', $view, function () use ($recipients, $view, $data, $callback, $options) {
            return parent::sendTo($recipients, $view, $data, $callback, $options);
        });
    }

    public function rawTo($recipients, $view, $callback = null, $options = [])
    {
        return $this->guard('rawTo', $view, function () use ($recipients, $view, $callback, $options) {
            return parent::rawTo($recipients, $view, $callback, $options);
        });
    }

    /**
     * guard runs the delivery closure and swallows any Throwable so mail
     * failures cannot abort the caller (checkout, password reset, etc.).
     *
     * @param  string  $sOperation  Mailer method name for log attribution.
     * @param  mixed   $view        Original $view argument, used for log context only.
     * @param  Closure $fnDelivery  Closure invoking the corresponding Mailer method.
     * @return mixed                Whatever the parent returns, or null on failure.
     */
    private function guard(string $sOperation, $view, Closure $fnDelivery)
    {
        try {
            return $fnDelivery();
        } catch (Throwable $obException) {
            // silent: mail failures must not 500 checkout / other boundary flows
            Log::error(sprintf(
                'SafeMailer::%s failed for "%s" — %s at %s:%d',
                $sOperation,
                $this->describeView($view),
                $obException->getMessage(),
                $obException->getFile(),
                $obException->getLine()
            ));
            return null;
        }
    }

    /**
     * describeView renders the $view argument as a short string for log messages.
     * Mailer accepts string view names, [html, text] arrays, and Mailable objects.
     *
     * @param  mixed $view
     * @return string
     */
    private function describeView($view): string
    {
        if (is_string($view)) {
            return $view;
        }
        if (is_array($view)) {
            $sEncoded = json_encode($view);
            return $sEncoded !== false ? $sEncoded : '<unencodable-array>';
        }
        if (is_object($view)) {
            return get_class($view);
        }
        return gettype($view);
    }
}
