<?php namespace Logingrupa\StoreExtender\Classes\Event\Product;

use Lovata\Toolbox\Classes\Event\AbstractBackendFieldHandler;
use Lovata\Shopaholic\Models\Product;
use Lovata\Shopaholic\Controllers\Products;

/**
 * Class ExtendProductFieldsHandler
 *
 * @package Logingrupa\StoreExtender\Classes\Event\Product
 */
class ExtendProductFieldsHandler extends AbstractBackendFieldHandler
{
    /**
     * Extend fields model
     * @param \Backend\Widgets\Form $obWidget
     */
    protected function extendFields($obWidget): void
    {
        $arFields = [
            'manufacturer' => [
                'label' => 'logingrupa.storeextender::lang.field.manufacturer',
                'tab' => 'lovata.toolbox::lang.tab.settings',
                'type' => 'text',
                'span' => 'left',
            ],
            'warning' => [
                'label' => 'logingrupa.storeextender::lang.field.warning',
                'tab' => 'lovata.toolbox::lang.tab.settings',
                'type' => 'markdown',
                'span' => 'left',
                'size' => 'giant',
            ],
            'ingredients' => [
                'label' => 'logingrupa.storeextender::lang.field.ingredients',
                'tab' => 'lovata.toolbox::lang.tab.settings',
                'type' => 'textarea',
                'span' => 'left',
                'size' => 'normal',
            ],
        ];

        $obWidget->addTabFields($arFields);
    }

    /**
     * Get model class name
     * @return string
     */
    protected function getModelClass(): string
    {
        return Product::class;
    }

    /**
     * Get controller class name
     * @return string
     */
    protected function getControllerClass(): string
    {
        return Products::class;
    }
}