<?php

require_once __DIR__.'/../StoreExtenderPluginTestCase.php';

use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use October\Rain\Exception\ApplicationException;
use Logingrupa\StoreExtender\Classes\Ajax\SafeAjaxResponse;

/**
 * A failed query must never reach the browser as SQL text in production,
 * while messages written for the shopper still do.
 */
class SafeAjaxResponseTest extends StoreExtenderPluginTestCase
{
    /** Container binding only, no plugin tables needed. */
    protected $autoMigrate = false;

    public function testContainerResolvesTheSafeResponse()
    {
        $this->assertInstanceOf(SafeAjaxResponse::class, ajax());
    }

    public function testQueryErrorIsGenericWithDebugOff()
    {
        Config::set('app.debug', false);
        $obException = new QueryException('mysql', 'SELECT * FROM tbl_secret', [], new \Exception('SQLSTATE[42S02]: Base table or view not found'));

        $obResponse = ajax()->exception($obException);

        $this->assertTrue($obResponse->isFatal());
        $this->assertSame(500, $obResponse->getStatusCode());
        $this->assertSame(Lang::get('system::lang.page.custom_error.help'), $obResponse->getMessage());
        $this->assertStringNotContainsString('SQLSTATE', $obResponse->getMessage());
        $this->assertStringNotContainsString('tbl_secret', $obResponse->getMessage());
    }

    public function testGenericExceptionIsGenericWithDebugOff()
    {
        Config::set('app.debug', false);

        $obResponse = ajax()->exception(new \RuntimeException('/home/forge/secret/path.php'));

        $this->assertTrue($obResponse->isFatal());
        $this->assertStringNotContainsString('secret', $obResponse->getMessage());
    }

    public function testQueryErrorStaysDetailedWithDebugOn()
    {
        Config::set('app.debug', true);
        $obException = new QueryException('mysql', 'SELECT * FROM tbl_secret', [], new \Exception('SQLSTATE[42S02]'));

        $this->assertStringContainsString('tbl_secret', ajax()->exception($obException)->getMessage());
    }

    public function testUserFacingMessagesSurvive()
    {
        Config::set('app.debug', false);

        $obApplication = ajax()->exception(new ApplicationException('Grozs ir tukšs'));
        $this->assertSame('Grozs ir tukšs', $obApplication->getMessage());

        $obValidation = ajax()->exception(ValidationException::withMessages(['email' => ['Nepareizs e-pasts']]));
        $this->assertTrue($obValidation->hasInvalidFields());
        $this->assertSame(['Nepareizs e-pasts'], $obValidation->getInvalidFields()['email']);
    }
}
