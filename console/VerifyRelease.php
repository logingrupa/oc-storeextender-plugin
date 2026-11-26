<?php

declare(strict_types=1);

namespace Logingrupa\StoreExtender\Console;

use Illuminate\Console\Command;
use Logingrupa\StoreExtender\Classes\Deploy\ReleaseIntegrityVerifier;

class VerifyRelease extends Command
{
    /** @var string */
    protected $signature = 'storeextender:verify-release {--skip-public-mirror : Skip mirrored public/ Vite checks}';

    /** @var string */
    protected $description = 'Verify Composer plugin parity, required plugins/markup, strict CMS Twig compilation, and mirrored Vite assets';

    public function handle(): int
    {
        $obVerifier = new ReleaseIntegrityVerifier(
            bCheckPublicMirror: ! (bool) $this->option('skip-public-mirror')
        );

        $arResult = $obVerifier->verify();

        if (! $arResult['ok']) {
            foreach ($arResult['errors'] as $sError) {
                $this->error($sError);
            }

            $this->error(sprintf('Release verification failed with %d error(s).', count($arResult['errors'])));

            return self::FAILURE;
        }

        $arStats = $arResult['stats'];
        $this->info(sprintf(
            'Release verification OK (%d plugins, %d templates, %d mirrored Vite assets).',
            $arStats['plugins'],
            $arStats['templates'],
            $arStats['assets']
        ));

        return self::SUCCESS;
    }
}
