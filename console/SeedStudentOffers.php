<?php

declare(strict_types=1);

namespace Logingrupa\StoreExtender\Console;

use Cms\Classes\Theme;
use Cms\Models\ThemeData;
use Illuminate\Console\Command;
use Logingrupa\StoreExtender\Classes\StudentOffers\StudentOfferDefaults;

/**
 * Seeds cms_theme_data.student_offer_sections with the initial Latvian
 * /online-student-offers sections so Theme Options opens pre-populated.
 */
class SeedStudentOffers extends Command
{
    /** @var string */
    protected $signature = 'storeextender:seed-student-offers
        {--dry-run : Preview actions without writing to the database}
        {--force : Overwrite existing student_offer_sections or seed on a non-Latvian storefront}';

    /** @var string */
    protected $description = 'Seed active theme ThemeData with the default /online-student-offers sections (idempotent)';

    public function handle(): int
    {
        $bDryRun = (bool) $this->option('dry-run');
        $bForce = (bool) $this->option('force');

        $obTheme = Theme::getActiveTheme() ?: Theme::load('logingrupa-naisstore');
        if (! $obTheme || ! $obTheme->isValid()) {
            $this->error('Active CMS theme could not be resolved.');

            return self::FAILURE;
        }

        if (! StudentOfferDefaults::isLatvianStorefront() && ! $bForce) {
            $this->warn('Skipped: active storefront is not Latvian (.lv). Pass --force to override.');

            return self::SUCCESS;
        }

        $sThemeCode = $obTheme->getDirName();
        $obExisting = ThemeData::createThemeDataModel()->where('theme', $sThemeCode)->first();
        $arExistingData = is_array($obExisting?->data) ? $obExisting->data : [];

        if (array_key_exists(StudentOfferDefaults::THEME_FIELD, $arExistingData) && ! $bForce) {
            $arDecoded = StudentOfferDefaults::decodeRepeaterValue($arExistingData[StudentOfferDefaults::THEME_FIELD]);
            $iExistingCount = is_array($arDecoded) ? count($arDecoded) : 0;

            $this->info(sprintf(
                'Theme "%s" already defines %s (%d section(s)). Pass --force to overwrite.',
                $sThemeCode,
                StudentOfferDefaults::THEME_FIELD,
                $iExistingCount
            ));

            return self::SUCCESS;
        }

        $arDefaults = StudentOfferDefaults::sections();
        $iProductCount = array_sum(array_map(
            static fn (array $arSection): int => count($arSection['products']),
            $arDefaults
        ));

        if ($bDryRun) {
            $this->info(sprintf(
                '[dry-run] Would seed "%s" with %d sections (%d manual product rows).',
                $sThemeCode,
                count($arDefaults),
                $iProductCount
            ));

            return self::SUCCESS;
        }

        $obThemeData = $obExisting ?? ThemeData::createThemeDataModel()->firstOrCreate(['theme' => $sThemeCode]);
        $obThemeData->initFormFields();
        $obThemeData->{StudentOfferDefaults::THEME_FIELD} = $arDefaults;
        $obThemeData->save();
        $obThemeData->clearCache($sThemeCode);

        $this->info(sprintf(
            'Seeded "%s" with %d sections (%d manual product rows) and cleared ThemeData cache.',
            $sThemeCode,
            count($arDefaults),
            $iProductCount
        ));

        return self::SUCCESS;
    }
}
