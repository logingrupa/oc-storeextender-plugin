<?php

require_once __DIR__.'/../StoreExtenderPluginTestCase.php';

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Logingrupa\StoreExtender\Classes\Color\ColorSyncSchedule;
use Logingrupa\StoreExtender\Plugin;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The shops share one nailolab host that sleeps between requests. When all of them
 * called it in the same second its cold start failed with 503, so each shop sets its
 * own sync minute and a malformed value must never break the scheduler.
 */
class ColorSyncScheduleTest extends StoreExtenderPluginTestCase
{
    /** Reads config and the schedule only, no table */
    protected $autoMigrate = false;

    const COMMAND = 'storeextender:sync-offer-colors';

    public function testScheduleRunsAtTheConfiguredMinuteInRigaTime()
    {
        Config::set('services.color_lab.sync_at', '07:04');

        $obEvent = $this->colorSyncEvent();

        $this->assertSame('4 7 * * *', $obEvent->expression);
        $this->assertSame('Europe/Riga', $obEvent->timezone);
    }

    public function testUnsetTimeKeepsSevenOClockWithoutWarning()
    {
        Config::set('services.color_lab.sync_at', null);
        Log::spy();

        $this->assertSame('0 7 * * *', $this->colorSyncEvent()->expression);
        Log::shouldNotHaveReceived('warning');
    }

    public function testMalformedTimeKeepsSevenOClockAndWarns()
    {
        Config::set('services.color_lab.sync_at', '7:4');
        Log::spy();

        $this->assertSame('0 7 * * *', $this->colorSyncEvent()->expression);
        Log::shouldHaveReceived('warning')->once();
    }

    #[DataProvider('timeProvider')]
    public function testOnlyTwoDigitHoursAndMinutesAreValid($mTime, bool $bExpected)
    {
        $this->assertSame($bExpected, ColorSyncSchedule::isValidTime($mTime));
    }

    public static function timeProvider(): array
    {
        return [
            'seven'          => ['07:00', true],
            'seven oh four'  => ['07:04', true],
            'midnight'       => ['00:00', true],
            'last minute'    => ['23:59', true],
            'single digits'  => ['7:4', false],
            'hour 24'        => ['24:00', false],
            'minute 60'      => ['07:60', false],
            'trailing space' => ['07:04 ', false],
            'word'           => ['soon', false],
            'integer'        => [704, false],
        ];
    }

    private function colorSyncEvent(): \Illuminate\Console\Scheduling\Event
    {
        $obSchedule = new Schedule();
        (new Plugin(App::make('app')))->registerSchedule($obSchedule);

        foreach ($obSchedule->events() as $obEvent) {
            if (str_contains($obEvent->command, self::COMMAND)) {
                return $obEvent;
            }
        }

        $this->fail(self::COMMAND.' is not scheduled');
    }
}
