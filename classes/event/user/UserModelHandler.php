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

        // afterSave also fires on create, so one binding covers registration and later edits
        $obElement->bindEvent('model.afterSave', function () use ($obElement) {
            $this->attachUserToGroup($obElement);
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
     * Runs only when the school changed: every other save (login stamp, checkout phone,
     * backend edit) leaves the groups alone.
     */
    protected function attachUserToGroup($obElement)
    {
        $sPropertyCode = $obElement->property['school-name'] ?? null;
        if (!$sPropertyCode || $sPropertyCode === $this->getOriginalSchoolCode($obElement)) {
            return;
        }

        $obGroup = UserGroupHelper::instance()->findByCode($sPropertyCode);
        if (!$obGroup) {
            Log::warning("Group with code '{$sPropertyCode}' not found.");
            return;
        }

        // Attach without detaching: a user can hold several groups and sync() would
        // drop every group this handler did not name.
        try {
            $obElement->groups()->syncWithoutDetaching([$obGroup->id]);
        } catch (\Exception $obException) {
            Log::error("Failed to attach user to group: {$obException->getMessage()}");

            return;
        }

        $this->makeSchoolGroupPrimary($obElement, $obGroup);
    }

    /**
     * The school code as loaded from the database. Read from the raw original because
     * "property" is jsonable and afterSave runs before Eloquent syncs the originals.
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
     * The chosen school is the user's group of record, so it also becomes RainLab's
     * primary group (the one the backend shows). Written with a quiet query: this
     * runs inside afterSave, and a model save here would re-fire it.
     */
    protected function makeSchoolGroupPrimary($obElement, $obGroup)
    {
        if ((int) $obElement->primary_group_id === (int) $obGroup->id) {
            return;
        }

        $obElement->newQuery()->whereKey($obElement->getKey())->update(['primary_group_id' => $obGroup->id]);
        $obElement->primary_group_id = $obGroup->id;
        $obElement->syncOriginalAttribute('primary_group_id');
    }
}