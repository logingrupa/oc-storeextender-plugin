<?php namespace Logingrupa\StoreExtender\Models;

use Lovata\Toolbox\Models\CommonProperty;
use October\Rain\Database\Traits\Sluggable;
use October\Rain\Database\Traits\Sortable;

/**
 * Class UserProperty
 * @package Logingrupa\StoreExtender\Models
 *
 * Dynamic user property definition, the RainLab.User replacement for
 * Lovata\Buddies\Models\Property. Both extend Toolbox CommonProperty, so getWidgetData()
 * and the type list behave identically and the ported rows need no transformation.
 */
class UserProperty extends CommonProperty
{
    use Sortable;
    use Sluggable;

    const TABLE_NAME = 'logingrupa_storeextender_user_properties';

    public $table = self::TABLE_NAME;

    protected $slugs = [];

    public $rules = [
        'name' => 'required',
        'code' => 'required|unique:'.self::TABLE_NAME,
    ];

    public $attributeNames = [
        'name' => 'lovata.toolbox::lang.field.name',
        'code' => 'lovata.toolbox::lang.field.code',
    ];

    protected $fillable = [
        'active',
        'name',
        'code',
        'description',
        'type',
        'settings',
        'sort_order',
    ];

    /**
     * Before save method
     */
    public function beforeSave()
    {
        $this->slug = $this->setSluggedValue('slug', 'name');
    }
}
