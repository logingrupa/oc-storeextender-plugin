<?php namespace logingrupa\Storeextender\Classes\Event\User;

use Lang;
use Illuminate\Support\Facades\Log;

use Lovata\Toolbox\Classes\Helper\UserHelper;
use Logingrupa\StoreExtender\Classes\Helper\UserGroupHelper;

class UserModelHandler
{
    public function subscribe()
    {
        // UserHelper resolves through PluginManager::exists(), which honours the disabled
        // flag, so a broken RainLab install leaves the model unextended instead of fatal.
        if (empty(UserHelper::instance()->getPluginName())) {
            return;
        }

        $obRainLabExtension = new ExtendRainLabUserModel();

        \RainLab\User\Models\User::extend(function ($obElement) use ($obRainLabExtension) {
            $obRainLabExtension->extend($obElement);
            $this->extendUserModel($obElement);
        });
    }

    protected function extendUserModel($obElement)
    {
        $this->addValidationRules($obElement);

        // Both save events also fire on create, so they cover registration and later edits
        $obElement->bindEvent('model.beforeSave', function () use ($obElement) {
            $this->applySchoolPriceGroup($obElement);
        });

        $obElement->bindEvent('model.afterSave', function () use ($obElement) {
            $this->mirrorPrimaryGroup($obElement);
        });
    }

    protected function addValidationRules($obElement)
    {
        // October\Rain\Auth\Models\User declares $customMessages; RainLab's User extends the
        // plain Model and does not, and there is no setter for it, so the rule would fire
        // with an untranslated message. It is a register-form anti-bot question rather than a
        // user invariant, so under RainLab it is validated in RainLabRegistrationHandler
        // instead, which also keeps it from blocking backend and programmatic user creation.
        if (!property_exists($obElement, 'customMessages')) {
            return;
        }

        $obElement->rules['property[security]'] = 'required:create|in:5';

        // Get current customMessages, modify it, then reassign to avoid "indirect modification" error
        $customMessages = $obElement->customMessages;
        $customMessages['property.security.required'] = Lang::get('logingrupa.storeextender::lang.message.e_security_required');
        $customMessages['property.security.in'] = Lang::get('logingrupa.storeextender::lang.message.e_security_in');
        $obElement->customMessages = $customMessages;
    }

    /**
     * A school chosen in the forms sets the price tier when its group carries a price type
     * and no manager set the tier. Runs only when the school changed: every other save
     * (login stamp, checkout phone, backend edit) leaves the primary group alone.
     * @param \RainLab\User\Models\User $obElement
     */
    protected function applySchoolPriceGroup($obElement)
    {
        $sSchoolCode = $obElement->property['school-name'] ?? null;
        if (!$sSchoolCode || $sSchoolCode === $this->getOriginalSchoolCode($obElement)) {
            return;
        }

        $obGroup = UserGroupHelper::instance()->findByCode($sSchoolCode);
        if (!$obGroup) {
            Log::warning("Group with code '{$sSchoolCode}' not found.");
            return;
        }

        if (empty($obGroup->price_type_id) || $this->hasManagerSetTier($obElement)) {
            return;
        }

        $obElement->primary_group_id = $obGroup->id;
    }

    /**
     * A priced primary group other than the previously chosen school came from a manager.
     * Queried through the relation so a primary group changed in the same save counts.
     * @param \RainLab\User\Models\User $obElement
     * @return bool
     */
    protected function hasManagerSetTier($obElement)
    {
        $obPrimaryGroup = $obElement->primary_group()->first();
        if (empty($obPrimaryGroup) || empty($obPrimaryGroup->price_type_id)) {
            return false;
        }

        return $obPrimaryGroup->code !== $this->getOriginalSchoolCode($obElement);
    }

    /**
     * The school code as loaded from the database. Read from the raw original because
     * "property" is jsonable.
     * @param \RainLab\User\Models\User $obElement
     * @return string|null
     */
    protected function getOriginalSchoolCode($obElement)
    {
        $arOriginalProperty = json_decode((string) $obElement->getRawOriginal('property'), true);
        if (!is_array($arOriginalProperty)) {
            return null;
        }

        return $arOriginalProperty['school-name'] ?? null;
    }

    /**
     * The secondary groups hold the primary group only, so group filters and member
     * counts match the price tier. afterSave runs before Eloquent syncs the originals.
     * @param \RainLab\User\Models\User $obElement
     */
    protected function mirrorPrimaryGroup($obElement)
    {
        if (!$obElement->isDirty('primary_group_id')) {
            return;
        }

        $obElement->groups()->sync(array_filter([$obElement->primary_group_id]));
    }
}
