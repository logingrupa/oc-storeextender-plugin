<?php namespace Logingrupa\StoreExtender\Classes\Event\Seo;

use App;
use RainLab\Translate\Classes\Translator;

/**
 * RainLab Translate keys the static page URL map and menu tree by the app locale, while the
 * pages inside carry the Translate locale's URLs. robots.txt and sitemap.xml run outside every
 * site (app locale en, primary site pages), so a request whose two locales differ gets a key of
 * its own. The backend keeps the plain key, because its cache clears set the app locale only.
 */
class StaticPageCacheKeyHandler
{
    const EVENT_LIST = ['pages.router.getCacheKey', 'pages.page.getMenuCacheKey'];

    /**
     * Add listeners
     * @param mixed $obEvent
     */
    public function subscribe($obEvent)
    {
        $obEvent->listen(self::EVENT_LIST, function (&$sKey) {
            if (App::runningInBackend()) {
                return;
            }

            $sTranslateLocale = (string) Translator::instance()->getLocale();
            if ($sTranslateLocale === App::getLocale()) {
                return;
            }

            $sKey .= '-'.$sTranslateLocale;
        });
    }
}
