<?php namespace Logingrupa\StoreExtender\Updates;

use Schema;
use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;
use System\Classes\PluginManager;

/**
 * Class UpdateTableUserGroup
 * @package Logingrupa\StoreExtender\Updates
 */
class UpdateTableUserGroup extends Migration
{
    /**
     * Get the table name based on active plugin
     * @return string|null
     */
    protected function getTableName()
    {
        $pluginManager = PluginManager::instance();
        
        if ($pluginManager->hasPlugin('Lovata.Buddies')) {
            return 'lovata_buddies_users';
        } elseif ($pluginManager->hasPlugin('RainLab.User')) {
            return 'users';
        }
        
        return null;
    }

    /**
     * Apply migration
     */
    public function up()
    {
        $tableName = $this->getTableName();
        
        if (!$tableName || !Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'price_type_id')) {
            return;
        }

        Schema::table($tableName, function (Blueprint $obTable) {
            $obTable->integer('price_type_id')->nullable();
        });
    }

    /**
     * Rollback migration
     */
    public function down()
    {
        $tableName = $this->getTableName();
        
        if (!$tableName || !Schema::hasTable($tableName) || !Schema::hasColumn($tableName, 'price_type_id')) {
            return;
        }

        Schema::table($tableName, function (Blueprint $obTable) {
            $obTable->dropColumn(['price_type_id']);
        });
    }
}
