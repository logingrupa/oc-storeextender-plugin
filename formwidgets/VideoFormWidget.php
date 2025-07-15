<?php namespace Logingrupa\Storeextender\FormWidgets;

use Backend\Classes\FormWidgetBase;

/**
 * VideoFormWidget Form Widget
 */
class VideoFormWidget extends FormWidgetBase
{
    /**
     * @inheritDoc
     */
    protected $defaultAlias = 'logingrupa_storeextender_video_form_widget';

    /**
     * @inheritDoc
     */
    public function init()
    {
    }

    /**
     * @inheritDoc
     */
    public function render()
    {
        $this->prepareVars();
        return $this->makePartial('videoformwidget');
    }

    /**
     * Prepares the form widget view data
     */
    public function prepareVars()
    {
        $this->vars['name'] = $this->formField->getName();
        $this->vars['value'] = $this->getLoadValue();
        $this->vars['model'] = $this->model;
    }

    /**
     * @inheritDoc
     */
    public function loadAssets()
    {
        // $this->addCss('css/videoformwidget.css', 'Logingrupa.Storeextender');
        // $this->addJs('js/videoformwidget.js', 'Logingrupa.Storeextender');
    }

    /**
     * @inheritDoc
     */
    public function getSaveValue($value)
    {
        return $value;
    }
}
