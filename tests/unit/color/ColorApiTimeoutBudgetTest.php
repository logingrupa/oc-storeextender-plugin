<?php namespace Logingrupa\StoreExtender\Tests\Unit\Color;

use Logingrupa\StoreExtender\Classes\Color\ColorApiClient;
use PHPUnit\Framework\TestCase;

/**
 * The client's request timeout has to outlast a cold export regeneration.
 *
 * This is the defect that left nailscosmetics.no with zero imported colors for
 * as long as anyone had been looking. The timeout was 3s; the export's cold
 * path was measured at 11.1s from that same box on 2026-08-05 while the
 * exporter rebuilt its hourly cache, against a 0.26s steady state. Every sync
 * died with "cURL error 28: Operation timed out after 3002 milliseconds" and
 * the command dutifully reported "Color API returned no data", which reads like
 * an upstream problem rather than our own budget.
 *
 * The sync period and the export's Cache-Control max-age are both one hour, so
 * runs land on the regenerating cold path often rather than rarely - the same
 * cause behind the transient HTTP 0 seen on nailscosmetics.lv, which had been
 * written off as harmless network flakiness.
 *
 * Deliberately boot-free (plain TestCase, like UpdatesVersionYamlTest): this is
 * a pure invariant over two constants, and its siblings in ColorApiClientTest
 * need PluginTestCase, which SKIPS wherever the Lovata migrations cannot run on
 * SQLite. A guard that skips on the machine you are working on guards nothing.
 */
class ColorApiTimeoutBudgetTest extends TestCase
{
    public function testRequestTimeoutOutlastsAColdExportRegeneration()
    {
        $this->assertGreaterThan(
            ColorApiClient::OBSERVED_COLD_EXPORT_SECONDS,
            ColorApiClient::REQUEST_TIMEOUT_SECONDS,
            'Timeout must outlast a cold export regeneration or the sync can never complete.'
        );
    }
}
