<?php namespace Logingrupa\StoreExtender\Classes\Helper;

use Schema;
use Illuminate\Support\Facades\DB;
use October\Rain\Support\Traits\Singleton;

/**
 * Class BuddiesUserPorter
 * @package Logingrupa\StoreExtender\Classes\Helper
 *
 * Copies Lovata.Buddies users, groups, memberships and dynamic property definitions onto
 * the RainLab.User tables.
 *
 * Set based SQL rather than model saves, for three reasons: RainLab's beforeCreate would
 * overwrite primary_group_id, its validation would reject rows the shop has carried for
 * years, and 13820 model saves cost minutes where one statement costs seconds.
 *
 * Every write is INSERT ... ON DUPLICATE KEY UPDATE keyed on the original id, so a second
 * run rewrites the same values and changes nothing.
 *
 * Ids are preserved verbatim. Ten tables hold Buddies user ids with no foreign key to
 * protect them, so a renumbering would silently detach orders, carts and wish lists.
 */
class BuddiesUserPorter
{
    use Singleton;

    const SOURCE_USER_TABLE = 'lovata_buddies_users';
    const SOURCE_GROUP_TABLE = 'lovata_buddies_groups';
    const SOURCE_MEMBERSHIP_TABLE = 'lovata_buddies_users_groups';
    const SOURCE_PROPERTY_TABLE = 'lovata_buddies_addition_properties';

    const TARGET_USER_TABLE = 'users';
    const TARGET_GROUP_TABLE = 'user_groups';
    const TARGET_MEMBERSHIP_TABLE = 'users_groups';
    const TARGET_PROPERTY_TABLE = 'logingrupa_storeextender_user_properties';

    const REGISTERED_GROUP_CODE = 'registered';

    /** Columns the C1 migrations add to the RainLab tables. Missing means C1 never ran. */
    const REQUIRED_USER_COLUMN_LIST = ['phone', 'phone_short', 'property', 'viewed_products'];
    const REQUIRED_GROUP_COLUMN_LIST = ['price_type_id'];

    /**
     * Blocking problems, checked before anything is written
     * @return array list of messages, empty when the port may proceed
     */
    public function getBlockerList()
    {
        $arResult = [];

        foreach ([self::SOURCE_USER_TABLE, self::SOURCE_GROUP_TABLE, self::SOURCE_MEMBERSHIP_TABLE, self::SOURCE_PROPERTY_TABLE] as $sTable) {
            if (!Schema::hasTable($sTable)) {
                $arResult[] = 'Source table "'.$sTable.'" is missing. The port reads the lovata_buddies_* staging tables; restore or reload them first.';
            }
        }

        foreach ([self::TARGET_USER_TABLE, self::TARGET_GROUP_TABLE, self::TARGET_MEMBERSHIP_TABLE, self::TARGET_PROPERTY_TABLE] as $sTable) {
            if (!Schema::hasTable($sTable)) {
                $arResult[] = 'Target table "'.$sTable.'" is missing. Run "october:migrate" first.';
            }
        }

        if (!empty($arResult)) {
            return $arResult;
        }

        foreach (self::REQUIRED_USER_COLUMN_LIST as $sColumn) {
            if (!Schema::hasColumn(self::TARGET_USER_TABLE, $sColumn)) {
                $arResult[] = 'Column "'.self::TARGET_USER_TABLE.'.'.$sColumn.'" is missing. Run "october:migrate" first.';
            }
        }

        foreach (self::REQUIRED_GROUP_COLUMN_LIST as $sColumn) {
            if (!Schema::hasColumn(self::TARGET_GROUP_TABLE, $sColumn)) {
                $arResult[] = 'Column "'.self::TARGET_GROUP_TABLE.'.'.$sColumn.'" is missing. Run "october:migrate" first.';
            }
        }

        $arResult = array_merge($arResult, $this->getDataBlockerList());

        return $arResult;
    }

    /**
     * Data level blockers: anything the port cannot represent without losing a user
     * @return array
     */
    protected function getDataBlockerList()
    {
        $arResult = [];

        // RainLab looks users up by email. Two rows sharing one address make the lookup
        // ambiguous and one of the two accounts unreachable.
        $iDuplicateEmail = DB::table(self::SOURCE_USER_TABLE)
            ->select('email')
            ->groupBy('email')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();

        if ($iDuplicateEmail > 0) {
            $arResult[] = 'Source holds '.$iDuplicateEmail.' duplicate email addresses. Resolve them before porting.';
        }

        // An id already taken by a different RainLab account cannot be overwritten: the
        // ten tables that reference user ids would follow the wrong person.
        $iOccupiedId = DB::table(self::TARGET_USER_TABLE.' as t')
            ->join(self::SOURCE_USER_TABLE.' as s', 's.id', '=', 't.id')
            ->whereRaw('t.email <> s.email')
            ->count();

        if ($iOccupiedId > 0) {
            $arResult[] = 'RainLab holds '.$iOccupiedId.' user ids that belong to a different account than the Buddies row with the same id.';
        }

        // A RainLab group already carrying a Buddies code, at another id, would leave two
        // rows fighting over the same code and the same price type.
        $iAmbiguousGroup = DB::table(self::TARGET_GROUP_TABLE.' as t')
            ->join(self::SOURCE_GROUP_TABLE.' as s', 's.code', '=', 't.code')
            ->whereRaw('t.id <> s.id')
            ->count();

        if ($iAmbiguousGroup > 0) {
            $arResult[] = 'RainLab holds '.$iAmbiguousGroup.' group(s) with a Buddies code under a different id.';
        }

        return $arResult;
    }

    /**
     * What the run would touch, for the dry run report
     * @return array
     */
    public function getPlan()
    {
        return [
            'users to insert'          => $this->countMissing(self::SOURCE_USER_TABLE, self::TARGET_USER_TABLE),
            'users to rewrite'         => $this->countPresent(self::SOURCE_USER_TABLE, self::TARGET_USER_TABLE),
            'groups to insert'         => $this->countMissing(self::SOURCE_GROUP_TABLE, self::TARGET_GROUP_TABLE),
            'groups to rewrite'        => $this->countPresent(self::SOURCE_GROUP_TABLE, self::TARGET_GROUP_TABLE),
            'properties to insert'     => $this->countMissing(self::SOURCE_PROPERTY_TABLE, self::TARGET_PROPERTY_TABLE),
            'properties to rewrite'    => $this->countPresent(self::SOURCE_PROPERTY_TABLE, self::TARGET_PROPERTY_TABLE),
            'memberships in source'    => DB::table(self::SOURCE_MEMBERSHIP_TABLE)->count(),
            'memberships in target'    => DB::table(self::TARGET_MEMBERSHIP_TABLE)->count(),
            'seeded groups to relocate' => count($this->getCollidingGroupList()),
        ];
    }

    /**
     * Run the whole port inside one transaction
     * @return array step name => affected row count
     */
    public function port()
    {
        $arResult = [];

        DB::transaction(function () use (&$arResult) {
            $arResult['relocated groups'] = count($this->relocateCollidingGroups());
            $arResult['groups'] = $this->portGroups();
            $arResult['users'] = $this->portUsers();
            $arResult['memberships'] = $this->portMemberships();
            $arResult['properties'] = $this->portProperties();
            $arResult['school primary groups'] = $this->promoteSchoolPrimaryGroups();
        });

        // ALTER TABLE commits implicitly, so it stays outside the transaction above.
        $this->resetAutoIncrement();

        return $arResult;
    }

    /**
     * RainLab seeds user_groups with "guest" at id 1 and "registered" at id 2, the ids
     * Buddies uses for "authorized" and "salona". Group ids drive price_type_id, which
     * drives user group pricing, so the Buddies ids win and the seeded rows move up.
     *
     * Their codes are untouched: getGuestGroup() and getRegisteredGroup() resolve by code.
     *
     * @return array old id => new id
     */
    public function relocateCollidingGroups()
    {
        $arCollidingList = $this->getCollidingGroupList();
        if (empty($arCollidingList)) {
            return [];
        }

        $iNextID = (int) max(
            (int) DB::table(self::TARGET_GROUP_TABLE)->max('id'),
            (int) DB::table(self::SOURCE_GROUP_TABLE)->max('id')
        );

        $arResult = [];
        foreach ($arCollidingList as $iOldID) {
            $iNextID++;

            DB::table(self::TARGET_GROUP_TABLE)->where('id', $iOldID)->update(['id' => $iNextID]);
            DB::table(self::TARGET_MEMBERSHIP_TABLE)->where('user_group_id', $iOldID)->update(['user_group_id' => $iNextID]);
            DB::table(self::TARGET_USER_TABLE)->where('primary_group_id', $iOldID)->update(['primary_group_id' => $iNextID]);

            $arResult[$iOldID] = $iNextID;
        }

        return $arResult;
    }

    /**
     * Existing RainLab group ids that a Buddies group claims, excluding groups already
     * ported (matched by code), which are rewritten in place instead.
     * @return array of int
     */
    protected function getCollidingGroupList()
    {
        return DB::table(self::TARGET_GROUP_TABLE.' as t')
            ->join(self::SOURCE_GROUP_TABLE.' as s', 's.id', '=', 't.id')
            ->whereRaw('t.code NOT IN (SELECT code FROM `'.self::SOURCE_GROUP_TABLE.'`)')
            ->orderBy('t.id')
            ->pluck('t.id')
            ->map(function ($iID) {
                return (int) $iID;
            })
            ->all();
    }

    /**
     * @return int
     */
    protected function portGroups()
    {
        $arColumnList = ['id', 'name', 'code', 'description', 'price_type_id', 'created_at', 'updated_at'];

        return $this->upsert(
            self::TARGET_GROUP_TABLE,
            $arColumnList,
            'SELECT `id`, `name`, `code`, `description`, `price_type_id`, `created_at`, `updated_at`'
            .' FROM `'.self::SOURCE_GROUP_TABLE.'` ORDER BY `id`',
            ['id']
        );
    }

    /**
     * @return int
     */
    protected function portUsers()
    {
        $iRegisteredGroupID = $this->getRegisteredGroupID();

        $arColumnList = [
            'id', 'is_guest', 'is_mail_blocked', 'first_name', 'last_name', 'username', 'email',
            'password', 'activation_code', 'persist_code', 'primary_group_id', 'activated_at',
            'last_seen', 'deleted_at', 'created_at', 'updated_at', 'phone', 'phone_short',
            'property', 'viewed_products',
        ];

        // persist_code is nulled on purpose: Buddies hashes it, RainLab does not, so a
        // copied value would never match and every session is re-established at next login.
        // is_activated is a RainLab accessor over activated_at, so the flag is folded in;
        // without the created_at fallback an activated user with no timestamp cannot log in.
        $sSelect = 'SELECT `id`, 0, 0, `name`, `last_name`, `email`, `email`,'
            .' `password`, `activation_code`, NULL, '.($iRegisteredGroupID === null ? 'NULL' : $iRegisteredGroupID).','
            .' COALESCE(`activated_at`, IF(`is_activated` = 1, `created_at`, NULL)),'
            .' `last_login`, `deleted_at`, `created_at`, `updated_at`, `phone`, `phone_short`,'
            .' `property`, `viewed_products`'
            .' FROM `'.self::SOURCE_USER_TABLE.'` ORDER BY `id`';

        return $this->upsert(self::TARGET_USER_TABLE, $arColumnList, $sSelect, ['id']);
    }

    /**
     * @return int
     */
    protected function portMemberships()
    {
        // A membership revoked at the source must disappear from the target on a re-sync,
        // so target-only rows are deleted first - but only for users the source knows:
        // native accounts above the ported range keep every membership they hold.
        $iDeleted = DB::affectingStatement(
            'DELETE ug FROM `'.self::TARGET_MEMBERSHIP_TABLE.'` AS ug'
            .' JOIN `'.self::SOURCE_USER_TABLE.'` AS s ON s.`id` = ug.`user_id`'
            .' LEFT JOIN `'.self::SOURCE_MEMBERSHIP_TABLE.'` AS b'
            .' ON b.`user_id` = ug.`user_id` AND b.`group_id` = ug.`user_group_id`'
            .' WHERE b.`user_id` IS NULL'
        );

        // Inserted in (user_id, group_id) order so the pivot scans back in the same order
        // Buddies produced. ActivePriceHelper reads groups->first(), so the order picks the
        // price type for the 62 users who belong to two groups.
        return $iDeleted + $this->upsert(
            self::TARGET_MEMBERSHIP_TABLE,
            ['user_id', 'user_group_id'],
            'SELECT `user_id`, `group_id` FROM `'.self::SOURCE_MEMBERSHIP_TABLE.'`'
            .' ORDER BY `user_id`, `group_id`',
            ['user_id', 'user_group_id']
        );
    }

    /**
     * @return int
     */
    protected function portProperties()
    {
        $arColumnList = [
            'id', 'active', 'name', 'slug', 'code', 'description', 'type', 'settings',
            'sort_order', 'created_at', 'updated_at',
        ];

        $sSelect = 'SELECT `id`, `active`, `name`, `slug`, `code`, `description`, `type`, `settings`,'
            .' `sort_order`, `created_at`, `updated_at`'
            .' FROM `'.self::SOURCE_PROPERTY_TABLE.'` ORDER BY `id`';

        return $this->upsert(self::TARGET_PROPERTY_TABLE, $arColumnList, $sSelect, ['id']);
    }

    /**
     * INSERT ... SELECT ... ON DUPLICATE KEY UPDATE over every non key column.
     *
     * VALUES() rather than the row alias form: the alias syntax is MySQL 8.0.19+ only and
     * these statements also have to run against MariaDB.
     *
     * @param string $sTable
     * @param array  $arColumnList
     * @param string $sSelect
     * @param array  $arKeyColumnList columns excluded from the update clause
     * @return int
     */
    protected function upsert($sTable, array $arColumnList, $sSelect, array $arKeyColumnList)
    {
        $arUpdateList = [];
        foreach ($arColumnList as $sColumn) {
            if (in_array($sColumn, $arKeyColumnList)) {
                continue;
            }

            $arUpdateList[] = '`'.$sColumn.'` = VALUES(`'.$sColumn.'`)';
        }

        if (empty($arUpdateList)) {
            // A pure key table has nothing to rewrite; assigning the key to itself keeps
            // the statement a no-op on collision instead of an error.
            $arUpdateList[] = '`'.$arKeyColumnList[0].'` = VALUES(`'.$arKeyColumnList[0].'`)';
        }

        $sSql = 'INSERT INTO `'.$sTable.'` (`'.implode('`, `', $arColumnList).'`) '
            .$sSelect
            .' ON DUPLICATE KEY UPDATE '.implode(', ', $arUpdateList);

        return DB::affectingStatement($sSql);
    }

    /**
     * The school picked at registration (property["school-name"]) is the user's group of
     * record, so it becomes RainLab's primary group, matching what UserModelHandler does
     * for users registering after the cutover. Users without a school keep "registered".
     *
     * JSON_EXTRACT returns utf8mb4_bin, so the value is re-collated before matching the
     * group code, and invalid or NULL property payloads are folded to an empty document
     * inside the expression - a WHERE guard alone would not stop MySQL evaluating the
     * join condition on a malformed row.
     * @return int
     */
    protected function promoteSchoolPrimaryGroups()
    {
        $sSchoolCode = 'CONVERT(JSON_UNQUOTE(JSON_EXTRACT('
            ."IF(JSON_VALID(u.`property`), u.`property`, '{}'), '$.\"school-name\"'"
            .')) USING utf8mb4) COLLATE utf8mb4_unicode_ci';

        return DB::affectingStatement(
            'UPDATE `'.self::TARGET_USER_TABLE.'` AS u'
            .' JOIN `'.self::SOURCE_USER_TABLE.'` AS s ON s.`id` = u.`id`'
            .' JOIN `'.self::TARGET_GROUP_TABLE.'` AS g ON g.`code` = '.$sSchoolCode
            .' SET u.`primary_group_id` = g.`id`'
            ." WHERE g.`code` NOT IN ('".implode("', '", UserGroupHelper::SEEDED_GROUP_CODES)."')"
        );
    }

    /**
     * RainLab's beforeCreate stamps primary_group_id on natively registered users. The
     * ported rows never pass through it, so the same value is written here and the backend
     * group filter keeps working.
     * @return int|null
     */
    protected function getRegisteredGroupID()
    {
        $iGroupID = DB::table(self::TARGET_GROUP_TABLE)
            ->where('code', self::REGISTERED_GROUP_CODE)
            ->value('id');

        return $iGroupID === null ? null : (int) $iGroupID;
    }

    /**
     * Explicit ids leave AUTO_INCREMENT behind on a relocated row, so the next natively
     * created record would collide.
     */
    protected function resetAutoIncrement()
    {
        foreach ([self::TARGET_USER_TABLE, self::TARGET_GROUP_TABLE, self::TARGET_PROPERTY_TABLE] as $sTable) {
            $iNextID = (int) DB::table($sTable)->max('id') + 1;

            DB::statement('ALTER TABLE `'.$sTable.'` AUTO_INCREMENT = '.$iNextID);
        }
    }

    /**
     * @param string $sSourceTable
     * @param string $sTargetTable
     * @return int
     */
    protected function countMissing($sSourceTable, $sTargetTable)
    {
        return DB::table($sSourceTable.' as s')
            ->whereRaw('s.id NOT IN (SELECT id FROM `'.$sTargetTable.'`)')
            ->count();
    }

    /**
     * @param string $sSourceTable
     * @param string $sTargetTable
     * @return int
     */
    protected function countPresent($sSourceTable, $sTargetTable)
    {
        return DB::table($sSourceTable.' as s')
            ->whereRaw('s.id IN (SELECT id FROM `'.$sTargetTable.'`)')
            ->count();
    }
}
