<?php namespace Logingrupa\StoreExtender\Classes\Helper;

use Illuminate\Support\Facades\DB;
use October\Rain\Support\Traits\Singleton;

/**
 * Class BuddiesUserChecksum
 * @package Logingrupa\StoreExtender\Classes\Helper
 *
 * Per column checksums over the Lovata.Buddies tables and their RainLab.User targets, so
 * the data port is proved rather than assumed.
 *
 * BIT_XOR over the top 64 bits of SHA2("id # value") is insensitive to row order but
 * sensitive to which id a value lands under, which is exactly the property the id contract
 * needs. The NULL sentinel keeps an empty string distinguishable from a missing value.
 *
 * SHA2 rather than CRC32: CRC32 is linear over GF(2), so BIT_XOR over equal length inputs
 * collapses to a checksum of the XOR of the raw bytes. Three property columns produced an
 * identical digest under CRC32 because their values are permutations of the digits 1 to 9.
 *
 * Every target side query is scoped to the ported id set, so rows created natively after
 * the port do not make the comparison fail.
 */
class BuddiesUserChecksum
{
    use Singleton;

    const NULL_SENTINEL = '~NULL~';

    const SOURCE_USER_TABLE = 'lovata_buddies_users';
    const SOURCE_GROUP_TABLE = 'lovata_buddies_groups';
    const SOURCE_MEMBERSHIP_TABLE = 'lovata_buddies_users_groups';
    const SOURCE_PROPERTY_TABLE = 'lovata_buddies_addition_properties';

    const TARGET_USER_TABLE = 'users';
    const TARGET_GROUP_TABLE = 'user_groups';
    const TARGET_MEMBERSHIP_TABLE = 'users_groups';
    const TARGET_PROPERTY_TABLE = 'logingrupa_storeextender_user_properties';

    /**
     * Section definitions: target column => source expression.
     *
     * "username" is seeded from email, "first_name" takes the Buddies "name", "last_seen"
     * takes "last_login", and "activated_at" absorbs the "is_activated" flag, which
     * RainLab exposes as an accessor rather than a column.
     *
     * "persist_code" is deliberately absent: Buddies hashes it, RainLab does not, so the
     * port nulls it and there is nothing to compare.
     */
    const SECTION_LIST = [
        'users' => [
            'source' => self::SOURCE_USER_TABLE,
            'target' => self::TARGET_USER_TABLE,
            'column' => [
                'id'              => 'id',
                'email'           => 'email',
                'username'        => 'email',
                'password'        => 'password',
                'first_name'      => 'name',
                'last_name'       => 'last_name',
                'activation_code' => 'activation_code',
                'activated_at'    => 'COALESCE(activated_at, IF(is_activated = 1, created_at, NULL))',
                'last_seen'       => 'last_login',
                'phone'           => 'phone',
                'phone_short'     => 'phone_short',
                'property'        => 'property',
                'viewed_products' => 'viewed_products',
                'created_at'      => 'created_at',
                'updated_at'      => 'updated_at',
                'deleted_at'      => 'deleted_at',
            ],
        ],
        'groups' => [
            'source' => self::SOURCE_GROUP_TABLE,
            'target' => self::TARGET_GROUP_TABLE,
            'column' => [
                'id'            => 'id',
                'name'          => 'name',
                'code'          => 'code',
                'description'   => 'description',
                'price_type_id' => 'price_type_id',
                'created_at'    => 'created_at',
                'updated_at'    => 'updated_at',
            ],
        ],
        'properties' => [
            'source' => self::SOURCE_PROPERTY_TABLE,
            'target' => self::TARGET_PROPERTY_TABLE,
            'column' => [
                'id'          => 'id',
                'active'      => 'active',
                'name'        => 'name',
                'slug'        => 'slug',
                'code'        => 'code',
                'description' => 'description',
                'type'        => 'type',
                'settings'    => 'settings',
                'sort_order'  => 'sort_order',
                'created_at'  => 'created_at',
                'updated_at'  => 'updated_at',
            ],
        ],
    ];

    /**
     * Compare every section, column by column
     * @return array list of ['section', 'column', 'source', 'target', 'match']
     */
    public function compare()
    {
        $arResult = [];

        foreach (self::SECTION_LIST as $sSection => $arSection) {
            $sScope = 'id IN (SELECT id FROM `'.$arSection['source'].'`)';

            foreach ($arSection['column'] as $sTargetColumn => $sSourceExpression) {
                $arSource = $this->digest($arSection['source'], $sSourceExpression, '');
                $arTarget = $this->digest($arSection['target'], '`'.$sTargetColumn.'`', $sScope);

                $arResult[] = [
                    'section' => $sSection,
                    'column'  => $sTargetColumn,
                    'source'  => $arSource,
                    'target'  => $arTarget,
                    'match'   => $arSource === $arTarget,
                ];
            }
        }

        $arResult[] = $this->compareMemberships();

        return $arResult;
    }

    /**
     * The membership pivot has no id column, so the pair itself is the key
     * @return array
     */
    protected function compareMemberships()
    {
        $arSource = $this->pairDigest(self::SOURCE_MEMBERSHIP_TABLE, 'user_id', 'group_id', '');
        $arTarget = $this->pairDigest(
            self::TARGET_MEMBERSHIP_TABLE,
            'user_id',
            'user_group_id',
            'user_id IN (SELECT id FROM `'.self::SOURCE_USER_TABLE.'`)'
        );

        return [
            'section' => 'memberships',
            'column'  => 'user_id + group_id',
            'source'  => $arSource,
            'target'  => $arTarget,
            'match'   => $arSource === $arTarget,
        ];
    }

    /**
     * The top 64 bits of SHA2, as an unsigned integer BIT_XOR can aggregate
     * @param string $sExpression the value being hashed
     * @return string
     */
    protected function rowDigestExpression($sExpression)
    {
        return 'CONV(SUBSTRING(SHA2('.$sExpression.', 256), 1, 16), 16, 10)';
    }

    /**
     * Row count, non null count and BIT_XOR digest for one column
     * @param string $sTable
     * @param string $sExpression quoted column or raw SQL expression
     * @param string $sScope      optional WHERE body
     * @return array
     */
    protected function digest($sTable, $sExpression, $sScope)
    {
        $sSql = 'SELECT COUNT(*) AS row_count, COUNT('.$sExpression.') AS filled_count, '
            .'BIT_XOR('.$this->rowDigestExpression('CONCAT_WS(\'#\', `id`, IFNULL('.$sExpression.', ?))').') AS digest '
            .'FROM `'.$sTable.'`'
            .($sScope === '' ? '' : ' WHERE '.$sScope);

        $obRow = DB::selectOne($sSql, [self::NULL_SENTINEL]);

        return [
            'rows'   => (int) $obRow->row_count,
            'filled' => (int) $obRow->filled_count,
            'digest' => (string) $obRow->digest,
        ];
    }

    /**
     * Same digest over a two column pivot
     * @param string $sTable
     * @param string $sFirstColumn
     * @param string $sSecondColumn
     * @param string $sScope
     * @return array
     */
    protected function pairDigest($sTable, $sFirstColumn, $sSecondColumn, $sScope)
    {
        $sSql = 'SELECT COUNT(*) AS row_count, '
            .'BIT_XOR('.$this->rowDigestExpression('CONCAT_WS(\'#\', `'.$sFirstColumn.'`, `'.$sSecondColumn.'`)').') AS digest '
            .'FROM `'.$sTable.'`'
            .($sScope === '' ? '' : ' WHERE '.$sScope);

        $obRow = DB::selectOne($sSql);

        return [
            'rows'   => (int) $obRow->row_count,
            'filled' => (int) $obRow->row_count,
            'digest' => (string) $obRow->digest,
        ];
    }
}
