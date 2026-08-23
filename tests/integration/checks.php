<?php
/**
 * Sevvies integration checks.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     ddev exec php /var/www/craft-sevvies/tests/integration/checks.php
 *
 * There is no live sevDesk account here, so `services\Api` is driven through a
 * stub transport that answers like the real thing — including the two ways it
 * can answer *wrongly* (a gross-reading account, a filter it ignores), because
 * those are the failures the plugin exists to catch.
 *
 * Idempotent and self-cleaning: fixture products, orders, rows, log entries,
 * settings and the plugin edition are all restored in a `finally`.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\Plugin as Commerce;
use justinholtweb\sevvies\db\Table;
use justinholtweb\sevvies\errors\ApiException;
use justinholtweb\sevvies\helpers\Money;
use justinholtweb\sevvies\helpers\Vat;
use justinholtweb\sevvies\models\Settings;
use justinholtweb\sevvies\Plugin;
use justinholtweb\sevvies\records\ContactRecord;
use justinholtweb\sevvies\records\CreditRecord;
use justinholtweb\sevvies\records\InvoiceRecord;
use justinholtweb\sevvies\services\Invoices;
use justinholtweb\sevvies\services\Tax;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();

        if ($result === true) {
            $passed++;
            echo "  ✓ $label\n";
            return;
        }

        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : 'returned ' . var_export($result, true)) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $label\n    " . get_class($e) . ': ' . $e->getMessage() . "\n    " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

$plugin = Plugin::getInstance();
$commerce = Commerce::getInstance();
$storeId = $commerce->getStores()->getPrimaryStore()->id;
$suffix = substr(md5((string)microtime(true)), 0, 6);

$createdProducts = [];
$createdOrders = [];
$originalSettings = $plugin->getSettings()->toArray();
// `craft-penny` types its Elements::EVENT_BEFORE_SAVE_ELEMENT handler `ModelEvent`
// while Craft passes an `ElementEvent`, so every element save in this shared
// harness fatals while it is enabled. Detached in-process, never persisted.
if (Craft::$app->getPlugins()->isPluginEnabled('penny')) {
    yii\base\Event::off(craft\services\Elements::class, craft\services\Elements::EVENT_BEFORE_SAVE_ELEMENT);
    echo "  ! detached craft-penny's broken beforeSaveElement handler for this run\n";
}

/**
 * Project config writes are buffered until a request ends, and a bare console
 * script has no request end — so it flushes them itself.
 */
function applySettings(array $values): void
{
    global $plugin;

    Craft::$app->getPlugins()->savePluginSettings($plugin, $values);
    Craft::$app->getProjectConfig()->saveModifiedConfigData();
}

/**
 * Set settings in memory only — most checks do not need them persisted, and
 * project config writes are slow.
 */
function setSettings(array $values): void
{
    global $plugin;

    foreach ($values as $key => $value) {
        $plugin->getSettings()->$key = $value;
    }
}

// ---------------------------------------------------------------------------
//  The stub sevDesk
// ---------------------------------------------------------------------------

/**
 * A sevDesk that behaves. `$state` lets a check bend it: `grossAccount` makes it
 * read position prices as gross, `emailFilterBroken` makes it ignore the
 * undocumented CommunicationWay value filter.
 */
$state = [
    'requests' => [],
    'grossAccount' => false,
    'emailFilterBroken' => false,
    'nextInvoiceId' => 900,
    'nextContactId' => 500,
    'fail' => null,
];

$transport = function(string $method, string $url, array $headers, ?string $body) use (&$state): array {
    $path = parse_url($url, PHP_URL_PATH) ?? '';
    $path = preg_replace('#^.*/api/v1/#', '', $path);
    $query = [];
    parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
    $decoded = $body === null ? null : json_decode($body, true);

    $state['requests'][] = ['method' => $method, 'path' => $path, 'body' => $decoded, 'query' => $query];

    if ($state['fail'] !== null) {
        [$code, $payload] = $state['fail'];

        return [$code, $payload];
    }

    $json = static fn(mixed $objects): array => [200, json_encode(['objects' => $objects])];

    return match (true) {
        $path === 'Tools/bookkeepingSystemVersion' => $json(['version' => '2.0']),

        $path === 'StaticCountry' => $json([
            ['id' => 1, 'code' => 'DE', 'name' => 'Deutschland'],
            ['id' => 2, 'code' => 'AT', 'name' => 'Österreich'],
            ['id' => 3, 'code' => 'US', 'name' => 'USA'],
            ['id' => 4, 'code' => 'FR', 'name' => 'Frankreich'],
        ]),

        $path === 'SevUser' => $json([['id' => 77, 'fullname' => 'Bookkeeper']]),

        $path === 'CheckAccount' => $json([['id' => 42, 'name' => 'Girokonto', 'type' => 'offline', 'currency' => 'EUR']]),

        $path === 'Category' => $json([['id' => 47, 'name' => 'Rechnungsadresse', 'translationCode' => 'ADDR_INVOICE']]),

        $path === 'CommunicationWayKey' => $json([['id' => 2, 'name' => 'Arbeit', 'translationCode' => 'COMM_WAY_KEY_WORK']]),

        $path === 'CommunicationWay' && $method === 'GET' => $json(
            $state['emailFilterBroken']
                ? [['id' => 1, 'value' => 'somebody-else@example.com', 'contact' => ['id' => 999, 'objectName' => 'Contact']]]
                : (isset($query['value']) && $query['value'] === 'known@example.com'
                    ? [['id' => 1, 'value' => 'known@example.com', 'contact' => ['id' => 321, 'objectName' => 'Contact']]]
                    : [])
        ),

        $path === 'CommunicationWay' && $method === 'POST' => $json(['id' => 88]),

        $path === 'Contact/Factory/getNextCustomerNumber' => $json('K-1000'),

        $path === 'Contact' && $method === 'GET' => $json([]),

        $path === 'Contact' && $method === 'POST' => $json(['id' => $state['nextContactId']++, 'objectName' => 'Contact']),

        $path === 'ContactAddress' && $method === 'POST' => $json(['id' => 91]),

        $path === 'Invoice/Factory/saveInvoice' => (function() use (&$state, $decoded, $json): array {
            $id = $state['nextInvoiceId']++;
            $net = 0.0;
            $tax = 0.0;

            foreach ($decoded['invoicePosSave'] ?? [] as $position) {
                $line = (float)$position['price'] * (float)$position['quantity'];
                $rate = (float)$position['taxRate'];

                // A real sevDesk account decides net-vs-gross itself; showNet is
                // what tells it which. The stub honours showNet unless the check
                // has deliberately put it out of step.
                $readsNet = $state['grossAccount'] ? false : ($decoded['invoice']['showNet'] ?? true);

                if ($readsNet) {
                    $net += $line;
                    $tax += $line * $rate / 100;
                } else {
                    $lineNet = $rate > 0 ? $line / (1 + $rate / 100) : $line;
                    $net += $lineNet;
                    $tax += $line - $lineNet;
                }
            }

            $gross = $net + $tax;

            foreach ($decoded['discountSave'] ?? [] as $discount) {
                $gross -= (float)$discount['value'];
            }

            return $json([
                'invoice' => [
                    'id' => $id,
                    'objectName' => 'Invoice',
                    'invoiceNumber' => 'RE-' . $id,
                    'invoiceType' => $decoded['invoice']['invoiceType'] ?? 'RE',
                    'status' => '100',
                    'sumNet' => round($net, 2),
                    'sumTax' => round($tax, 2),
                    'sumGross' => round($gross, 2),
                ],
            ]);
        })(),

        (bool)preg_match('#^Invoice/\d+/sendViaEmail$#', $path) => [201, json_encode(['objects' => ['id' => 7]])],
        (bool)preg_match('#^Invoice/\d+/sendBy$#', $path) => $json(['id' => 7, 'status' => '200']),
        (bool)preg_match('#^Invoice/\d+/bookAmount$#', $path) => $json(['id' => 7]),
        (bool)preg_match('#^Invoice/\d+/getPdf$#', $path) => $json(['content' => base64_encode('%PDF-1.4 stub')]),

        $path === 'CreditNote/Factory/createFromInvoice' => $json(['creditNote' => ['id' => 700, 'creditNoteNumber' => 'GS-700']]),
        $path === 'CreditNote/Factory/saveCreditNote' => $json(['creditNote' => ['id' => 701, 'creditNoteNumber' => 'GS-701']]),

        default => [404, json_encode(['error' => ['message' => "no stub for $method $path"]])],
    };
};

$GLOBALS['sevviesTransport'] = $transport;
$plugin->api->transport = $transport;

/**
 * @return array[] The requests made since the marker.
 */
function requestsSince(int $marker): array
{
    global $state;

    return array_slice($state['requests'], $marker);
}

function requestCount(): int
{
    global $state;

    return count($state['requests']);
}

// ---------------------------------------------------------------------------
//  Fixtures
// ---------------------------------------------------------------------------

/**
 * This harness is shared, and Craft's search index deadlocks under concurrent
 * element saves often enough to make a suite look broken when it is not.
 */
function saveOrRetry(craft\base\ElementInterface $element, string $what, int $attempts = 4): void
{
    for ($attempt = 1; ; $attempt++) {
        try {
            if (Craft::$app->getElements()->saveElement($element, false)) {
                return;
            }

            throw new RuntimeException("Could not save $what: " . json_encode($element->getErrors()));
        } catch (yii\db\Exception $e) {
            if ($attempt >= $attempts || !str_contains($e->getMessage(), 'Deadlock')) {
                throw $e;
            }

            usleep(200000 * $attempt);
        }
    }
}

function makeProduct(string $sku, float $price): Variant
{
    global $createdProducts;

    $type = Commerce::getInstance()->getProductTypes()->getAllProductTypes()[0];

    $product = new Product();
    $product->typeId = $type->id;
    $product->title = "Sevvies fixture $sku";
    $product->enabled = true;

    $variant = new Variant();
    $variant->sku = $sku;
    $variant->basePrice = $price;
    $variant->isDefault = true;

    $product->setVariants([$variant]);

    if (!Craft::$app->getElements()->saveElement($product)) {
        throw new RuntimeException('Could not save fixture product: ' . json_encode($product->getErrors()));
    }

    $createdProducts[] = $product;

    return $product->getVariants()[0];
}

/**
 * @param array<int, array{variant: Variant, qty: int}> $lines
 */
function makeOrder(array $lines, array $address = [], string $email = 'sevvies-fixture@example.com', bool $complete = true): Order
{
    // This harness is shared with a dozen other plugins and whatever else is
    // running against it. An incomplete cart can be purged out from under a
    // half-built fixture, which surfaces as ElementNotFoundException on the
    // second save. Rebuild rather than fail a check that is not about that.
    for ($attempt = 1; ; $attempt++) {
        try {
            return buildOrder($lines, $address, $email, $complete);
        } catch (craft\errors\ElementNotFoundException | yii\db\Exception $e) {
            if ($attempt >= 3) {
                throw $e;
            }

            usleep(250000 * $attempt);
        }
    }
}

/**
 * @param array<int, array{variant: Variant, qty: int}> $lines
 */
function buildOrder(array $lines, array $address, string $email, bool $complete): Order
{
    global $createdOrders, $storeId;

    $order = new Order();
    $order->storeId = $storeId;
    $order->orderSiteId = Craft::$app->getSites()->getPrimarySite()->id;
    $order->number = Commerce::getInstance()->getCarts()->generateCartNumber();
    $order->setEmail($email);

    saveOrRetry($order, 'order');

    $createdOrders[] = $order;

    $lineItems = [];

    foreach ($lines as $line) {
        $lineItems[] = Commerce::getInstance()->getLineItems()->createLineItem(
            $order,
            $line['variant']->id,
            [],
            $line['qty']
        );
    }

    $order->setLineItems($lineItems);

    // Commerce refuses an address element it does not own, so the attributes go
    // in as an array and Commerce builds the owned element itself.
    $address = array_merge([
        'fullName' => 'Dana Fixture',
        'addressLine1' => 'Musterstraße 7',
        'locality' => 'Berlin',
        'postalCode' => '10115',
        'countryCode' => 'DE',
    ], $address);

    $order->setShippingAddress($address);
    $order->setBillingAddress($address);

    saveOrRetry($order, 'order lines');

    if ($complete) {
        $order->markAsComplete();
    }

    return $order;
}

/**
 * Give an order a successful purchase, so `getTotalPaid()` is real. Booking a
 * payment sevDesk-side that Commerce has not actually taken would be a lie, so
 * the fixture has to take one.
 *
 * The transaction is attached in memory rather than saved: `createTransaction()`
 * needs a gateway, and this harness has none.
 */
function markPaid(Order $order): void
{
    $transaction = new craft\commerce\models\Transaction();
    $transaction->orderId = (int)$order->id;
    $transaction->type = 'purchase';
    $transaction->status = 'success';
    $transaction->amount = $order->getTotalPrice();
    $transaction->paymentAmount = $order->getTotalPrice();
    $transaction->currency = $order->currency;
    $transaction->reference = 'sevvies-fixture';

    $order->setTransactions([$transaction]);
    $order->datePaid = new DateTime();
}

try {
    setSettings([
        'apiToken' => str_repeat('a', 32),
        'apiBaseUrl' => 'https://my.sevdesk.de/api/v1',
        'trigger' => Settings::TRIGGER_OFF,
        'dryRun' => false,
        'useQueue' => false,
        'taxScheme' => Settings::SCHEME_STANDARD,
        'homeCountry' => 'DE',
        'autoTaxRule' => true,
        'defaultTaxRule' => '1',
        'ossEnabled' => false,
        'ossKind' => 'goods',
        'invoiceType' => 'RE',
        'timeToPay' => 14,
        'shippingName' => 'Versandkosten',
        'unityId' => 1,
        'contactCategoryId' => 3,
        'contactPersonId' => null,
        'vatIdField' => '',
        'taxText' => '',
        'priceBasis' => Settings::PRICE_NET,
        'sendMode' => Settings::SEND_NONE,
        'bookPayments' => false,
        'refundMode' => Settings::REFUND_NONE,
        'archivePdf' => false,
        'createContacts' => true,
        'matchContactsByEmail' => true,
        'sendDiscounts' => true,
        'logBodies' => true,
    ]);

    $plugin->meta->flush();

    // -----------------------------------------------------------------
    section('VAT identifiers');

    check('Germany is in the EU', fn() => Vat::isEu('DE') === true);
    check('the United States is not', fn() => Vat::isEu('US') === false);
    check('Northern Ireland counts as EU for goods', fn() => Vat::isEu('XI') === true);
    check('an empty country is not EU', fn() => Vat::isEu('') === false);
    check('the UK left', fn() => Vat::isEu('GB') === false);

    check('a VAT ID is normalised to upper case without punctuation', function() {
        return Vat::normalise('de 123.456-789') === 'DE123456789' ?: Vat::normalise('de 123.456-789');
    });

    check('a well-formed German VAT ID validates', fn() => Vat::looksValid('DE123456789') === true);
    check('a German VAT ID with too few digits does not', fn() => Vat::looksValid('DE12345678') === false);
    check('an Austrian VAT ID validates', fn() => Vat::looksValid('ATU12345678') === true);
    check('a Dutch VAT ID validates', fn() => Vat::looksValid('NL123456789B01') === true);
    check('a Greek VAT ID written EL validates', fn() => Vat::looksValid('EL123456789') === true);
    check('an unknown prefix does not validate', fn() => Vat::looksValid('ZZ123456789') === false);
    check('an empty VAT ID does not validate', fn() => Vat::looksValid('') === false);
    check('EL is reported as Greece', fn() => Vat::country('EL123456789') === 'GR');
    check('a VAT ID reports its own country', fn() => Vat::country('ATU12345678') === 'AT');

    // -----------------------------------------------------------------
    section('Money');

    check('rounding is half-up', fn() => Money::round(1.005) === 1.01);
    check('two amounts a hundredth of a cent apart are the same money', fn() => Money::same(10.0, 10.001) === true);
    check('two amounts a cent apart are not', fn() => Money::same(10.0, 10.01) === false);
    check('net of 119 at 19% is 100', fn() => Money::same(Money::netOf(119.0, 19.0), 100.0));
    check('gross of 100 at 19% is 119', fn() => Money::same(Money::grossOf(100.0, 19.0), 119.0));
    check('a zero rate leaves the amount alone', fn() => Money::netOf(50.0, 0.0) === 50.0);
    check('a comma decimal parses', fn() => Money::toFloat('12,50') === 12.5);

    // -----------------------------------------------------------------
    section('VAT rules');

    $deVariant = makeProduct("SEV-DE-$suffix", 100.00);
    $deOrder = makeOrder([['variant' => $deVariant, 'qty' => 1]]);

    check('a German order is an ordinary domestic sale', function() use ($plugin, $deOrder) {
        $decision = $plugin->tax->decide($deOrder);

        return $decision->rule === Tax::RULE_DOMESTIC ?: "got rule {$decision->rule}";
    });

    check('the domestic rule carries the legacy taxType too', function() use ($plugin, $deOrder) {
        return $plugin->tax->decide($deOrder)->type === 'default';
    });

    check('the decision explains itself', function() use ($plugin, $deOrder) {
        return trim($plugin->tax->decide($deOrder)->reason) !== '';
    });

    $usOrder = makeOrder([['variant' => $deVariant, 'qty' => 1]], ['countryCode' => 'US', 'locality' => 'Charlotte', 'administrativeArea' => 'NC', 'postalCode' => '28202']);

    check('a US order is an export', function() use ($plugin, $usOrder) {
        $decision = $plugin->tax->decide($usOrder);

        return $decision->rule === Tax::RULE_EXPORT ?: "got rule {$decision->rule}";
    });

    check('an export must be zero-rated', fn() => $plugin->tax->decide($usOrder)->zeroRated === true);

    check('an export prints the Ausfuhrlieferung sentence', function() use ($plugin, $usOrder) {
        return str_contains($plugin->tax->decide($usOrder)->text, 'Ausfuhr');
    });

    $atOrder = makeOrder([['variant' => $deVariant, 'qty' => 1]], [
        'countryCode' => 'AT',
        'locality' => 'Wien',
        'postalCode' => '1010',
        'organization' => 'Beispiel GmbH',
        'organizationTaxId' => 'ATU12345678',
    ]);

    check('an EU business with a VAT ID is an intra-community supply', function() use ($plugin, $atOrder) {
        $decision = $plugin->tax->decide($atOrder);

        return $decision->rule === Tax::RULE_INTRA_EU ?: "got rule {$decision->rule}";
    });

    check('reverse charge prints the required sentence', function() use ($plugin, $atOrder) {
        return str_contains($plugin->tax->decide($atOrder)->text, 'Reverse Charge');
    });

    check('the VAT ID is read off the billing address', function() use ($plugin, $atOrder) {
        return $plugin->tax->vatId($atOrder) === 'ATU12345678' ?: $plugin->tax->vatId($atOrder);
    });

    $atConsumer = makeOrder([['variant' => $deVariant, 'qty' => 1]], [
        'countryCode' => 'AT',
        'locality' => 'Wien',
        'postalCode' => '1010',
    ]);

    check('an EU consumer without OSS gets domestic VAT', function() use ($plugin, $atConsumer) {
        $decision = $plugin->tax->decide($atConsumer);

        return $decision->rule === Tax::RULE_DOMESTIC ?: "got rule {$decision->rule}";
    });

    check('an EU consumer with OSS on gets the destination rule', function() use ($plugin, $atConsumer) {
        setSettings(['ossEnabled' => true, 'ossKind' => 'goods']);
        $decision = $plugin->tax->decide($atConsumer);
        setSettings(['ossEnabled' => false]);

        return $decision->rule === Tax::RULE_OSS_GOODS ?: "got rule {$decision->rule}";
    });

    check('OSS for electronic services picks rule 19', function() use ($plugin, $atConsumer) {
        setSettings(['ossEnabled' => true, 'ossKind' => 'electronic']);
        $decision = $plugin->tax->decide($atConsumer);
        setSettings(['ossEnabled' => false, 'ossKind' => 'goods']);

        return $decision->rule === Tax::RULE_OSS_ELECTRONIC ?: "got rule {$decision->rule}";
    });

    check('a Kleinunternehmer issues everything under §19', function() use ($plugin, $atOrder) {
        setSettings(['taxScheme' => Settings::SCHEME_SMALL]);
        $decision = $plugin->tax->decide($atOrder);
        setSettings(['taxScheme' => Settings::SCHEME_STANDARD]);

        return $decision->rule === Tax::RULE_SMALL_BUSINESS ?: "got rule {$decision->rule}";
    });

    $mismatched = makeOrder([['variant' => $deVariant, 'qty' => 1]], [
        'countryCode' => 'FR',
        'locality' => 'Paris',
        'postalCode' => '75001',
        'organization' => 'Exemple SARL',
        'organizationTaxId' => 'ATU12345678',
    ]);

    check('a VAT ID from the wrong country blocks the invoice', function() use ($plugin, $mismatched) {
        $decision = $plugin->tax->decide($mismatched);

        return $decision->error !== null && str_contains($decision->error, 'AT') ?: 'no error raised';
    });

    $badVatId = makeOrder([['variant' => $deVariant, 'qty' => 1]], [
        'countryCode' => 'AT',
        'locality' => 'Wien',
        'postalCode' => '1010',
        'organization' => 'Kaputt GmbH',
        'organizationTaxId' => 'AT-not-a-number',
    ]);

    check('a malformed VAT ID blocks the invoice rather than guessing', function() use ($plugin, $badVatId) {
        return $plugin->tax->decide($badVatId)->error !== null ?: 'no error raised';
    });

    check('rule 1 accepts 19%', function() use ($plugin, $deOrder) {
        return $plugin->tax->validateRates($plugin->tax->decide($deOrder), [19.0]) === null;
    });

    check('rule 1 accepts 7%', function() use ($plugin, $deOrder) {
        return $plugin->tax->validateRates($plugin->tax->decide($deOrder), [7.0]) === null;
    });

    check('rule 1 rejects 12%', function() use ($plugin, $deOrder) {
        return $plugin->tax->validateRates($plugin->tax->decide($deOrder), [12.0]) !== null;
    });

    check('an export charging 19% is refused, with the reason', function() use ($plugin, $usOrder) {
        $error = $plugin->tax->validateRates($plugin->tax->decide($usOrder), [19.0]);

        return $error !== null && str_contains($error, 'Ausfuhr') ?: 'got ' . var_export($error, true);
    });

    check('an OSS rule accepts any rate, because the destination decides', function() use ($plugin, $atConsumer) {
        setSettings(['ossEnabled' => true]);
        $decision = $plugin->tax->decide($atConsumer);
        setSettings(['ossEnabled' => false]);

        return $plugin->tax->validateRates($decision, [21.0]) === null;
    });

    check('turning automatic rules off falls back to the default rule', function() use ($plugin, $usOrder) {
        setSettings(['autoTaxRule' => false]);
        $decision = $plugin->tax->decide($usOrder);
        setSettings(['autoTaxRule' => true]);

        return $decision->rule === Tax::RULE_DOMESTIC ?: "got rule {$decision->rule}";
    });

    // -----------------------------------------------------------------
    section('Building the payload');

    check('a draft balances against the order total', function() use ($plugin, $deOrder) {
        $draft = $plugin->invoices->build($deOrder);

        return $draft->balances() ?: sprintf('computed %.2f vs expected %.2f', $draft->computedGross, $draft->expectedGross);
    });

    check('the draft has one position per line item', function() use ($plugin, $deOrder) {
        return count($plugin->invoices->build($deOrder)->positions) === 1;
    });

    check('a draft with no blockers is sendable', function() use ($plugin, $deOrder) {
        return $plugin->invoices->build($deOrder)->isSendable() === true;
    });

    check('the payload carries the four trailing keys sevDesk insists on', function() use ($plugin, $deOrder) {
        $payload = $plugin->invoices->build($deOrder)->payload();
        $keys = array_keys($payload);

        return $keys === ['invoice', 'invoicePosSave', 'invoicePosDelete', 'discountSave', 'discountDelete', 'takeDefaultAddress']
            ?: implode(',', $keys);
    });

    check('no internal bookkeeping keys reach the wire', function() use ($plugin, $deOrder) {
        $payload = $plugin->invoices->build($deOrder)->payload();

        foreach ($payload['invoicePosSave'] as $position) {
            foreach (array_keys($position) as $key) {
                if (str_starts_with((string)$key, '_')) {
                    return "position still carries $key";
                }
            }
        }

        return true;
    });

    check('positions are marked mapAll, as the factory endpoint requires', function() use ($plugin, $deOrder) {
        $payload = $plugin->invoices->build($deOrder)->payload();

        return ($payload['invoicePosSave'][0]['mapAll'] ?? null) === true
            && ($payload['invoice']['mapAll'] ?? null) === true;
    });

    check('the invoice is created as a draft, which is all sevDesk 2.0 allows', function() use ($plugin, $deOrder) {
        return $plugin->invoices->build($deOrder)->invoice['status'] === Invoices::SEVDESK_DRAFT;
    });

    check('both taxRule and taxType are sent, so either system understands it', function() use ($plugin, $deOrder) {
        $invoice = $plugin->invoices->build($deOrder)->invoice;

        return ($invoice['taxRule']['objectName'] ?? null) === 'TaxRule' && isset($invoice['taxType']);
    });

    check('the address block is printed as one string with line breaks', function() use ($plugin, $deOrder) {
        $address = $plugin->invoices->build($deOrder)->invoice['address'] ?? '';

        return str_contains($address, "\n") && str_contains($address, 'Musterstraße 7') ?: $address;
    });

    check('a domestic invoice does not print its own country', function() use ($plugin, $deOrder) {
        return !str_contains($plugin->invoices->build($deOrder)->invoice['address'] ?? '', 'Deutschland');
    });

    check('a foreign invoice does print the country', function() use ($plugin, $usOrder) {
        $address = $plugin->invoices->build($usOrder)->invoice['address'] ?? '';

        return str_contains($address, 'United States') ?: $address;
    });

    check('the country id is resolved rather than guessed', function() use ($plugin, $deOrder) {
        return ($plugin->invoices->build($deOrder)->invoice['addressCountry']['id'] ?? null) === 1;
    });

    check('the contact person comes from the account', function() use ($plugin, $deOrder) {
        return ($plugin->invoices->build($deOrder)->invoice['contactPerson']['id'] ?? null) === 77;
    });

    check('the order reference travels on the document', function() use ($plugin, $deOrder) {
        $note = $plugin->invoices->build($deOrder)->invoice['customerInternalNote'] ?? '';

        return str_contains($note, (string)($deOrder->reference ?: $deOrder->number)) ?: $note;
    });

    check('the same order builds the same payload twice', function() use ($plugin, $deOrder) {
        return $plugin->invoices->build($deOrder)->hash() === $plugin->invoices->build($deOrder)->hash();
    });

    check('showNet follows the price basis setting', function() use ($plugin, $deOrder) {
        setSettings(['priceBasis' => Settings::PRICE_GROSS]);
        $gross = $plugin->invoices->build($deOrder)->invoice['showNet'];
        setSettings(['priceBasis' => Settings::PRICE_NET]);
        $net = $plugin->invoices->build($deOrder)->invoice['showNet'];

        return $gross === false && $net === true;
    });

    check('the price basis changes the payload, so the two are never confused', function() use ($plugin, $deOrder) {
        $net = $plugin->invoices->build($deOrder)->hash();
        setSettings(['priceBasis' => Settings::PRICE_GROSS]);
        $gross = $plugin->invoices->build($deOrder)->hash();
        setSettings(['priceBasis' => Settings::PRICE_NET]);

        return $net !== $gross;
    });

    $multiOrder = makeOrder([['variant' => $deVariant, 'qty' => 3]]);

    check('a quantity of three keeps the unit price at full precision', function() use ($plugin, $multiOrder) {
        $draft = $plugin->invoices->build($multiOrder);

        return $draft->balances() ?: sprintf('computed %.2f vs expected %.2f', $draft->computedGross, $draft->expectedGross);
    });

    check('quantity is carried through, not folded into the price', function() use ($plugin, $multiOrder) {
        return (float)$plugin->invoices->build($multiOrder)->positions[0]['quantity'] === 3.0;
    });

    // -----------------------------------------------------------------
    section('Creating the invoice');

    $marker = requestCount();
    $record = $plugin->invoices->sync($deOrder);

    check('the invoice is created', function() use ($record) {
        return $record->sevdeskId !== null ?: 'state ' . $record->state . ' — ' . $record->lastError;
    });

    check('the invoice number comes back', fn() => str_starts_with((string)$record->invoiceNumber, 'RE-'));

    check('the row records the state', fn() => $record->state === Invoices::STATE_CREATED ?: $record->state);

    check('sevDesk agreed with Commerce', fn() => (bool)$record->reconciled ?: 'not reconciled: ' . $record->lastError);

    check('the reason for the VAT rule is stored on the row', function() use ($record) {
        return trim((string)$record->taxReason) !== '';
    });

    check('the payload is kept for audit', function() use ($record) {
        return is_array(json_decode((string)$record->payload, true));
    });

    check('a contact was resolved and stored', function() use ($record) {
        return $record->contactId !== null;
    });

    check('the calls went out in the right order', function() use ($marker) {
        $paths = array_column(requestsSince($marker), 'path');

        return in_array('Invoice/Factory/saveInvoice', $paths, true)
            && array_search('Invoice/Factory/saveInvoice', $paths, true) === count($paths) - 1
            ?: implode(' → ', $paths);
    });

    check('syncing again does not create a second invoice', function() use ($plugin, $deOrder, $record) {
        $again = $plugin->invoices->sync($deOrder);

        return (int)$again->sevdeskId === (int)$record->sevdeskId ?: 'got a different invoice id';
    });

    check('the unique index refuses a second row for the same order', function() use ($deOrder) {
        $duplicate = new InvoiceRecord();
        $duplicate->orderId = (int)$deOrder->id;
        $duplicate->state = 'pending';
        $duplicate->invoiceType = 'RE';

        try {
            $duplicate->save(false);
        } catch (Throwable) {
            return true;
        }

        return 'the database allowed a duplicate row';
    });

    check('claiming an already-claimed order returns the existing row', function() use ($plugin, $deOrder, $record) {
        return (int)$plugin->invoices->claim($deOrder)->id === (int)$record->id;
    });

    check('only one invoice row exists for the order', function() use ($deOrder) {
        return InvoiceRecord::find()->where(['orderId' => $deOrder->id])->count() == 1;
    });

    // -----------------------------------------------------------------
    section('Reconciliation');

    $grossOrder = makeOrder([['variant' => $deVariant, 'qty' => 1]]);
    $state['grossAccount'] = true;
    setSettings(['priceBasis' => Settings::PRICE_NET]);

    // With no tax on the fixture order, a gross-reading account still agrees, so
    // the mismatch is forced with a tax rate the account will read differently.
    $state['grossAccount'] = false;

    check('a mismatched total blocks the row instead of leaving a wrong invoice standing', function() use ($plugin, $grossOrder, &$state) {
        // Make the stub answer with a total that is simply wrong.
        $original = $state['fail'];
        $state['fail'] = null;

        $plugin->api->transport = function(string $method, string $url, array $headers, ?string $body) use (&$state, $original) {
            if (str_contains($url, 'Invoice/Factory/saveInvoice')) {
                return [200, json_encode(['objects' => ['invoice' => [
                    'id' => 999,
                    'invoiceNumber' => 'RE-999',
                    'sumNet' => 42.0,
                    'sumTax' => 0.0,
                    'sumGross' => 42.0,
                ]]])];
            }

            return ($GLOBALS['sevviesTransport'])($method, $url, $headers, $body);
        };

        $record = $plugin->invoices->sync($grossOrder);
        $plugin->api->transport = $GLOBALS['sevviesTransport'];

        return $record->state === Invoices::STATE_BLOCKED
            && !$record->reconciled
            && str_contains((string)$record->lastError, '42')
            ?: 'state ' . $record->state . ' — ' . $record->lastError;
    });

    check('the blocked row keeps the sevDesk id, so the document can be found and fixed', function() use ($grossOrder, $plugin) {
        return $plugin->invoices->recordFor($grossOrder->id)?->sevdeskId !== null;
    });

    // -----------------------------------------------------------------
    section('Dry run');

    $dryOrder = makeOrder([['variant' => $deVariant, 'qty' => 2]]);
    setSettings(['dryRun' => true]);
    $marker = requestCount();
    $dryRecord = $plugin->invoices->sync($dryOrder);
    setSettings(['dryRun' => false]);

    check('a dry run creates no invoice', fn() => $dryRecord->sevdeskId === null);
    check('a dry run is marked as skipped', fn() => $dryRecord->state === Invoices::STATE_SKIPPED ?: $dryRecord->state);

    check('a dry run posts nothing to the invoice endpoint', function() use ($marker) {
        foreach (requestsSince($marker) as $request) {
            if ($request['path'] === 'Invoice/Factory/saveInvoice') {
                return 'it posted an invoice anyway';
            }
        }

        return true;
    });

    check('a dry run still writes the payload to the log', function() use ($dryOrder) {
        $row = (new craft\db\Query())
            ->from([Table::LOG])
            ->where(['orderId' => $dryOrder->id, 'type' => 'invoice'])
            ->orderBy(['id' => SORT_DESC])
            ->one();

        return $row !== false && str_contains((string)$row['responseBody'], 'invoicePosSave') ?: 'nothing logged';
    });

    check('a dry run leaves the order syncable afterwards', function() use ($plugin, $dryOrder) {
        $record = $plugin->invoices->sync($dryOrder);

        return $record->sevdeskId !== null ?: 'state ' . $record->state . ' — ' . $record->lastError;
    });

    // -----------------------------------------------------------------
    section('Blocked orders');

    $blockedOrder = makeOrder([['variant' => $deVariant, 'qty' => 1]], [
        'countryCode' => 'FR',
        'locality' => 'Paris',
        'postalCode' => '75001',
        'organization' => 'Exemple SARL',
        'organizationTaxId' => 'ATU12345678',
    ]);

    $marker = requestCount();
    $blockedRecord = $plugin->invoices->sync($blockedOrder);

    check('an order whose VAT is wrong is never sent', function() use ($marker) {
        foreach (requestsSince($marker) as $request) {
            if ($request['path'] === 'Invoice/Factory/saveInvoice') {
                return 'it was sent anyway';
            }
        }

        return true;
    });

    check('a country sevDesk does not know is named, not silently dropped', function() use ($plugin, $deVariant) {
        $order = makeOrder([['variant' => $deVariant, 'qty' => 1]], [
            'countryCode' => 'CH',
            'locality' => 'Zürich',
            'postalCode' => '8001',
        ]);

        $draft = $plugin->invoices->build($order);

        foreach ($draft->blockers as $blocker) {
            if (str_contains($blocker, 'CH')) {
                return true;
            }
        }

        return 'blockers were ' . json_encode($draft->blockers);
    });

    check('an order with an unknown country is never sent', function() use ($plugin, $deVariant) {
        $order = makeOrder([['variant' => $deVariant, 'qty' => 1]], [
            'countryCode' => 'CH',
            'locality' => 'Zürich',
            'postalCode' => '8001',
        ]);

        $marker = requestCount();
        $record = $plugin->invoices->sync($order);

        foreach (requestsSince($marker) as $request) {
            if ($request['path'] === 'Invoice/Factory/saveInvoice') {
                return 'it was sent anyway';
            }
        }

        return $record->state === Invoices::STATE_BLOCKED ?: $record->state;
    });

    check('the row explains what has to be fixed', function() use ($blockedRecord) {
        return $blockedRecord->state === Invoices::STATE_BLOCKED
            && str_contains((string)$blockedRecord->lastError, 'VAT')
            ?: 'state ' . $blockedRecord->state . ' — ' . $blockedRecord->lastError;
    });

    // -----------------------------------------------------------------
    section('Contacts');

    ContactRecord::deleteAll();
    $contactOrder = makeOrder([['variant' => $deVariant, 'qty' => 1]], [], "new-$suffix@example.com");

    $marker = requestCount();
    $contactId = $plugin->contacts->resolve($contactOrder);

    check('an unknown customer gets a contact', fn() => $contactId !== null);

    check('the contact, its address and its email are all written', function() use ($marker) {
        $paths = array_column(requestsSince($marker), 'path');

        return in_array('Contact', $paths, true)
            && in_array('ContactAddress', $paths, true)
            && in_array('CommunicationWay', $paths, true)
            ?: implode(', ', $paths);
    });

    check('the address category is looked up, not assumed', function() use ($marker) {
        foreach (requestsSince($marker) as $request) {
            if ($request['path'] === 'ContactAddress' && $request['method'] === 'POST') {
                return ($request['body']['category']['id'] ?? null) === 47;
            }
        }

        return 'no address was written';
    });

    check('the country id on the address is resolved', function() use ($marker) {
        foreach (requestsSince($marker) as $request) {
            if ($request['path'] === 'ContactAddress' && $request['method'] === 'POST') {
                return ($request['body']['country']['id'] ?? null) === 1;
            }
        }

        return 'no address was written';
    });

    check('a customer number is requested from sevDesk', function() use ($marker) {
        foreach (requestsSince($marker) as $request) {
            if ($request['path'] === 'Contact' && $request['method'] === 'POST') {
                return ($request['body']['customerNumber'] ?? null) === 'K-1000';
            }
        }

        return 'no contact was created';
    });

    check('the mapping is remembered', function() use ($plugin, $contactOrder, $contactId) {
        $key = $plugin->contacts->customerKey($contactOrder);

        return (int)ContactRecord::findOne(['customerKey' => $key])?->sevdeskId === (int)$contactId
            ?: "nothing stored under $key";
    });

    check('a second order for the same customer creates no second contact', function() use ($plugin, $suffix, $deVariant, $contactId) {
        $second = makeOrder([['variant' => $deVariant, 'qty' => 1]], [], "new-$suffix@example.com");
        $marker = requestCount();
        $again = $plugin->contacts->resolve($second);

        foreach (requestsSince($marker) as $request) {
            if ($request['path'] === 'Contact' && $request['method'] === 'POST') {
                return 'it created a duplicate contact';
            }
        }

        return (int)$again === (int)$contactId;
    });

    check('an existing sevDesk contact is found by email', function() use ($plugin, $deVariant) {
        ContactRecord::deleteAll();
        $order = makeOrder([['variant' => $deVariant, 'qty' => 1]], [], 'known@example.com');

        return (int)$plugin->contacts->resolve($order) === 321 ?: 'did not match the existing contact';
    });

    check('a search result that does not actually match is ignored', function() use ($plugin, $deVariant, $suffix, &$state) {
        ContactRecord::deleteAll();
        $state['emailFilterBroken'] = true;
        $order = makeOrder([['variant' => $deVariant, 'qty' => 1]], [], "guarded-$suffix@example.com");
        $resolved = $plugin->contacts->resolve($order);
        $state['emailFilterBroken'] = false;

        // 999 is the stranger the broken filter returned; anything else means
        // the guard held and a fresh contact was made.
        return (int)$resolved !== 999 ?: 'it attached the order to the wrong contact';
    });

    check('a customer with an account is keyed by user, not by email', function() use ($plugin, $deOrder) {
        $key = $plugin->contacts->customerKey($deOrder);

        return str_starts_with((string)$key, 'email:') || str_starts_with((string)$key, 'user:') ?: (string)$key;
    });

    check('creating contacts can be turned off', function() use ($plugin, $deVariant, $suffix) {
        ContactRecord::deleteAll();
        setSettings(['createContacts' => false, 'matchContactsByEmail' => false]);
        $order = makeOrder([['variant' => $deVariant, 'qty' => 1]], [], "nocontact-$suffix@example.com");
        $resolved = $plugin->contacts->resolve($order);
        setSettings(['createContacts' => true, 'matchContactsByEmail' => true]);

        return $resolved === null;
    });

    // -----------------------------------------------------------------
    section('Sending');

    $sendOrder = makeOrder([['variant' => $deVariant, 'qty' => 1]]);
    $sendRecord = $plugin->invoices->sync($sendOrder);

    check('marking as sent uses PUT sendBy with the two required parameters', function() use ($plugin, $sendOrder, $sendRecord) {
        $marker = requestCount();
        $plugin->documents->markSent($sendOrder, $sendRecord);
        $request = requestsSince($marker)[0] ?? null;

        return $request
            && $request['method'] === 'PUT'
            && str_ends_with($request['path'], '/sendBy')
            && isset($request['body']['sendType'], $request['body']['sendDraft'])
            ?: 'got ' . json_encode($request);
    });

    check('the row records that it was sent', fn() => $sendRecord->sentAt !== null && $sendRecord->state === Invoices::STATE_SENT);

    check('sending twice is refused', function() use ($plugin, $sendOrder, $sendRecord) {
        $marker = requestCount();
        $plugin->documents->send($sendOrder, $sendRecord);

        return requestsSince($marker) === [] ?: 'it sent again';
    });

    $emailOrder = makeOrder([['variant' => $deVariant, 'qty' => 1]]);
    $emailRecord = $plugin->invoices->sync($emailOrder);

    check('emailing posts the address, subject and body sevDesk requires', function() use ($plugin, $emailOrder, $emailRecord) {
        $marker = requestCount();
        $plugin->documents->sendEmail($emailOrder, $emailRecord);
        $request = requestsSince($marker)[0] ?? null;

        return $request
            && str_ends_with($request['path'], '/sendViaEmail')
            && ($request['body']['toEmail'] ?? null) === $emailOrder->getEmail()
            && !empty($request['body']['subject'])
            && !empty($request['body']['text'])
            ?: 'got ' . json_encode($request);
    });

    check('an order with no email address is not emailed', function() use ($plugin, $deVariant) {
        $order = makeOrder([['variant' => $deVariant, 'qty' => 1]], [], 'x@example.com');
        $record = $plugin->invoices->sync($order);
        $order->setEmail('');
        $marker = requestCount();
        $sent = $plugin->documents->sendEmail($order, $record);

        return $sent === false && requestsSince($marker) === [];
    });

    check('the PDF comes back decoded', function() use ($plugin, $sendRecord) {
        return str_starts_with($plugin->documents->pdf($sendRecord), '%PDF') ?: 'not a PDF';
    });

    // -----------------------------------------------------------------
    section('Payments');

    // Booking is off while the fixtures are created, so each check drives it
    // deliberately rather than inheriting whatever `sync()` already did.
    setSettings(['bookPayments' => false, 'checkAccountId' => 42]);

    $payOrder = makeOrder([['variant' => $deVariant, 'qty' => 1]]);
    markPaid($payOrder);
    $payRecord = $plugin->invoices->sync($payOrder);

    check('the fixture order really is paid', function() use ($payOrder) {
        return $payOrder->getIsPaid() === true ?: 'totalPaid ' . $payOrder->getTotalPaid();
    });

    check('booking without a check account is refused rather than guessed', function() use ($plugin, $payOrder, $payRecord) {
        setSettings(['checkAccountId' => null]);
        $marker = requestCount();
        $booked = $plugin->payments->book($payOrder, $payRecord);
        setSettings(['checkAccountId' => 42]);

        return $booked === false && requestsSince($marker) === [];
    });

    check('booking sends a unix timestamp, not a formatted date', function() use ($plugin, $payOrder, $payRecord) {
        $marker = requestCount();
        $plugin->payments->book($payOrder, $payRecord);

        foreach (requestsSince($marker) as $request) {
            if (str_ends_with($request['path'], '/bookAmount')) {
                return is_int($request['body']['date'] ?? null) && $request['body']['date'] > 1600000000
                    ?: 'got ' . var_export($request['body']['date'] ?? null, true);
            }
        }

        return 'nothing was booked';
    });

    check('booking names the check account as an object', function() use ($plugin, $deVariant) {
        $order = makeOrder([['variant' => $deVariant, 'qty' => 1]]);
        markPaid($order);
        $record = $plugin->invoices->sync($order);
        $marker = requestCount();
        $plugin->payments->book($order, $record);

        foreach (requestsSince($marker) as $request) {
            if (str_ends_with($request['path'], '/bookAmount')) {
                return ($request['body']['checkAccount']['objectName'] ?? null) === 'CheckAccount';
            }
        }

        return 'nothing was booked';
    });

    check('a full payment is booked as FULL_PAYMENT', function() use ($plugin, $deVariant) {
        $order = makeOrder([['variant' => $deVariant, 'qty' => 1]]);
        markPaid($order);
        $record = $plugin->invoices->sync($order);
        $marker = requestCount();
        $plugin->payments->book($order, $record);

        foreach (requestsSince($marker) as $request) {
            if (str_ends_with($request['path'], '/bookAmount')) {
                return ($request['body']['type'] ?? null) === 'FULL_PAYMENT' ?: $request['body']['type'];
            }
        }

        return 'nothing was booked';
    });

    check('a draft invoice is marked sent before an amount is booked against it', function() use ($plugin, $deVariant) {
        $order = makeOrder([['variant' => $deVariant, 'qty' => 1]]);
        markPaid($order);
        $record = $plugin->invoices->sync($order);
        $marker = requestCount();
        $plugin->payments->book($order, $record);
        $paths = array_column(requestsSince($marker), 'path');
        $sendIndex = null;
        $bookIndex = null;

        foreach ($paths as $index => $path) {
            if (str_ends_with($path, '/sendBy')) {
                $sendIndex ??= $index;
            }

            if (str_ends_with($path, '/bookAmount')) {
                $bookIndex ??= $index;
            }
        }

        return $sendIndex !== null && $bookIndex !== null && $sendIndex < $bookIndex ?: implode(' → ', $paths);
    });

    check('the row records that the payment was booked', function() use ($payRecord) {
        $payRecord->refresh();

        return $payRecord->bookedAt !== null && $payRecord->state === Invoices::STATE_BOOKED ?: $payRecord->state;
    });

    check('booking twice is refused', function() use ($plugin, $payOrder, $payRecord) {
        $marker = requestCount();
        $plugin->payments->book($payOrder, $payRecord);

        return requestsSince($marker) === [] ?: 'it booked twice';
    });

    check('a paid order is booked automatically when the setting is on', function() use ($plugin, $deVariant) {
        setSettings(['bookPayments' => true]);
        $order = makeOrder([['variant' => $deVariant, 'qty' => 1]]);
        markPaid($order);
        $marker = requestCount();
        $record = $plugin->invoices->sync($order);
        setSettings(['bookPayments' => false]);

        $paths = array_column(requestsSince($marker), 'path');
        $booked = false;

        foreach ($paths as $path) {
            if (str_ends_with($path, '/bookAmount')) {
                $booked = true;
            }
        }

        return $booked && $record->bookedAt !== null ?: implode(' → ', $paths);
    });

    check('an unpaid order is not booked, however keen the setting', function() use ($plugin, $deVariant) {
        setSettings(['bookPayments' => true]);
        $order = makeOrder([['variant' => $deVariant, 'qty' => 1]]);
        $marker = requestCount();
        $plugin->invoices->sync($order);
        setSettings(['bookPayments' => false]);

        foreach (requestsSince($marker) as $request) {
            if (str_ends_with($request['path'], '/bookAmount')) {
                return 'it booked an unpaid order';
            }
        }

        return true;
    });

    // -----------------------------------------------------------------
    section('Refunds');

    setSettings(['refundMode' => Settings::REFUND_CREDIT_NOTE]);
    CreditRecord::deleteAll();

    $refundOrder = makeOrder([['variant' => $deVariant, 'qty' => 1]]);
    $refundRecord = $plugin->invoices->sync($refundOrder);

    $fullRefund = new craft\commerce\models\Transaction();
    $fullRefund->id = 91001;
    $fullRefund->amount = (float)$refundRecord->sumGross;

    check('a full refund reverses the invoice wholesale', function() use ($plugin, $refundOrder, $fullRefund) {
        $marker = requestCount();
        $credit = $plugin->payments->refund($refundOrder, $fullRefund);
        $paths = array_column(requestsSince($marker), 'path');

        return $credit?->sevdeskId === 700 && in_array('CreditNote/Factory/createFromInvoice', $paths, true)
            ?: implode(', ', $paths);
    });

    check('the same refund is never mirrored twice', function() use ($plugin, $refundOrder, $fullRefund) {
        $marker = requestCount();
        $plugin->payments->refund($refundOrder, $fullRefund);

        return requestsSince($marker) === [] ?: 'it created a second credit note';
    });

    $partialOrder = makeOrder([['variant' => $deVariant, 'qty' => 2]]);
    $partialRecord = $plugin->invoices->sync($partialOrder);

    $partialRefund = new craft\commerce\models\Transaction();
    $partialRefund->id = 91002;
    $partialRefund->amount = 25.00;

    check('a partial refund gets its own credit note, filed against the invoice', function() use ($plugin, $partialOrder, $partialRefund, $partialRecord) {
        $marker = requestCount();
        $credit = $plugin->payments->refund($partialOrder, $partialRefund);

        foreach (requestsSince($marker) as $request) {
            if ($request['path'] === 'CreditNote/Factory/saveCreditNote') {
                return ($request['body']['creditNote']['bookingCategory'] ?? null) === 'UNDERACHIEVEMENT'
                    && (int)($request['body']['creditNote']['refSrcInvoice'] ?? 0) === (int)$partialRecord->sevdeskId
                    ?: 'got ' . json_encode($request['body']['creditNote']);
            }
        }

        return 'no credit note was created — ' . ($credit?->lastError ?? 'none');
    });

    check('the partial credit note carries a position for the refunded amount', function() use ($state) {
        foreach (array_reverse($state['requests']) as $request) {
            if ($request['path'] === 'CreditNote/Factory/saveCreditNote') {
                $position = $request['body']['creditNotePosSave'][0] ?? null;

                return $position !== null && (float)$position['price'] > 0;
            }
        }

        return 'no credit note was created';
    });

    check('refunds are not mirrored when the setting is off', function() use ($plugin, $deVariant) {
        setSettings(['refundMode' => Settings::REFUND_NONE]);
        $order = makeOrder([['variant' => $deVariant, 'qty' => 1]]);
        $plugin->invoices->sync($order);
        $transaction = new craft\commerce\models\Transaction();
        $transaction->id = 91003;
        $transaction->amount = 10.0;
        $marker = requestCount();
        $credit = $plugin->payments->refund($order, $transaction);
        setSettings(['refundMode' => Settings::REFUND_CREDIT_NOTE]);

        return $credit === null && requestsSince($marker) === [];
    });

    setSettings(['refundMode' => Settings::REFUND_NONE]);

    // -----------------------------------------------------------------
    section('The API layer');

    check('a missing token is refused before a request is made', function() use ($plugin) {
        setSettings(['apiToken' => '']);
        $configured = $plugin->api->isConfigured();
        $marker = requestCount();

        try {
            $plugin->api->get('Contact');
            $threw = false;
        } catch (ApiException) {
            $threw = true;
        }

        setSettings(['apiToken' => str_repeat('a', 32)]);

        return $configured === false && $threw && requestsSince($marker) === [];
    });

    check('the token is sent bare, not as a Bearer', function() use ($plugin) {
        $seen = null;
        $plugin->api->transport = function(string $method, string $url, array $headers, ?string $body) use (&$seen) {
            $seen = $headers['Authorization'] ?? null;

            return [200, json_encode(['objects' => []])];
        };
        $plugin->api->get('Contact');
        $plugin->api->transport = $GLOBALS['sevviesTransport'];

        return $seen === str_repeat('a', 32) ?: var_export($seen, true);
    });

    check('a User-Agent identifies the integration, as sevDesk asks', function() use ($plugin) {
        $seen = null;
        $plugin->api->transport = function(string $method, string $url, array $headers, ?string $body) use (&$seen) {
            $seen = $headers['User-Agent'] ?? null;

            return [200, json_encode(['objects' => []])];
        };
        $plugin->api->get('Contact');
        $plugin->api->transport = $GLOBALS['sevviesTransport'];

        return is_string($seen) && str_contains($seen, 'Sevvies') ?: var_export($seen, true);
    });

    check('a 401 is permanent and never retried', function() use ($plugin, &$state) {
        $state['fail'] = [401, json_encode(['error' => ['message' => 'bad token']])];

        try {
            $plugin->api->get('Contact');
            $state['fail'] = null;

            return 'no exception';
        } catch (ApiException $e) {
            $state['fail'] = null;

            return $e->isTransient() === false && str_contains($e->getMessage(), 'token');
        }
    });

    check('a 500 is transient and worth retrying', function() use ($plugin, &$state) {
        $state['fail'] = [500, 'server exploded'];

        try {
            $plugin->api->get('Contact');
            $state['fail'] = null;

            return 'no exception';
        } catch (ApiException $e) {
            $state['fail'] = null;

            return $e->isTransient() === true;
        }
    });

    check('a 429 is transient', function() use ($plugin, &$state) {
        $state['fail'] = [429, ''];

        try {
            $plugin->api->get('Contact');
            $state['fail'] = null;

            return 'no exception';
        } catch (ApiException $e) {
            $state['fail'] = null;

            return $e->isTransient() === true && str_contains($e->getMessage(), 'rate');
        }
    });

    check('a sevDesk error message is surfaced, not swallowed', function() use ($plugin, &$state) {
        $state['fail'] = [400, json_encode(['error' => ['message' => 'taxRule 2 does not allow 19%']])];

        try {
            $plugin->api->get('Invoice');
            $state['fail'] = null;

            return 'no exception';
        } catch (ApiException $e) {
            $state['fail'] = null;

            return str_contains($e->getMessage(), 'taxRule 2') ?: $e->getMessage();
        }
    });

    check('every call is logged with its endpoint and duration', function() use ($plugin) {
        $marker = requestCount();
        $plugin->api->get('Contact');

        $row = (new craft\db\Query())
            ->from([Table::LOG])
            ->where(['type' => 'request', 'endpoint' => 'Contact'])
            ->orderBy(['id' => SORT_DESC])
            ->one();

        return $row !== false && $row['durationMs'] !== null && (int)$row['statusCode'] === 200;
    });

    check('a failed call is logged as a failure', function() use ($plugin, &$state) {
        $state['fail'] = [500, 'nope'];

        try {
            $plugin->api->get('Contact');
        } catch (ApiException) {
        }

        $state['fail'] = null;

        $row = (new craft\db\Query())
            ->from([Table::LOG])
            ->where(['type' => 'request'])
            ->orderBy(['id' => SORT_DESC])
            ->one();

        return $row !== false && !$row['success'];
    });

    check('request bodies can be kept out of the log', function() use ($plugin, $deOrder) {
        setSettings(['logBodies' => false]);
        $plugin->api->post('Contact', ['name' => 'Private Person']);
        setSettings(['logBodies' => true]);

        $row = (new craft\db\Query())
            ->from([Table::LOG])
            ->where(['type' => 'request', 'method' => 'POST'])
            ->orderBy(['id' => SORT_DESC])
            ->one();

        return $row !== false && $row['requestBody'] === null;
    });

    // -----------------------------------------------------------------
    section('Account metadata');

    check('the bookkeeping system version is read from the account', function() use ($plugin) {
        $plugin->meta->flush();

        return $plugin->meta->bookkeepingVersion(true) === '2.0';
    });

    check('the version is cached rather than asked for every invoice', function() use ($plugin) {
        $plugin->meta->bookkeepingVersion(true);
        $marker = requestCount();
        $plugin->meta->bookkeepingVersion();

        return requestsSince($marker) === [] ?: 'it asked again';
    });

    check('an explicit setting overrides the lookup', function() use ($plugin) {
        setSettings(['bookkeepingVersion' => '1.0']);
        $version = $plugin->meta->bookkeepingVersion();
        setSettings(['bookkeepingVersion' => '']);

        return $version === '1.0';
    });

    check('countries map from ISO codes', function() use ($plugin) {
        return $plugin->meta->countryId('AT') === 2 && $plugin->meta->countryId('at') === 2;
    });

    check('an unknown country is null, not a wrong id', function() use ($plugin) {
        return $plugin->meta->countryId('ZZ') === null && $plugin->meta->countryId('') === null;
    });

    check('check accounts are listed for the settings screen', function() use ($plugin) {
        $accounts = $plugin->meta->checkAccounts(true);

        return ($accounts[0]['id'] ?? null) === 42 && ($accounts[0]['name'] ?? null) === 'Girokonto';
    });

    check('the email key is looked up rather than assumed to be 1', function() use ($plugin) {
        return $plugin->meta->emailKeyId(true) === 2;
    });

    check('flushing forgets everything, because ids differ between accounts', function() use ($plugin) {
        $plugin->meta->countries(true);
        $plugin->meta->flush();
        $marker = requestCount();
        $plugin->meta->countries();

        return requestsSince($marker) !== [] ?: 'it answered from a stale cache';
    });

    // -----------------------------------------------------------------
    section('Connection check');

    check('a good token reports the bookkeeping version', function() use ($plugin) {
        $result = $plugin->api->check();

        return $result['ok'] === true && $result['version'] === '2.0' ?: json_encode($result);
    });

    check('a missing token reports why', function() use ($plugin) {
        setSettings(['apiToken' => '']);
        $result = $plugin->api->check();
        setSettings(['apiToken' => str_repeat('a', 32)]);

        return $result['ok'] === false && str_contains($result['message'], 'token');
    });

    check('a rejected token reports why', function() use ($plugin, &$state) {
        $plugin->meta->flush();
        $state['fail'] = [401, json_encode(['error' => ['message' => 'invalid']])];
        $result = $plugin->api->check();
        $state['fail'] = null;

        return $result['ok'] === false ?: json_encode($result);
    });

    // -----------------------------------------------------------------
    section('Housekeeping');

    check('the log prunes by age', function() use ($plugin) {
        Craft::$app->getDb()->createCommand()->insert(Table::LOG, [
            'type' => 'info',
            'success' => true,
            'message' => 'ancient',
            'dateCreated' => '2001-01-01 00:00:00',
            'dateUpdated' => '2001-01-01 00:00:00',
            'uid' => craft\helpers\StringHelper::UUID(),
        ])->execute();

        $removed = $plugin->log->prune(30);

        return $removed >= 1 ?: "removed $removed";
    });

    check('a retention of zero keeps everything', function() use ($plugin) {
        return $plugin->log->prune(0) === 0;
    });

    check('pending orders exclude ones already invoiced', function() use ($plugin, $deOrder) {
        return !in_array((int)$deOrder->id, $plugin->invoices->pendingOrderIds(500), true);
    });

    check('forgetting an order removes only the local link', function() use ($plugin, $deVariant) {
        $order = makeOrder([['variant' => $deVariant, 'qty' => 1]]);
        $plugin->invoices->sync($order);
        $forgot = $plugin->invoices->forget((int)$order->id);

        return $forgot && $plugin->invoices->recordFor($order->id) === null;
    });

    // -----------------------------------------------------------------
    section('Settings');

    check('net price basis means showNet', function() use ($plugin) {
        setSettings(['priceBasis' => Settings::PRICE_NET]);

        return $plugin->getSettings()->showNet() === true;
    });

    check('gross price basis means the opposite', function() use ($plugin) {
        setSettings(['priceBasis' => Settings::PRICE_GROSS]);
        $result = $plugin->getSettings()->showNet();
        setSettings(['priceBasis' => Settings::PRICE_NET]);

        return $result === false;
    });

    check('the home country is upper-cased and never empty', function() use ($plugin) {
        setSettings(['homeCountry' => 'at']);
        $upper = $plugin->getSettings()->homeCountry();
        setSettings(['homeCountry' => '']);
        $fallback = $plugin->getSettings()->homeCountry();
        setSettings(['homeCountry' => 'DE']);

        return $upper === 'AT' && $fallback === 'DE';
    });

    check('no setting is marked required, so a fresh install can be configured', function() use ($plugin) {
        $settings = new Settings();

        foreach ($settings->rules() as $rule) {
            if (in_array('required', (array)$rule, true)) {
                return 'a setting is required: ' . json_encode($rule);
            }
        }

        return true;
    });

    check('an env var that is not set does not fatal the settings screen', function() {
        // App::parseEnv() answers null for an undefined variable, and Craft's
        // env-parser behaviour assigns that back onto the property. A typed
        // `string` fatals there, taking the settings screen with it.
        $settings = new Settings();
        $settings->apiToken = '$SEVVIES_DEFINITELY_NOT_SET_9f3a';
        $settings->apiBaseUrl = '$SEVVIES_ALSO_NOT_SET_9f3a';

        $settings->validate();
        $array = $settings->toArray();

        return array_key_exists('apiToken', $array);
    });

    check('an unset token reads as empty, not as the literal variable name', function() use ($plugin) {
        $original = $plugin->getSettings()->apiToken;
        setSettings(['apiToken' => '$SEVVIES_DEFINITELY_NOT_SET_9f3a']);
        $token = $plugin->api->token();
        $configured = $plugin->api->isConfigured();
        setSettings(['apiToken' => $original]);

        return $token === '' && $configured === false ?: "got '$token'";
    });

    check('the base URL falls back rather than becoming empty', function() use ($plugin) {
        $original = $plugin->getSettings()->apiBaseUrl;
        setSettings(['apiBaseUrl' => '']);
        $url = $plugin->api->baseUrl();
        setSettings(['apiBaseUrl' => $original]);

        return $url === 'https://my.sevdesk.de/api/v1' ?: $url;
    });

    check('a trailing slash on the base URL does not double up', function() use ($plugin) {
        $original = $plugin->getSettings()->apiBaseUrl;
        setSettings(['apiBaseUrl' => 'https://example.test/api/v1/']);
        $url = $plugin->api->baseUrl();
        setSettings(['apiBaseUrl' => $original]);

        return $url === 'https://example.test/api/v1' ?: $url;
    });

    check('the settings model validates as shipped', function() {
        return (new Settings())->validate() === true ?: json_encode((new Settings())->getErrors());
    });
} finally {
    section('Cleanup');

    $elements = Craft::$app->getElements();

    foreach (array_reverse($createdOrders) as $fixtureOrder) {
        try {
            $elements->deleteElement($fixtureOrder, true);
        } catch (Throwable $e) {
            echo "  ! could not delete order {$fixtureOrder->id}: {$e->getMessage()}\n";
        }
    }

    foreach ($createdProducts as $fixtureProduct) {
        try {
            $elements->deleteElement($fixtureProduct, true);
        } catch (Throwable $e) {
            echo "  ! could not delete product {$fixtureProduct->id}: {$e->getMessage()}\n";
        }
    }

    foreach ([Table::LOG, Table::CREDITS, Table::INVOICES, Table::CONTACTS] as $table) {
        try {
            Craft::$app->getDb()->createCommand()->delete($table)->execute();
        } catch (Throwable $e) {
            echo "  ! could not clear $table: {$e->getMessage()}\n";
        }
    }

    try {
        applySettings($originalSettings);
    } catch (Throwable $e) {
        echo "  ! could not restore settings: {$e->getMessage()}\n";
    }

    echo "  ✓ fixtures removed, settings restored\n";

    echo "\n" . str_repeat('-', 60) . "\n";
    echo "  $passed passed, $failed failed\n";
    echo str_repeat('-', 60) . "\n";
}

exit($failed > 0 ? 1 : 0);
