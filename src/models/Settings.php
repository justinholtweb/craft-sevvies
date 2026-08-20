<?php

namespace justinholtweb\sevvies\models;

use craft\base\Model;
use craft\behaviors\EnvAttributeParserBehavior;

/**
 * Plugin settings.
 *
 * Nothing here is `required` — a required setting stops a fresh install from
 * ever reaching the settings screen.
 */
class Settings extends Model
{
    public const TRIGGER_OFF = 'off';
    public const TRIGGER_COMPLETE = 'complete';
    public const TRIGGER_PAID = 'paid';
    public const TRIGGER_STATUS = 'status';

    public const PRICE_NET = 'net';
    public const PRICE_GROSS = 'gross';

    public const SCHEME_STANDARD = 'standard';
    public const SCHEME_SMALL = 'small';

    public const SEND_NONE = 'none';
    public const SEND_MARK = 'mark';
    public const SEND_EMAIL = 'email';

    public const REFUND_NONE = 'none';
    public const REFUND_CREDIT_NOTE = 'creditNote';

    // — Connection ————————————————————————————————————————————————

    /**
     * @var string|null sevDesk API token (32 hex chars). Supports $ENV_VARS.
     *
     * Nullable on purpose: `App::parseEnv()` answers null for an environment
     * variable that is not set, and Craft's env-parser behaviour assigns that
     * straight back onto the property. A typed `string` fatals there — so a
     * typo in an env var name would take down the settings screen.
     */
    public ?string $apiToken = '';

    /** @var string|null API base URL. Only change this to point at a mock. */
    public ?string $apiBaseUrl = 'https://my.sevdesk.de/api/v1';

    /** @var int Request timeout in seconds. */
    public int $timeout = 20;

    /**
     * @var string Bookkeeping system version: '', '1.0' or '2.0'.
     * Empty means "ask sevDesk and cache the answer".
     */
    public string $bookkeepingVersion = '';

    // — When to invoice ———————————————————————————————————————————

    /** @var string One of the TRIGGER_* constants. */
    public string $trigger = self::TRIGGER_PAID;

    /** @var string[] Order status handles that trigger an invoice (TRIGGER_STATUS). */
    public array $triggerStatuses = [];

    /** @var bool Queue the sync instead of running it inline. Checkout must never wait on sevDesk. */
    public bool $useQueue = true;

    /** @var bool Build and log the payload but never send it. */
    public bool $dryRun = false;

    /** @var array|null Pro: an order condition deciding which orders sync. */
    public ?array $orderCondition = null;

    // — Document ——————————————————————————————————————————————————

    /** @var string sevDesk invoice type. RE = normal invoice. */
    public string $invoiceType = 'RE';

    /**
     * @var string How your sevDesk account interprets position prices.
     * Getting this wrong books the wrong money, so Sevvies reconciles the
     * created invoice against Commerce and tells you if it disagrees.
     */
    public string $priceBasis = self::PRICE_NET;

    /** @var int Payment term in days. */
    public int $timeToPay = 14;

    /** @var int|null sevDesk user shown as the document's contact person. Null = the first one. */
    public ?int $contactPersonId = null;

    /** @var int sevDesk Unity id used for positions (1 = Stück/piece). */
    public int $unityId = 1;

    /** @var string Twig template for the document header. */
    public string $headerTemplate = '';

    /** @var string Twig template for the intro text. */
    public string $headTextTemplate = '';

    /** @var string Twig template for the footer text. */
    public string $footTextTemplate = '';

    /** @var string Position name used for the shipping line. */
    public string $shippingName = 'Versandkosten';

    /** @var bool Send each order-level discount as a sevDesk discount rather than folding it into positions. */
    public bool $sendDiscounts = true;

    /** @var bool Include the line item SKU in the position text. */
    public bool $includeSku = true;

    // — Tax ————————————————————————————————————————————————————————

    /** @var string standard (Regelbesteuerung) or small (Kleinunternehmer §19). */
    public string $taxScheme = self::SCHEME_STANDARD;

    /** @var string ISO-3166-1 alpha-2 country you are taxed in. */
    public string $homeCountry = 'DE';

    /** @var bool Pro: derive the VAT rule from the customer's country and VAT ID. */
    public bool $autoTaxRule = true;

    /** @var bool Reverse charge only when the customer supplied a VAT ID. */
    public bool $reverseChargeRequiresVatId = true;

    /** @var bool Pro: use One Stop Shop rules for EU B2C sales. */
    public bool $ossEnabled = false;

    /** @var string OSS flavour: goods (18), electronic (19) or other service (20). */
    public string $ossKind = 'goods';

    /** @var string Field handle on the order/address holding the customer's VAT ID. */
    public string $vatIdField = '';

    /** @var string Fallback tax rule id when nothing else matches. */
    public string $defaultTaxRule = '1';

    /** @var string Tax text printed on the document, e.g. 'Umsatzsteuer 19%'. */
    public string $taxText = '';

    // — Contacts ——————————————————————————————————————————————————

    /** @var bool Create a sevDesk contact when no match exists. */
    public bool $createContacts = true;

    /** @var int sevDesk contact category id (3 = customer). */
    public int $contactCategoryId = 3;

    /** @var bool Reuse an existing sevDesk contact found by email. */
    public bool $matchContactsByEmail = true;

    /** @var bool Let sevDesk assign the customer number. */
    public bool $assignCustomerNumber = true;

    /** @var bool Keep the sevDesk contact's address in step with the order's billing address. */
    public bool $updateContactAddress = true;

    // — After the invoice exists ——————————————————————————————————

    /** @var string none, mark (mark as sent) or email (sevDesk sends it). */
    public string $sendMode = self::SEND_NONE;

    /** @var string Twig template for the email subject. */
    public string $emailSubject = '';

    /** @var string Twig template for the email body. May contain HTML. */
    public string $emailText = '';

    /** @var string Comma-separated addresses to BCC. */
    public string $emailBcc = '';

    /** @var bool Pro: book the payment in sevDesk when Commerce marks the order paid. */
    public bool $bookPayments = false;

    /** @var int|null sevDesk check account the payment is booked onto. */
    public ?int $checkAccountId = null;

    /** @var string Pro: how to mirror Commerce refunds. */
    public string $refundMode = self::REFUND_NONE;

    /** @var bool Pro: store the sevDesk PDF as a Craft asset. */
    public bool $archivePdf = false;

    /** @var string|null Volume UID the PDF is stored in. */
    public ?string $pdfVolumeUid = null;

    /** @var string Subfolder within the volume. Supports {{ order.dateOrdered|date('Y') }}-style Twig. */
    public string $pdfSubpath = '';

    // — Housekeeping ——————————————————————————————————————————————

    /** @var int Days of connection log to keep. 0 keeps everything. */
    public int $logRetentionDays = 30;

    /** @var bool Log full request and response bodies. */
    public bool $logBodies = true;

    /** @var int Times a failed sync is retried by the queue. */
    public int $maxAttempts = 5;

    public function behaviors(): array
    {
        return [
            'parser' => [
                'class' => EnvAttributeParserBehavior::class,
                'attributes' => ['apiToken', 'apiBaseUrl'],
            ],
        ];
    }

    public function rules(): array
    {
        return [
            [['apiBaseUrl'], 'trim'],
            [['timeout'], 'integer', 'min' => 1, 'max' => 300],
            [['timeToPay', 'logRetentionDays'], 'integer', 'min' => 0],
            [['maxAttempts'], 'integer', 'min' => 0, 'max' => 20],
            [['unityId', 'contactCategoryId'], 'integer', 'min' => 1],
            [['checkAccountId', 'contactPersonId'], 'integer', 'min' => 1],
            [['trigger'], 'in', 'range' => [
                self::TRIGGER_OFF, self::TRIGGER_COMPLETE, self::TRIGGER_PAID, self::TRIGGER_STATUS,
            ]],
            [['priceBasis'], 'in', 'range' => [self::PRICE_NET, self::PRICE_GROSS]],
            [['taxScheme'], 'in', 'range' => [self::SCHEME_STANDARD, self::SCHEME_SMALL]],
            [['sendMode'], 'in', 'range' => [self::SEND_NONE, self::SEND_MARK, self::SEND_EMAIL]],
            [['refundMode'], 'in', 'range' => [self::REFUND_NONE, self::REFUND_CREDIT_NOTE]],
            [['ossKind'], 'in', 'range' => ['goods', 'electronic', 'other']],
            [['bookkeepingVersion'], 'in', 'range' => ['', '1.0', '2.0']],
            [['homeCountry'], 'match', 'pattern' => '/^[A-Za-z]{2}$/', 'skipOnEmpty' => true],
            [['invoiceType'], 'in', 'range' => ['RE', 'WKR', 'SR']],
        ];
    }

    /**
     * The home country, upper-cased, never empty.
     */
    /**
     * sevDesk's `showNet` flag. It is not a display option: it tells sevDesk
     * whether the position prices you send are net or gross.
     */
    public function showNet(): bool
    {
        return $this->priceBasis === self::PRICE_NET;
    }

    public function homeCountry(): string
    {
        return strtoupper($this->homeCountry ?: 'DE');
    }

    /**
     * The API base URL, never empty and never trailing-slashed.
     */
    public function apiBaseUrl(): string
    {
        return rtrim(trim((string)$this->apiBaseUrl) ?: 'https://my.sevdesk.de/api/v1', '/');
    }
}
