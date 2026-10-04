<?php

require_once __DIR__.'/../../updates/update_table_theme_data_bank_details_copy_mark.php';

use PHPUnit\Framework\TestCase;
use Logingrupa\StoreExtender\Classes\Helper\BankTransferDetails;
use Logingrupa\StoreExtender\Updates\UpdateTableThemeDataBankDetailsCopyMark;

/**
 * The bank details block is free rich text the managers edit, so the parser and the
 * one-time copy mark are pinned on the blocks the three shops held on 2026-10-02.
 */
class BankTransferDetailsTest extends TestCase
{
    const BLOCK_LV = '<ul><li><strong>PROMINENCE SIA</strong></li><li><strong>Banka:&nbsp;</strong>Luminor Bank AS, swift: <span style="color:red;">RIKOLV2X</span></li><li><strong>Konts:&nbsp;</strong><span style="color:red;">LV37RIKO0000082814159</span>, EUR</li><li><strong>Reģ. nr.:&nbsp;</strong>40103186559</li><li><strong>Juridiskā adrese:&nbsp;</strong>Mālkalnes prospekts 27-48, Ogre, LV-5001, Latvija</li></ul>';

    const BLOCK_LT = '<ul><li><strong>Įmone:</strong> Jungle Fever Lt, MB</li><li><strong>Bankas:&nbsp;</strong>„Swedbank”, AB , swift: HABALT22</li><li><strong>Konts:&nbsp;</strong><a href="javascript%3AlinkAction(\'business.d2d.accounts.accountStatement\',\'force_acc\',\'10152721946\',\'\',\'\',\'\',\'\')" title="Sąskaitos išrašas">LT197300010152721946</a> , EUR</li><li><strong>Įmonės kodas</strong><strong>:&nbsp;</strong>304590569</li><li><strong>Adresas</strong><strong>:&nbsp;</strong>V. Krėvės pr. 114F-1A, LT-50315 Kaunas</li></ul>';

    const BLOCK_NO = '<ul><li>AGNESE KRAVECA STUDIO</li><li><strong>Bank:&nbsp;</strong>DNB, <strong>swift</strong>: DNBANOKKXXX</li><li><strong>Konts:&nbsp;</strong>15068752040, NOK</li><li><strong>Org. Nr</strong><strong>.:&nbsp;</strong>893 342 052</li><li><strong>Juridiskā adrese:&nbsp;</strong>Kongensgate 28 1530 MOSS</li></ul>';

    public function testLithuanianBlockParsesIntoLabelledRowsWithoutTheBankLink()
    {
        $this->assertSame([
            ['label' => 'Įmone', 'value' => 'Jungle Fever Lt, MB', 'copy' => ''],
            ['label' => 'Bankas', 'value' => '„Swedbank”, AB, swift: HABALT22', 'copy' => ''],
            ['label' => 'Konts', 'value' => 'LT197300010152721946, EUR', 'copy' => 'LT197300010152721946'],
            ['label' => 'Įmonės kodas', 'value' => '304590569', 'copy' => ''],
            ['label' => 'Adresas', 'value' => 'V. Krėvės pr. 114F-1A, LT-50315 Kaunas', 'copy' => ''],
        ], BankTransferDetails::parse(UpdateTableThemeDataBankDetailsCopyMark::markAccount(self::BLOCK_LT)));
    }

    public function testLatvianBlockCopiesTheBeneficiaryAndTheIban()
    {
        $arRowList = BankTransferDetails::parse(UpdateTableThemeDataBankDetailsCopyMark::markAccount(self::BLOCK_LV));

        $this->assertSame(['label' => '', 'value' => 'PROMINENCE SIA', 'copy' => 'PROMINENCE SIA'], $arRowList[0]);
        $this->assertSame(['label' => 'Banka', 'value' => 'Luminor Bank AS, swift: RIKOLV2X', 'copy' => ''], $arRowList[1]);
        $this->assertSame(['label' => 'Konts', 'value' => 'LV37RIKO0000082814159, EUR', 'copy' => 'LV37RIKO0000082814159'], $arRowList[2]);
        $this->assertSame(['label' => 'Reģ. nr.', 'value' => '40103186559', 'copy' => ''], $arRowList[3]);
    }

    public function testNorwegianBlockCopiesTheBeneficiaryAndTheDomesticAccount()
    {
        $arRowList = BankTransferDetails::parse(UpdateTableThemeDataBankDetailsCopyMark::markAccount(self::BLOCK_NO));

        $this->assertSame(['label' => '', 'value' => 'AGNESE KRAVECA STUDIO', 'copy' => 'AGNESE KRAVECA STUDIO'], $arRowList[0]);
        $this->assertSame(['label' => 'Bank', 'value' => 'DNB, swift: DNBANOKKXXX', 'copy' => ''], $arRowList[1]);
        $this->assertSame(['label' => 'Konts', 'value' => '15068752040, NOK', 'copy' => '15068752040'], $arRowList[2]);
        $this->assertSame(['label' => 'Org. Nr.', 'value' => '893 342 052', 'copy' => ''], $arRowList[3]);
    }

    public function testUnmarkedBlockHasNoCopyValueAndAnEmptyBlockNoRows()
    {
        $this->assertSame('', BankTransferDetails::parse(self::BLOCK_LV)[2]['copy']);
        $this->assertSame([], BankTransferDetails::parse('<p>&nbsp;</p>'));
        $this->assertSame([], BankTransferDetails::parse(''));
    }

    public function testMarkingIsIdempotentAndLeavesAManagerMarkedBlockAlone()
    {
        $sMarked = UpdateTableThemeDataBankDetailsCopyMark::markAccount(self::BLOCK_LT);

        $this->assertStringNotContainsString('<a ', $sMarked);
        $this->assertSame(1, substr_count($sMarked, 'oc-class-code'));
        $this->assertSame($sMarked, UpdateTableThemeDataBankDetailsCopyMark::markAccount($sMarked));
    }

    public function testPlainParagraphsBecomeRows()
    {
        $this->assertSame([
            ['label' => '', 'value' => 'Shop Ltd', 'copy' => 'Shop Ltd'],
            ['label' => 'IBAN', 'value' => 'GB00TEST', 'copy' => ''],
        ], BankTransferDetails::parse('<p>Shop Ltd</p><p><b>IBAN:</b> GB00TEST</p>'));
    }
}
