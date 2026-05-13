<?php namespace Logingrupa\StoreExtender\Classes\Mail;

use InvalidArgumentException;

/**
 * SafeMailManager extends October\Rain\Mail\MailManager to construct SafeMailer
 * instances instead of vanilla Mailer instances during resolve(). All other
 * MailManager behavior — events, queue binding, global addresses, transport
 * creation — is preserved verbatim from the parent so future framework
 * upgrades diff cleanly.
 *
 * @package Logingrupa\StoreExtender\Classes\Mail
 */
class SafeMailManager extends \October\Rain\Mail\MailManager
{
    /**
     * resolve the given mailer. Verbatim copy of parent body with the single
     * change of constructing SafeMailer instead of Mailer.
     *
     * @param  string  $name
     * @return \Illuminate\Mail\Mailer
     * @throws \InvalidArgumentException
     */
    protected function resolve($name)
    {
        // Extensibility
        $this->app['events']->dispatch('mailer.beforeResolve', [$this, $name]);

        $config = $this->getConfig($name);

        if (is_null($config)) {
            throw new InvalidArgumentException("Mailer [{$name}] is not defined.");
        }

        // Once we have created the mailer instance we will set a container instance
        // on the mailer. This allows us to resolve mailer classes via containers
        // for maximum testability on said classes instead of passing Closures.
        $mailer = new SafeMailer(
            $name,
            $this->app['view'],
            $this->createSymfonyTransport($config),
            $this->app['events']
        );

        if ($this->app->bound('queue')) {
            $mailer->setQueue($this->app['queue']);
        }

        // Next we will set all of the global addresses on this mailer, which allows
        // for easy unification of all "from" addresses as well as easy debugging
        // of sent messages since these will be sent to a single email address.
        foreach (['from', 'reply_to', 'to', 'return_path'] as $type) {
            $this->setGlobalAddress($mailer, $config, $type);
        }

        // Extensibility
        $this->app['events']->dispatch('mailer.resolve', [$this, $name, $mailer]);

        return $mailer;
    }
}
