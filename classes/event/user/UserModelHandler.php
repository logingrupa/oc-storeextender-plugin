<?php namespace logingrupa\Storeextender\Classes\Event\User;

use Lang;
use Illuminate\Support\Facades\Log;

use Lovata\Toolbox\Classes\Helper\UserHelper;
use Logingrupa\StoreExtender\Classes\Helper\UserGroupHelper;

class UserModelHandler
{
    const BUDDIES_PLUGIN_NAME = 'Lovata.Buddies';

    public function subscribe()
    {
        // UserHelper resolves through PluginManager::exists(), which honours the disabled
        // flag; hasPlugin() does not. Asking the same seam as every other handler keeps a
        // disabled Buddies from leaving the model half switched.
        $sPluginName = UserHelper::instance()->getPluginName();

        if ($sPluginName == self::BUDDIES_PLUGIN_NAME) {
            \Lovata\Buddies\Models\User::extend(function ($obElement) {
                $this->extendUserModel($obElement);
            });
        } elseif (!empty($sPluginName)) {
            $obRainLabExtension = new ExtendRainLabUserModel();

            \RainLab\User\Models\User::extend(function ($obElement) use ($obRainLabExtension) {
                $obRainLabExtension->extend($obElement);
                $this->extendUserModel($obElement);
            });
        }
    }

    protected function extendUserModel($obElement)
    {
        $this->addValidationRules($obElement);
        
        $obElement->bindEvent('model.afterCreate', function() use ($obElement) {
            $this->attachUserToGroup($obElement);
        });

        $obElement->bindEvent('model.afterSave', function() use ($obElement) {
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

    protected function attachUserToGroup($obElement)
    {
        $sPropertyCode = $obElement->property['school-name'] ?? null;
        
        if (!$sPropertyCode) {
            return;
        }

        $group = UserGroupHelper::instance()->findByCode($sPropertyCode);

        if (!$group) {
            Log::warning("Group with code '{$sPropertyCode}' not found.");
            return;
        }

        // Attach without detaching: a user can hold several groups and sync() would
        // drop every group this handler did not name.
        try {
            $obElement->groups()->syncWithoutDetaching([$group->id]);
        } catch (\Exception $e) {
            Log::error("Failed to attach user to group: {$e->getMessage()}");

            return;
        }

        $this->makeSchoolGroupPrimary($obElement, $group);
    }

    /**
     * The chosen school is the user's group of record, so it also becomes RainLab's
     * primary group (the one the backend shows). Written with a quiet query: this
     * runs inside afterSave, and a model save here would re-fire it.
     */
    protected function makeSchoolGroupPrimary($obElement, $obGroup)
    {
        // Buddies has no primary group concept
        if (!$obElement instanceof \RainLab\User\Models\User) {
            return;
        }

        if ((int) $obElement->primary_group_id === (int) $obGroup->id) {
            return;
        }

        $obElement->newQuery()->whereKey($obElement->getKey())->update(['primary_group_id' => $obGroup->id]);
        $obElement->primary_group_id = $obGroup->id;
        $obElement->syncOriginalAttribute('primary_group_id');
    }
}