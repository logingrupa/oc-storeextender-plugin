<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The .lv group shape: tier groups, the legacy "authorized" group without a price type,
 * and school groups that carry a price type (kolonna grants wholesale, the rest salon).
 */
trait PriceTierGroupFixtures
{
    /**
     * @return void
     */
    protected function createPriceTypeStubTable()
    {
        if (Schema::hasTable('lovata_shopaholic_price_types')) {
            return;
        }

        Schema::create('lovata_shopaholic_price_types', function (Blueprint $obTable) {
            $obTable->increments('id');
            $obTable->boolean('active')->default(0);
            $obTable->string('name');
            $obTable->string('code')->nullable();
            $obTable->string('external_id')->nullable();
            $obTable->integer('currency_id')->nullable();
            $obTable->integer('sort_order')->nullable();
            $obTable->softDeletes();
            $obTable->timestamps();
        });
    }

    /**
     * Creates the groups in .lv id order and returns their ids keyed by code
     * @return array<string, int>
     */
    protected function createPriceTierGroups()
    {
        $this->createPriceTypeStubTable();

        $arPriceTypeIDList = [];
        foreach (['wholesale', 'salon', 'distributor'] as $sPriceTypeCode) {
            $arPriceTypeIDList[$sPriceTypeCode] = DB::table('lovata_shopaholic_price_types')->insertGetId([
                'active' => 1,
                'name'   => ucfirst($sPriceTypeCode),
                'code'   => $sPriceTypeCode,
            ]);
        }

        $arGroupPriceTypeList = [
            'authorized'  => null,
            'salona'      => 'salon',
            'distributor' => 'distributor',
            'vairum'      => 'wholesale',
            'studija'     => 'salon',
            'kolonna'     => 'wholesale',
        ];

        $arGroupIDList = [];
        foreach ($arGroupPriceTypeList as $sGroupCode => $sPriceTypeCode) {
            $arGroupIDList[$sGroupCode] = DB::table('user_groups')->insertGetId([
                'name'          => ucfirst($sGroupCode),
                'code'          => $sGroupCode,
                'price_type_id' => $sPriceTypeCode ? $arPriceTypeIDList[$sPriceTypeCode] : null,
            ]);
        }

        return $arGroupIDList;
    }
}
