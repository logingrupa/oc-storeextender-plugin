<?php namespace logingrupa\Storeextender\Classes\Event\User;

use Lang;
use Illuminate\Support\Facades\Log;

class UserModelHandler
{
    public function subscribe()
    {
        $pluginManager = \System\Classes\PluginManager::instance();
        
        // Check which user plugin is available and extend accordingly
        if ($pluginManager->hasPlugin('Lovata.Buddies')) {
            \Lovata\Buddies\Models\User::extend(function ($obElement) {
                $this->extendUserModel($obElement);
            });
        } elseif ($pluginManager->hasPlugin('RainLab.User')) {
            \RainLab\User\Models\User::extend(function ($obElement) {
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

        $pluginManager = \System\Classes\PluginManager::instance();
        $group = null;

        // Find the group by code using the appropriate model
        if ($pluginManager->hasPlugin('Lovata.Buddies')) {
            $group = \Lovata\Buddies\Models\Group::where('code', $sPropertyCode)->first();
        } elseif ($pluginManager->hasPlugin('RainLab.User')) {
            $group = \RainLab\User\Models\UserGroup::where('code', $sPropertyCode)->first();
        }

        if (!$group) {
            Log::warning("Group with code '{$sPropertyCode}' not found.");
            return;
        }

        // Attach user to the group without detaching existing ones
        try {
            $obElement->groups()->sync([$group->id]);
        } catch (\Exception $e) {
            Log::error("Failed to attach user to group: {$e->getMessage()}");
        }
    }
}