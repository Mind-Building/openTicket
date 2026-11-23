<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Permission;
use PHPUnit\Framework\TestCase;

/**
 * Rules of the role "Externe Partner": installation level + per-user level.
 * Only the pure functions are tested here (no DB, no session).
 */
class PermissionTest extends TestCase
{
    private const ALL = [
        Permission::SELL_CASH, Permission::SELL_CUSTOMER, Permission::RESERVE, Permission::PRICE_TYPES,
        Permission::VOUCHER_REDEEM, Permission::OWN_ORDERS, Permission::CANCEL_OWN, Permission::OCCUPANCY,
        Permission::CUSTOMERS,
    ];

    public function testAdminAndPresalesMayDoEverything(): void
    {
        foreach (['admin', 'presales'] as $role) {
            foreach (self::ALL as $feature) {
                $this->assertTrue(Permission::decide($role, $feature, false, [], []), "$role / $feature");
            }
            $this->assertTrue(Permission::decideEvent($role, 42, [1, 2]));
        }
    }

    public function testPartnerGetsNothingWhileRoleIsInactive(): void
    {
        $this->assertFalse(Permission::decide('partner', Permission::SELL_CASH, false, self::ALL, null));
    }

    public function testPartnerNeedsFeatureOnInstallationLevel(): void
    {
        $install = [Permission::SELL_CASH];
        $this->assertTrue(Permission::decide('partner', Permission::SELL_CASH, true, $install, null));
        $this->assertFalse(Permission::decide('partner', Permission::RESERVE, true, $install, null));
    }

    public function testUserLevelCanOnlyNarrowDown(): void
    {
        $install = [Permission::SELL_CASH, Permission::RESERVE];
        // user restricted to cash only
        $this->assertTrue(Permission::decide('partner', Permission::SELL_CASH, true, $install, [Permission::SELL_CASH]));
        $this->assertFalse(Permission::decide('partner', Permission::RESERVE, true, $install, [Permission::SELL_CASH]));
        // user allowed something the installation does not allow -> still denied
        $this->assertFalse(Permission::decide('partner', Permission::CUSTOMERS, true, $install, [Permission::CUSTOMERS]));
        // empty user list = nothing
        $this->assertFalse(Permission::decide('partner', Permission::SELL_CASH, true, $install, []));
    }

    public function testUnknownRoleAndFeatureAreDenied(): void
    {
        $this->assertFalse(Permission::decide(null, Permission::SELL_CASH, true, self::ALL, null));
        $this->assertFalse(Permission::decide('customer', Permission::SELL_CASH, true, self::ALL, null));
        $this->assertFalse(Permission::decide('partner', 'delete_everything', true, ['delete_everything'], null));
    }

    public function testEventRestriction(): void
    {
        $this->assertTrue(Permission::decideEvent('partner', 5, null), 'null = all events');
        $this->assertTrue(Permission::decideEvent('partner', 5, [3, 5]));
        $this->assertFalse(Permission::decideEvent('partner', 7, [3, 5]));
        $this->assertFalse(Permission::decideEvent(null, 5, null));
    }

    public function testParseUserPermissions(): void
    {
        $this->assertSame(['features' => null, 'event_ids' => null], Permission::parseUserPermissions(null));
        $this->assertSame(['features' => null, 'event_ids' => null], Permission::parseUserPermissions('not json'));

        $parsed = Permission::parseUserPermissions('{"features":["sell_cash","bogus"],"event_ids":["3","3",0,7]}');
        $this->assertSame([Permission::SELL_CASH], $parsed['features'], 'unknown features are dropped');
        $this->assertSame([3, 7], $parsed['event_ids'], 'ids are ints, unique, without 0');

        // an empty event list must not lock the partner out
        $this->assertNull(Permission::parseUserPermissions(['features' => [], 'event_ids' => []])['event_ids']);
        $this->assertSame([], Permission::parseUserPermissions(['features' => [], 'event_ids' => []])['features']);
    }

    public function testEncodeRoundTrip(): void
    {
        $json = Permission::encodeUserPermissions([Permission::SELL_CASH, Permission::OWN_ORDERS], [2, 9]);
        $parsed = Permission::parseUserPermissions($json);
        $this->assertSame([Permission::SELL_CASH, Permission::OWN_ORDERS], $parsed['features']);
        $this->assertSame([2, 9], $parsed['event_ids']);
    }

    public function testDefaultFeaturesAreTheSafeOnes(): void
    {
        $defaults = Permission::defaultFeatures();
        $this->assertContains(Permission::SELL_CASH, $defaults);
        $this->assertContains(Permission::OWN_ORDERS, $defaults);
        $this->assertNotContains(Permission::CUSTOMERS, $defaults, 'personal data only on purpose');
        $this->assertNotContains(Permission::CANCEL_OWN, $defaults);
    }
}
