<?php namespace Logingrupa\StoreExtender\Updates;

use DB;
use Schema;
use October\Rain\Database\Updates\Migration;

/**
 * Class UpdateTableUsersPrimaryGroupFromPriceGroup
 *
 * The price tier follows the primary group. The Buddies port gave every user without a
 * school the "registered" primary group and kept the tier in the secondary groups pivot,
 * so each such user gets the lowest id pivot group that carries a price type as primary.
 * The pivot then holds the primary group only, and the legacy "authorized" group goes.
 *
 * @package Logingrupa\StoreExtender\Updates
 */
class UpdateTableUsersPrimaryGroupFromPriceGroup extends Migration
{
    /** Owner ruling 2026-09-29: this account keeps the distributor tier it pays today. */
    const PRIMARY_GROUP_OVERRIDE_LIST = [
        'nailscosmetics.slovenija@gmail.com' => 'distributor',
    ];

    const LEGACY_GROUP_CODE = 'authorized';

    const CHUNK_SIZE = 500;

    /**
     * Apply migration
     */
    public function up()
    {
        if (!Schema::hasTable('users_groups') || !Schema::hasColumn('user_groups', 'price_type_id')) {
            return;
        }

        DB::transaction(function () {
            $this->promotePriceGroups();
            $this->applyOverrides();
            DB::table('user_groups')->where('code', self::LEGACY_GROUP_CODE)->whereNull('price_type_id')->delete();
            $this->mirrorPrimaryGroups();
        });
    }

    /**
     * Rollback migration
     *
     * The old pivot tiers cannot be derived from the new state, so the change is one way.
     * The rollback is the database backup taken before the release.
     */
    public function down()
    {
    }

    /**
     * Users whose primary group has no price type take their lowest id priced pivot group
     */
    protected function promotePriceGroups()
    {
        $arPriceGroupIDList = DB::table('user_groups')->whereNotNull('price_type_id')->pluck('id')->all();

        $arTargetGroupList = DB::table('users_groups')
            ->join('users', 'users.id', '=', 'users_groups.user_id')
            ->whereIn('users_groups.user_group_id', $arPriceGroupIDList)
            ->where(function ($obQuery) use ($arPriceGroupIDList) {
                $obQuery->whereNull('users.primary_group_id')->orWhereNotIn('users.primary_group_id', $arPriceGroupIDList);
            })
            ->groupBy('users_groups.user_id')
            ->selectRaw('users_groups.user_id, MIN(users_groups.user_group_id) AS group_id')
            ->pluck('group_id', 'user_id')
            ->all();

        $arUserIDListByGroup = [];
        foreach ($arTargetGroupList as $iUserID => $iGroupID) {
            $arUserIDListByGroup[$iGroupID][] = $iUserID;
        }

        foreach ($arUserIDListByGroup as $iGroupID => $arUserIDList) {
            foreach (array_chunk($arUserIDList, self::CHUNK_SIZE) as $arUserIDChunk) {
                DB::table('users')->whereIn('id', $arUserIDChunk)->update(['primary_group_id' => $iGroupID]);
            }
        }
    }

    /**
     * An override applies only to a user who holds the group today, so the same
     * address on a site with an empty pivot stays as it is
     */
    protected function applyOverrides()
    {
        foreach (self::PRIMARY_GROUP_OVERRIDE_LIST as $sEmail => $sGroupCode) {
            $obMembership = DB::table('users_groups')
                ->join('users', 'users.id', '=', 'users_groups.user_id')
                ->join('user_groups', 'user_groups.id', '=', 'users_groups.user_group_id')
                ->where('users.email', $sEmail)
                ->where('user_groups.code', $sGroupCode)
                ->first(['users_groups.user_id', 'users_groups.user_group_id']);

            if (empty($obMembership)) {
                continue;
            }

            DB::table('users')->where('id', $obMembership->user_id)->update(['primary_group_id' => $obMembership->user_group_id]);
        }
    }

    /**
     * Rewrites the pivot to one row per user, equal to the primary group
     */
    protected function mirrorPrimaryGroups()
    {
        DB::table('users_groups')->delete();

        DB::table('users_groups')->insertUsing(
            ['user_id', 'user_group_id'],
            DB::table('users')
                ->join('user_groups', 'user_groups.id', '=', 'users.primary_group_id')
                ->select('users.id', 'users.primary_group_id')
        );
    }
}
