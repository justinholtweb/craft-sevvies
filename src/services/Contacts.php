<?php

namespace justinholtweb\sevvies\services;

use Craft;
use craft\commerce\elements\Order;
use craft\elements\Address;
use justinholtweb\sevvies\errors\ApiException;
use justinholtweb\sevvies\helpers\Vat;
use justinholtweb\sevvies\Plugin;
use justinholtweb\sevvies\records\ContactRecord;
use yii\base\Component;

/**
 * Resolves a Commerce order's customer to a sevDesk contact.
 *
 * The mapping is stored locally, so a repeat customer never produces a second
 * contact — duplicated contacts are the thing that makes a sevDesk account
 * unusable after a year of orders.
 */
class Contacts extends Component
{
    /**
     * The sevDesk contact id for this order, creating the contact if needed.
     *
     * @throws ApiException
     */
    public function resolve(Order $order): ?int
    {
        $settings = Plugin::getInstance()->getSettings();
        $key = $this->customerKey($order);

        if ($key === null) {
            return null;
        }

        $record = ContactRecord::findOne(['customerKey' => $key]);

        if ($record !== null) {
            $this->refreshIfChanged($record, $order);

            return (int)$record->sevdeskId;
        }

        $sevdeskId = null;

        if ($settings->matchContactsByEmail) {
            $sevdeskId = $this->findByEmail($this->email($order), $order->id);
        }

        if ($sevdeskId === null) {
            if (!$settings->createContacts) {
                return null;
            }

            $sevdeskId = $this->create($order);
        }

        if ($sevdeskId === null) {
            return null;
        }

        $this->remember($key, $order, $sevdeskId);

        return $sevdeskId;
    }

    /**
     * What identifies this customer. A registered user is keyed by id so a
     * changed email address does not split them into two contacts; a guest is
     * keyed by email, which is all we have.
     */
    public function customerKey(Order $order): ?string
    {
        $userId = $order->getCustomer()?->id;

        if ($userId) {
            return 'user:' . $userId;
        }

        $email = $this->email($order);

        return $email === '' ? null : 'email:' . mb_strtolower($email);
    }

    public function email(Order $order): string
    {
        return trim((string)($order->getEmail() ?: $order->getCustomer()?->email ?: ''));
    }

    /**
     * Look for an existing sevDesk contact with this email address.
     *
     * `value` is not a documented CommunicationWay filter, so the result is
     * verified rather than trusted: if sevDesk ignores the filter and answers
     * with every communication way it has, matching here stops Sevvies from
     * attaching an order to a stranger's contact.
     */
    public function findByEmail(string $email, ?int $orderId = null): ?int
    {
        $email = trim($email);

        if ($email === '') {
            return null;
        }

        try {
            $ways = (array)Plugin::getInstance()->api->get('CommunicationWay', [
                'type' => 'EMAIL',
                'value' => $email,
                'limit' => 50,
            ], $orderId);
        } catch (ApiException $e) {
            Plugin::getInstance()->log->note(
                Craft::t('sevvies', 'Could not search sevDesk for the customer’s email address.'),
                $orderId,
                false,
                'contact',
                $e->getMessage(),
            );

            return null;
        }

        foreach ($ways as $way) {
            if (!is_array($way)) {
                continue;
            }

            if (mb_strtolower(trim((string)($way['value'] ?? ''))) !== mb_strtolower($email)) {
                continue;
            }

            $contactId = $way['contact']['id'] ?? null;

            if ($contactId) {
                return (int)$contactId;
            }
        }

        return null;
    }

    /**
     * Create the contact, its billing address and its email address.
     *
     * @throws ApiException
     */
    public function create(Order $order): ?int
    {
        $settings = Plugin::getInstance()->getSettings();
        $address = $order->getBillingAddress();
        $organisation = $this->organisation($address);

        $body = [
            'category' => ['id' => $settings->contactCategoryId, 'objectName' => 'Category'],
        ];

        if ($organisation !== null) {
            $body['name'] = $organisation;
        } else {
            [$first, $last] = $this->personName($order, $address);
            $body['surename'] = $first;
            $body['familyname'] = $last;
            // sevDesk shows organisations by `name`; a person needs one too or
            // the contact list shows a blank row.
            $body['name'] = trim($first . ' ' . $last) ?: $this->email($order);
        }

        $vatId = Plugin::getInstance()->tax->vatId($order);

        if ($vatId !== '' && Vat::looksValid($vatId)) {
            $body['vatNumber'] = $vatId;
        }

        if ($settings->assignCustomerNumber) {
            $number = $this->nextCustomerNumber($order->id);

            if ($number !== null) {
                $body['customerNumber'] = $number;
            }
        }

        $created = Plugin::getInstance()->api->post('Contact', $body, $order->id);
        $contactId = isset($created['id']) ? (int)$created['id'] : null;

        if ($contactId === null) {
            return null;
        }

        $this->syncAddress($contactId, $address, $order->id);
        $this->syncEmail($contactId, $this->email($order), $order->id);

        Plugin::getInstance()->log->note(
            Craft::t('sevvies', 'Created sevDesk contact {id}.', ['id' => $contactId]),
            $order->id,
            true,
            'contact',
        );

        return $contactId;
    }

    /**
     * Push the order's billing address onto an existing sevDesk contact.
     */
    public function syncAddress(int $contactId, ?Address $address, ?int $orderId = null): void
    {
        if ($address === null) {
            return;
        }

        $countryId = Plugin::getInstance()->meta->countryId($address->countryCode);

        if ($countryId === null) {
            Plugin::getInstance()->log->note(
                Craft::t('sevvies', 'sevDesk has no country matching “{code}”, so the address was not written.', [
                    'code' => (string)$address->countryCode,
                ]),
                $orderId,
                false,
                'contact',
            );

            return;
        }

        $body = [
            'contact' => ['id' => $contactId, 'objectName' => 'Contact'],
            'street' => trim((string)$address->addressLine1 . ' ' . (string)$address->addressLine2),
            'zip' => (string)$address->postalCode,
            'city' => (string)$address->locality,
            'country' => ['id' => $countryId, 'objectName' => 'StaticCountry'],
        ];

        $categoryId = Plugin::getInstance()->meta->invoiceAddressCategoryId();

        if ($categoryId !== null) {
            $body['category'] = ['id' => $categoryId, 'objectName' => 'Category'];
        }

        try {
            Plugin::getInstance()->api->post('ContactAddress', $body, $orderId);
        } catch (ApiException $e) {
            // A contact without a filed address still invoices correctly — the
            // invoice carries its own address string.
            Plugin::getInstance()->log->note(
                Craft::t('sevvies', 'Could not write the contact’s address.'),
                $orderId,
                false,
                'contact',
                $e->getMessage(),
            );
        }
    }

    public function syncEmail(int $contactId, string $email, ?int $orderId = null): void
    {
        if (trim($email) === '') {
            return;
        }

        $keyId = Plugin::getInstance()->meta->emailKeyId();

        if ($keyId === null) {
            return;
        }

        try {
            Plugin::getInstance()->api->post('CommunicationWay', [
                'contact' => ['id' => $contactId, 'objectName' => 'Contact'],
                'type' => 'EMAIL',
                'value' => $email,
                'key' => ['id' => $keyId, 'objectName' => 'CommunicationWayKey'],
                'main' => true,
            ], $orderId);
        } catch (ApiException $e) {
            Plugin::getInstance()->log->note(
                Craft::t('sevvies', 'Could not write the contact’s email address.'),
                $orderId,
                false,
                'contact',
                $e->getMessage(),
            );
        }
    }

    /**
     * The next free customer number, or null if sevDesk will not say.
     */
    public function nextCustomerNumber(?int $orderId = null): ?string
    {
        try {
            $objects = Plugin::getInstance()->api->get('Contact/Factory/getNextCustomerNumber', [], $orderId);
        } catch (ApiException) {
            return null;
        }

        if (is_string($objects) || is_int($objects)) {
            return (string)$objects;
        }

        if (is_array($objects)) {
            $value = $objects['nextCustomerNumber'] ?? $objects['customerNumber'] ?? ($objects[0] ?? null);

            if (is_scalar($value)) {
                return (string)$value;
            }
        }

        return null;
    }

    /**
     * Forget one mapping — used when a contact was deleted in sevDesk.
     */
    public function forget(string $customerKey): void
    {
        ContactRecord::deleteAll(['customerKey' => $customerKey]);
    }

    /**
     * Store the mapping so the next order skips every lookup above.
     */
    private function remember(string $key, Order $order, int $sevdeskId): void
    {
        $record = new ContactRecord();
        $record->customerKey = $key;
        $record->userId = $order->getCustomer()?->id;
        $record->email = $this->email($order) ?: null;
        $record->sevdeskId = $sevdeskId;
        $record->isOrganisation = $this->organisation($order->getBillingAddress()) !== null;
        $record->vatId = Plugin::getInstance()->tax->vatId($order) ?: null;
        $record->addressHash = $this->addressHash($order->getBillingAddress());
        $record->save(false);
    }

    /**
     * Keep sevDesk in step when a returning customer has moved.
     */
    private function refreshIfChanged(ContactRecord $record, Order $order): void
    {
        if (!Plugin::getInstance()->getSettings()->updateContactAddress) {
            return;
        }

        $hash = $this->addressHash($order->getBillingAddress());

        if ($hash === null || $hash === $record->addressHash) {
            return;
        }

        $this->syncAddress((int)$record->sevdeskId, $order->getBillingAddress(), $order->id);

        $record->addressHash = $hash;
        $record->save(false);
    }

    /**
     * The organisation name on an address, or null for a private person.
     */
    private function organisation(?Address $address): ?string
    {
        if ($address === null) {
            return null;
        }

        $organisation = trim((string)($address->organization ?? ''));

        return $organisation !== '' ? $organisation : null;
    }

    /**
     * @return array{0:string,1:string}
     */
    private function personName(Order $order, ?Address $address): array
    {
        $first = trim((string)($address?->firstName ?? ''));
        $last = trim((string)($address?->lastName ?? ''));

        if ($first === '' && $last === '') {
            $full = trim((string)($address?->fullName ?? $order->getCustomer()?->fullName ?? ''));

            if ($full !== '') {
                $parts = preg_split('/\s+/', $full) ?: [];
                $last = (string)array_pop($parts);
                $first = implode(' ', $parts);
            }
        }

        if ($first === '' && $last === '') {
            $last = $this->email($order);
        }

        return [$first, $last];
    }

    private function addressHash(?Address $address): ?string
    {
        if ($address === null) {
            return null;
        }

        return md5(implode('|', [
            (string)$address->organization,
            (string)$address->fullName,
            (string)$address->addressLine1,
            (string)$address->addressLine2,
            (string)$address->postalCode,
            (string)$address->locality,
            (string)$address->countryCode,
        ]));
    }
}
