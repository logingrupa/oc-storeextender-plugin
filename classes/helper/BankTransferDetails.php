<?php namespace Logingrupa\StoreExtender\Classes\Helper;

use DOMNode;
use DOMXPath;
use DOMDocument;
use Lovata\OrdersShopaholic\Models\Order;

/**
 * Class BankTransferDetails
 * @package Logingrupa\StoreExtender\Classes\Helper
 *
 * The bank transfer details of a shop, read from the rich-text block the managers edit in
 * Theme options > Invoice settings (bank_details, and secondary_bank_details for legal
 * persons when it is filled). Shared by the order mails and the order page.
 *
 * The block is a list: every list item is one row, its leading bold text is the label.
 * Text styled with the editor's inline style "Code" is what the row's Copy button copies.
 */
class BankTransferDetails
{
    const THEME_KEY = 'bank_details';

    const SECONDARY_THEME_KEY = 'secondary_bank_details';

    const LEGAL_PERSON = 'legal_person';

    /** Class of the October rich editor inline style "Code" */
    const COPY_MARK_CLASS = 'oc-class-code';

    const LABEL_TAG_LIST = ['strong', 'b'];

    /**
     * @param Order $obOrder
     * @return array list of ['label' => string, 'value' => string, 'copy' => string]
     */
    public static function forOrder(Order $obOrder): array
    {
        $arPrimaryRowList = self::parse((string) ThemeVariable::get(self::THEME_KEY));
        if (array_get((array) $obOrder->property, 'account_type') !== self::LEGAL_PERSON) {
            return $arPrimaryRowList;
        }

        $arSecondaryRowList = self::parse((string) ThemeVariable::get(self::SECONDARY_THEME_KEY));

        return $arSecondaryRowList ?: $arPrimaryRowList;
    }

    /**
     * @param string $sHtml the rich-text block
     * @return array list of ['label' => string, 'value' => string, 'copy' => string]
     */
    public static function parse(string $sHtml): array
    {
        $sPlainText = self::cleanText(html_entity_decode(strip_tags($sHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($sPlainText === '') {
            return [];
        }

        $bPreviousErrorMode = libxml_use_internal_errors(true);
        $obDocument = new DOMDocument();
        $obDocument->loadHTML('<?xml encoding="UTF-8"><div>'.$sHtml.'</div>');
        libxml_clear_errors();
        libxml_use_internal_errors($bPreviousErrorMode);

        $obXPath = new DOMXPath($obDocument);
        $arRowList = [];
        foreach ($obXPath->query('//li | //p[not(ancestor::li)]') as $obRowNode) {
            $arRow = self::parseRow($obRowNode, $obXPath);
            if ($arRow['value'] !== '') {
                $arRowList[] = $arRow;
            }
        }

        return $arRowList ?: [['label' => '', 'value' => $sPlainText, 'copy' => '']];
    }

    /**
     * @param DOMNode $obRowNode a list item or paragraph
     * @param DOMXPath $obXPath
     * @return array ['label' => string, 'value' => string, 'copy' => string]
     */
    protected static function parseRow(DOMNode $obRowNode, DOMXPath $obXPath): array
    {
        $sLabel = '';
        $sValue = '';
        $bLabelEnded = false;
        foreach ($obRowNode->childNodes as $obChildNode) {
            $bIsLabelNode = in_array(strtolower($obChildNode->nodeName), self::LABEL_TAG_LIST, true);
            $bIsBlankText = $obChildNode->nodeType === XML_TEXT_NODE && self::cleanText($obChildNode->textContent) === '';
            if (!$bLabelEnded && ($bIsLabelNode || $bIsBlankText)) {
                $sLabel .= $obChildNode->textContent;
                continue;
            }

            $bLabelEnded = true;
            $sValue .= $obChildNode->textContent;
        }

        $sLabel = self::cleanText($sLabel);
        $sValue = self::cleanText($sValue);
        $obCopyNode = $obXPath->query(
            ".//*[contains(concat(' ', normalize-space(@class), ' '), ' ".self::COPY_MARK_CLASS." ')]",
            $obRowNode
        )->item(0);

        return [
            // A row that is bold all the way through (the company name) is a value, not a label
            'label' => $sValue === '' ? '' : rtrim($sLabel, ': '),
            'value' => $sValue === '' ? $sLabel : $sValue,
            'copy' => $obCopyNode ? self::cleanText($obCopyNode->textContent) : '',
        ];
    }

    /**
     * @param string $sText
     * @return string one line, non-breaking spaces and the space before a comma removed
     */
    protected static function cleanText(string $sText): string
    {
        $sText = preg_replace('/[\s\x{00A0}]+/u', ' ', $sText);

        return trim(str_replace(' ,', ',', $sText));
    }
}
