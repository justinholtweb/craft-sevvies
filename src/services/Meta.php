<?php

namespace justinholtweb\sevvies\services;

use Craft;
use justinholtweb\sevvies\errors\ApiException;
use justinholtweb\sevvies\Plugin;
use yii\base\Component;

/**
 * The static data sevDesk expects you to reference by id — countries, units,
 * address categories, check accounts.
 *
 * These are looked up and cached rather than hard-coded, because the ids are
 * per-installation for some of them and quietly wrong ids produce documents
 * that look fine and book to the wrong place.
 */
class Meta extends Component
{
    private const CACHE_PREFIX = 'sevvies.meta.';
    private const TTL = 86400;

    /**
     * '1.0' or '2.0'. Decides whether documents carry taxType or taxRule, so
     * everything downstream hangs off this one answer.
     */
    public function bookkeepingVersion(bool $refresh = false): string
    {
        $configured = trim(Plugin::getInstance()->getSettings()->bookkeepingVersion);

        if ($configured !== '' && !$refresh) {
            return $configured;
        }

        $key = self::CACHE_PREFIX . 'version';
        $cache = Craft::$app->getCache();

        if (!$refresh) {
            $cached = $cache->get($key);

            if ($cached) {
                return (string)$cached;
            }
        }

        $objects = Plugin::getInstance()->api->get('Tools/bookkeepingSystemVersion');
        $version = (string)($objects['version'] ?? '2.0');
        $version = in_array($version, ['1.0', '2.0'], true) ? $version : '2.0';

        $cache->set($key, $version, self::TTL);

        return $version;
    }

    public function usesTaxRules(): bool
    {
        try {
            return $this->bookkeepingVersion() === '2.0';
        } catch (ApiException) {
            // If sevDesk cannot be asked, assume the current system. An account
            // still on 1.0 accepts taxRule-less documents; the reverse is not true.
            return true;
        }
    }

    /**
     * sevDesk's StaticCountry id for an ISO-3166-1 alpha-2 code.
     */
    public function countryId(?string $iso): ?int
    {
        $iso = strtoupper(trim((string)$iso));

        if ($iso === '') {
            return null;
        }

        $map = $this->countries();

        return $map[$iso] ?? null;
    }

    /**
     * @return array<string,int> ISO code => StaticCountry id
     */
    public function countries(bool $refresh = false): array
    {
        return $this->cached('countries', $refresh, function (): array {
            $map = [];

            foreach ((array)Plugin::getInstance()->api->get('StaticCountry', ['limit' => 500]) as $country) {
                $code = strtoupper((string)($country['code'] ?? ''));

                if ($code !== '' && isset($country['id'])) {
                    $map[$code] = (int)$country['id'];
                }
            }

            return $map;
        });
    }

    /**
     * Check accounts (bank accounts, cash registers) available for booking.
     *
     * @return array<int,array{id:int,name:string,type:string,currency:string}>
     */
    public function checkAccounts(bool $refresh = false): array
    {
        return $this->cached('checkAccounts', $refresh, function (): array {
            $accounts = [];

            foreach ((array)Plugin::getInstance()->api->get('CheckAccount', ['limit' => 200]) as $account) {
                if (!isset($account['id'])) {
                    continue;
                }

                $accounts[] = [
                    'id' => (int)$account['id'],
                    'name' => (string)($account['name'] ?? ''),
                    'type' => (string)($account['type'] ?? ''),
                    'currency' => (string)($account['currency'] ?? ''),
                ];
            }

            return $accounts;
        });
    }

    /**
     * The ContactAddress category id to file a billing address under, resolved
     * by translation key rather than by a guessed number.
     */
    public function invoiceAddressCategoryId(bool $refresh = false): ?int
    {
        $id = $this->cached('addressCategory', $refresh, function (): ?int {
            $categories = (array)Plugin::getInstance()->api->get('Category', [
                'objectType' => 'ContactAddress',
                'limit' => 100,
            ]);

            $fallback = null;

            foreach ($categories as $category) {
                if (!isset($category['id'])) {
                    continue;
                }

                $name = strtolower((string)($category['name'] ?? '')
                    . ' ' . (string)($category['translationCode'] ?? ''));

                if (str_contains($name, 'invoice') || str_contains($name, 'rechnung')) {
                    return (int)$category['id'];
                }

                $fallback ??= (int)$category['id'];
            }

            return $fallback;
        });

        return $id === null ? null : (int)$id;
    }

    /**
     * The CommunicationWayKey to file an email address under. sevDesk seeds
     * these per account, so it is looked up rather than assumed.
     */
    public function emailKeyId(bool $refresh = false): ?int
    {
        $id = $this->cached('emailKey', $refresh, function (): ?int {
            $keys = (array)Plugin::getInstance()->api->get('CommunicationWayKey', ['limit' => 100]);
            $fallback = null;

            foreach ($keys as $key) {
                if (!isset($key['id'])) {
                    continue;
                }

                $name = strtolower((string)($key['name'] ?? '') . ' ' . (string)($key['translationCode'] ?? ''));

                if (str_contains($name, 'work') || str_contains($name, 'business') || str_contains($name, 'arbeit')) {
                    return (int)$key['id'];
                }

                $fallback ??= (int)$key['id'];
            }

            return $fallback;
        });

        return $id === null ? null : (int)$id;
    }

    /**
     * The sevDesk user printed on the document as the contact person. Required
     * on every invoice, so a missing one is a setup problem worth naming.
     */
    public function contactPersonId(bool $refresh = false): ?int
    {
        $configured = Plugin::getInstance()->getSettings()->contactPersonId;

        if ($configured) {
            return $configured;
        }

        $id = $this->cached('contactPerson', $refresh, function (): ?int {
            foreach ((array)Plugin::getInstance()->api->get('SevUser', ['limit' => 50]) as $user) {
                if (isset($user['id'])) {
                    return (int)$user['id'];
                }
            }

            return null;
        });

        return $id === null ? null : (int)$id;
    }

    /**
     * Every sevDesk user, for the settings screen.
     *
     * @return array<int,array{id:int,name:string}>
     */
    public function users(bool $refresh = false): array
    {
        return $this->cached('users', $refresh, function (): array {
            $users = [];

            foreach ((array)Plugin::getInstance()->api->get('SevUser', ['limit' => 100]) as $user) {
                if (isset($user['id'])) {
                    $users[] = [
                        'id' => (int)$user['id'],
                        'name' => trim((string)($user['fullname'] ?? '') ?: (string)($user['username'] ?? '')),
                    ];
                }
            }

            return $users;
        });
    }

    /**
     * Forget everything. Called when the token or base URL changes — cached ids
     * from one sevDesk account are meaningless in another.
     */
    public function flush(): void
    {
        $cache = Craft::$app->getCache();

        foreach (['version', 'countries', 'checkAccounts', 'addressCategory', 'emailKey', 'contactPerson', 'users'] as $key) {
            $cache->delete(self::CACHE_PREFIX . $key);
        }
    }

    private function cached(string $key, bool $refresh, callable $fetch): mixed
    {
        $cacheKey = self::CACHE_PREFIX . $key;
        $cache = Craft::$app->getCache();

        if (!$refresh) {
            $cached = $cache->get($cacheKey);

            if ($cached !== false) {
                return $cached;
            }
        }

        $value = $fetch();
        $cache->set($cacheKey, $value, self::TTL);

        return $value;
    }
}
