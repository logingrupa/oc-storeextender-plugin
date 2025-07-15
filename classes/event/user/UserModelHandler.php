<?php namespace logingrupa\Storeextender\Classes\Event\User;

use Lang;
use Lovata\Buddies\Models\User;
use Lovata\Buddies\Models\Group;
use Illuminate\Support\Facades\Log;

class UserModelHandler
{
    public function subscribe()
    {
        User::extend(function ($obElement) {
            $this->extendUserModel($obElement);
        });
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
        $obElement->customMessages['property.security.required'] = Lang::get('logingrupa.storeextender::lang.message.e_security_required');
        $obElement->customMessages['property.security.in'] = Lang::get('logingrupa.storeextender::lang.message.e_security_in');
    }

    protected function attachUserToGroup($obElement)
    {
        $sPropertyCode = $obElement->property['school-name'] ?? null;
        
        if (!$sPropertyCode) {
            return;
        }

        // Find the group by code
        $group = Group::where('code', $sPropertyCode)->first();

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