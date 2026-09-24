<?php

/**
 * A shipping type as the ladder is allowed to see it: id, name, tax_percent
 * and getFullPriceValue(). Any other read throws, because on a real
 * ShippingTypeItem it would fall through to price_data and build the live
 * cart through CartProcessor.
 */
final class ShippingTypeStub
{
    /** @var int */
    public $id;

    /** @var string */
    public $name;

    /** @var float */
    public $tax_percent;

    /** @var float */
    private $fPriceFull;

    /**
     * @param int    $iId
     * @param string $sName
     * @param float  $fPriceFull
     * @param float  $fTaxPercent
     */
    public function __construct(int $iId, string $sName, float $fPriceFull, float $fTaxPercent = 0.0)
    {
        $this->id = $iId;
        $this->name = $sName;
        $this->tax_percent = $fTaxPercent;
        $this->fPriceFull = $fPriceFull;
    }

    /**
     * @return float
     */
    public function getFullPriceValue()
    {
        return $this->fPriceFull;
    }

    /**
     * @param string $sName
     * @return void
     */
    public function __get($sName)
    {
        throw new LogicException("ShippingLadder read '{$sName}' off a shipping type; only id, name, tax_percent and getFullPriceValue() are allowed");
    }
}
