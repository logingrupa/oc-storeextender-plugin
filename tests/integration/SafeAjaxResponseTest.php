<?php

require_once __DIR__.'/../StoreExtenderPluginTestCase.php';

use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use October\Rain\Exception\ApplicationException;
use October\Rain\Exception\ForbiddenException;
use System\Classes\ErrorHandler;
use Twig\Error\RuntimeError;
use Logingrupa\StoreExtender\Classes\Ajax\SafeAjaxResponse;

/**
 * Production AJAX errors carry no SQL or internal text, while severity,
 * status and user-facing messages stay exactly as Larajax produced them.
 */
class SafeAjaxResponseTest extends StoreExtenderPluginTestCase
{
    /** Container binding only, no plugin tables needed. */
    protected $autoMigrate = false;

    public function setUp(): void
    {
        parent::setUp();
        Config::set('app.debug', false);
    }

    public function testContainerResolvesTheSafeResponse()
    {
        $this->assertInstanceOf(SafeAjaxResponse::class, ajax());
    }

    public function testInternalExceptionsGetTheGenericMessageButKeepLarajaxShape()
    {
        $obQuery = new QueryException('mysql', 'SELECT * FROM tbl_secret', [], new \Exception('SQLSTATE[42S02]'));
        $obResponse = ajax()->exception($obQuery);
        $this->assertTrue($obResponse->isFatal());
        $this->assertSame(500, $obResponse->getStatusCode());
        $this->assertSame(ErrorHandler::getDetailedMessage($obQuery), $obResponse->getMessage());
        $this->assertStringNotContainsString('tbl_secret', $obResponse->getMessage());

        $obResponse = ajax()->exception(new \RuntimeException('/home/forge/secret/path.php'));
        $this->assertTrue($obResponse->isError());
        $this->assertSame(400, $obResponse->getStatusCode());
        $this->assertStringNotContainsString('secret', $obResponse->getMessage());
    }

    public function testQueryErrorStaysDetailedWithDebugOn()
    {
        Config::set('app.debug', true);
        $obQuery = new QueryException('mysql', 'SELECT * FROM tbl_secret', [], new \Exception('SQLSTATE[42S02]'));

        $this->assertStringContainsString('tbl_secret', ajax()->exception($obQuery)->getMessage());
    }

    public function testUserFacingMessagesSurvive()
    {
        $obApplication = ajax()->exception(new ApplicationException('Grozs ir tukšs'));
        $this->assertSame('Grozs ir tukšs', $obApplication->getMessage());
        $this->assertTrue($obApplication->isError());

        $obForbidden = ajax()->exception(new ForbiddenException('Access Denied'));
        $this->assertSame('Access Denied', $obForbidden->getMessage());
        $this->assertSame(400, $obForbidden->getStatusCode());

        $obValidation = ajax()->exception(ValidationException::withMessages(['email' => ['Nepareizs e-pasts']]));
        $this->assertTrue($obValidation->hasInvalidFields());
        $this->assertSame(['Nepareizs e-pasts'], $obValidation->getInvalidFields()['email']);
    }

    public function testUnintendedExceptionsReachTheLogWithDebugOff()
    {
        $obQuery = new QueryException('mysql', 'SELECT * FROM tbl_secret', [], new \Exception('SQLSTATE[42S02]'));

        Log::shouldReceive('error')
            ->once()
            ->withArgs(function ($sMessage, $arContext) use ($obQuery) {
                return $arContext['exception'] === $obQuery;
            });

        ajax()->exception($obQuery);
    }

    public function testTwigWrappedExceptionsReportTheirCause()
    {
        $obQuery = new QueryException('mysql', 'SELECT * FROM tbl_secret', [], new \Exception('SQLSTATE[42S02]'));
        $obWrapped = new RuntimeError('rendering failed', -1, null, $obQuery);

        Log::shouldReceive('error')
            ->once()
            ->withArgs(function ($sMessage, $arContext) use ($obQuery) {
                return $arContext['exception'] === $obQuery;
            });

        ajax()->exception($obWrapped);
    }

    public function testUserFacingExceptionsAreNotReported()
    {
        Log::shouldReceive('error')->never();

        ajax()->exception(new ApplicationException('Grozs ir tukšs'));
        ajax()->exception(new ForbiddenException('Access Denied'));
        ajax()->exception(ValidationException::withMessages(['email' => ['Nepareizs e-pasts']]));
    }

    public function testTwigWrappedExceptionsAreClassifiedByTheirCause()
    {
        $obUser = new RuntimeError('rendering failed', -1, null, new ApplicationException('Grozs ir tukšs'));
        $this->assertSame('Grozs ir tukšs', ajax()->exception($obUser)->getMessage());

        $obQuery = new QueryException('mysql', 'SELECT * FROM tbl_secret', [], new \Exception('SQLSTATE[42S02]'));
        $obWrapped = new RuntimeError('rendering failed: tbl_secret', -1, null, $obQuery);
        $this->assertStringNotContainsString('tbl_secret', ajax()->exception($obWrapped)->getMessage());
    }
}
