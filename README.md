<div align="center">

# 💳 Monetico for PHP

**Monetico Paiement, without the folklore.**

Sealed payment forms, verified notifications, capture and refund —
for Laravel, Symfony, or no framework at all.

[![PHP](https://img.shields.io/badge/PHP-8.2%20%E2%86%92%208.5-777BB4?logo=php&logoColor=white)](https://php.net)
[![Laravel](https://img.shields.io/badge/Laravel-12%20%7C%2013-FF2D20?logo=laravel&logoColor=white)](https://laravel.com)
[![Symfony](https://img.shields.io/badge/Symfony-6.4%20%7C%207-000000?logo=symfony&logoColor=white)](https://symfony.com)
[![Tests](https://img.shields.io/badge/tests-444%20passing-22C55E)](#-tests)
[![PHPStan](https://img.shields.io/badge/PHPStan-max-2563EB)](#-tests)
[![License](https://img.shields.io/badge/license-MIT-blue)](#license)

</div>

---

[Monetico Paiement](https://www.monetico-paiement.fr) is the card payment
platform of **Crédit Mutuel and CIC** — the gateway a great many French shops
are handed when they open a merchant account, and one that has to be
integrated by hand: a sealed HTML form, a server-to-server notification to
answer within fifteen seconds, and two more services for collecting and
refunding.

This package does those four things and nothing else. It has no opinion about
your orders, writes nothing to your database, and reaches the network only
when you ask it to collect or refund money.

> Every class in `src/` is commented with **why** it does what it does, not
> what it does. The protocol has a dozen details that look like trivia until a
> payment fails at nine on a Saturday morning, and they are written down next
> to the code that handles them.

## ✨ What's inside

- 🔒 **The seal, done once** — `name=value` pairs in byte order, joined by `*`,
  HMAC-SHA1 over the 20 bytes of your key, compared in constant time
- 🧾 **Payment forms** — rendered as hidden inputs, a whole `<form>`, or a URL
  for the iframe integration, escaped **after** sealing and never before
- 📬 **Notifications you can trust** — the seal recomputed over *every* field
  received, including ones this library has never heard of, plus the
  acknowledgement the platform insists on
- 🛡️ **3D Secure 2 order context** — billing, shipping, basket and customer,
  with the seventy documented attributes checked by name
- 💳 **Capture, partial capture, cancellation and recurrence stop** — with the
  amount arithmetic the platform requires checked before anything is sent
- ↩️ **Refunds**, whole or partial, including the card-only shortcut that omits
  the authorisation number
- 🗓️ **Split payments** in two, three or four instalments — and the
  notifications that arrive months later, sealed the way the order was created
- 🤝 **Partner payment methods** — PayPal, Lyf Pay, paying in 3 or 4 through
  COFIDIS, with the credit application pre-filled (and hex-encoded, as that
  one partner requires)
- 🧩 **Laravel and Symfony bridges** — a service provider and a bundle, each
  about a hundred lines, both optional
- 🧮 **Money as integers**, never floats; currencies with no minor unit and
  currencies with three decimals both handled rather than rounded
- ✅ **444 tests**, PHPStan at `max`, no network, no database

## 🚀 Quick start

```sh
composer require xpiston/monetico
```

Monetico gives you three things when the contract is signed: a **terminal
number** (`TPE`, 7 characters), a **security key** (40 hexadecimal characters)
and a **company code** (`societe`). They are different in the sandbox and in
production — all three of them.

### 1. Send the customer to the payment page

```php
use Piston\Monetico\Monetico;
use Piston\Monetico\Field\{Amount, Reference};
use Piston\Monetico\Payment\PaymentRequest;
use Piston\Monetico\Payment\Context\{BillingAddress, OrderContext};

$monetico = Monetico::sandbox('1234567', $securityKey, 'monSite1');

$form = $monetico->form(new PaymentRequest(
    reference: Reference::of('REF7896543'),
    amount: Amount::fromMinor(6273, 'EUR'),      // 62.73 €, in cents
    orderedAt: new DateTimeImmutable(),
    context: OrderContext::for(
        BillingAddress::create('1 rue de Test', 'Paris', '75001', 'FR'),
    ),
    customerEmail: 'ada@example.com',
    successUrl: 'https://shop.test/merci',
    errorUrl: 'https://shop.test/paiement-refuse',
));

echo $form->toHtml('Payer 62,73 €');
```

`$form->toInputs()` gives you the hidden fields alone, for a template that
wants to own the `<form>` element. `$form->toUrl()` gives you the URL for the
iframe integration.

### 2. Answer the notification

The platform posts the result of **every** attempt to a URL you give it when
the contract is set up — not to your return URLs, which are only where the
customer's browser lands.

```php
use Piston\Monetico\Notification\Acknowledgement;

$notification = $monetico->attemptNotification($_POST);   // null if the seal is wrong

if ($notification !== null && $notification->isAccepted()
    && $notification->matches($order->reference, $order->total)) {
    $order->markPaid($notification->authorisationNumber());
}

header('Content-Type: text/plain');
echo Acknowledgement::for($notification !== null)->body();   // version=2\ncdr=0\n
```

Three things that are not obvious and are not negotiable:

- **The acknowledgement depends on the seal, not on the payment.** A refused
  payment with a valid seal is answered `cdr=0`. Answering `cdr=1` because the
  payment failed makes the platform email the merchant and send the whole thing
  again.
- **You have fifteen seconds.** Emails, invoices, stock: after the
  acknowledgement, in a queue.
- **The same payment can arrive twice.** The endpoint is called after every
  attempt on a reference, and again when an acknowledgement goes missing. Make
  the handler idempotent.

### 3. Collect the money, and send it back

Deferred, partial and pre-authorised payments are not collected until you ask.

```php
use Piston\Monetico\Service\{CaptureRequest, RefundRequest};

$capture = $monetico->capture(CaptureRequest::of(
    reference: $order->reference,
    orderAmount: $order->total,
    toCapture: $order->total,
    orderedOn: $order->placedAt,
));

$capture->isAccepted();            // the money is on its way
$capture->isRetryable();           // the service was busy; this one is worth repeating

$refund = $monetico->refund(RefundRequest::of(
    reference: $order->reference,
    orderAmount: $order->total,
    toRefund: Amount::fromMinor(3200, 'EUR'),
    orderedOn: $order->placedAt,
    remittedOn: $order->remittedOn,
    authorisationNumber: $order->authorisationNumber,
));

$refund->isCompleted();
$refund->isAlreadyDone();          // -46: nothing to do, and not a failure
```

These two call the platform over HTTPS, so they need a **PSR-18 client**. Pass
one to the constructor, or let [`php-http/discovery`](https://github.com/php-http/discovery)
find the one your application already has:

```sh
composer require php-http/discovery guzzlehttp/guzzle   # or symfony/http-client
```

Rendering a form and verifying a notification need no HTTP client at all,
which is why the constructor does not ask for one.

## 🧰 Laravel

The service provider is discovered automatically. Set three values:

```dotenv
MONETICO_TERMINAL=1234567
MONETICO_KEY=0123456789ABCDEF0123456789ABCDEF01234567
MONETICO_COMPANY=monSite1
MONETICO_ENVIRONMENT=sandbox
```

```php
use Illuminate\Http\Request;
use Piston\Monetico\Bridge\Laravel\{AcknowledgementResponse, NotificationRequest};
use Piston\Monetico\Monetico;
use Piston\Monetico\Notification\Acknowledgement;

final class MoneticoNotificationController
{
    public function __construct(private readonly Monetico $monetico)
    {
    }

    public function __invoke(Request $request)
    {
        $notification = $this->monetico->attemptNotification(
            NotificationRequest::fields($request),
        );

        if ($notification?->isAccepted() === true) {
            ProcessPayment::dispatch($notification->fields());
        }

        return AcknowledgementResponse::for(Acknowledgement::for($notification !== null));
    }
}
```

Register the route, and take it out of CSRF protection — the platform is not a
browser and carries no token:

```php
// routes/web.php
Route::post('/monetico/notify', MoneticoNotificationController::class);

// bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->validateCsrfTokens(except: ['monetico/notify']);
})
```

A `419` here is invisible from the shop's side: the platform retries once,
emails the merchant, and the payment sits unrecorded.

`NotificationRequest::fields()` is not decoration. `$request->all()` merges the
query string into the body, so a notification route reached with
`?utm_source=x` would put a field the platform never sealed into the string
the seal is computed over — and every payment would fail verification with
nothing in the logs to say why.

`php artisan vendor:publish --tag=monetico-config` writes the config file, with
the reasoning for each option in it.

Laravel **12 and 13** are in the test matrix. The bridge touches nothing newer
than `ServiceProvider`, `Request` and `Response`, so it works on 11 as well -
but 11 is not tested, because Composer now refuses to install any 11.x release:
they are all covered by security advisories.

## 🧰 Symfony

```yaml
# config/bundles.php
Piston\Monetico\Bridge\Symfony\MoneticoBundle::class => ['all' => true],
```

```yaml
# config/packages/monetico.yaml
monetico:
    terminal: '%env(MONETICO_TERMINAL)%'
    key: '%env(MONETICO_KEY)%'
    company: '%env(MONETICO_COMPANY)%'
    environment: '%env(MONETICO_ENVIRONMENT)%'
```

`Monetico` and `NotificationVerifier` are then autowired like anything else,
and the PSR-18 client is taken from the container as soon as
`symfony/http-client` is installed.

The notification route has to be reachable without a session and without
authentication — if a firewall covers the path, give it `security: false`.

```php
#[Route('/monetico/notify', methods: ['POST'])]
public function notify(Request $request, Monetico $monetico): Response
{
    $notification = $monetico->attemptNotification(NotificationRequest::fields($request));

    return AcknowledgementResponse::for(Acknowledgement::for($notification !== null));
}
```

## 🔐 The seal, in one page

Every message in both directions carries a `MAC`: 40 hexadecimal characters,
HMAC-SHA1 per RFC 2104, keyed with the merchant's security key.

**The key is text, the HMAC needs bytes.** Monetico hands over 40 hexadecimal
characters; the documentation calls the 20-byte form the *operational
representation*. Hashing with the 40-character text produces a perfectly
well-formed seal that the platform rejects every single time, with an error
message that blames your form.

**The string is built, not formatted.** Sorted `name=value` pairs joined by
`*`, in **byte order** — digits, then upper case, then lower case, so `TPE`
comes before `date`. PHP's `ksort()` defaults to `SORT_REGULAR`, which compares
two numeric-looking keys as numbers; this sorts bytes.

**For a notification, every field counts.** The documentation is firm: *all
parameters sent by our server, even the ones unknown or unused by your server*.
So `AlphabeticalSeal` owns no list of field names, and it is important that it
never does — because the sandbox **adds a field with a random name and random
content to every notification**, for the stated purpose of proving that
merchants handle parameters they have never seen.

**There are two algorithms in production.** Monetico changed the calculation,
and it seals a notification the way the *order* was created — so an instalment
plan taken before your migration still calls back, months later, sealed the old
way. Both are accepted by default; `SealVerifier::strict()` turns the old one
off once nothing can still report in.

**Escape last.** The documentation says to HTML-encode the fields *immediately
after* the MAC calculation. Seal `Rue de l&#x27;Église` instead of
`Rue de l'Église` and every payment from an address with an apostrophe fails
while the rest work. `PaymentRequest::fields()` returns raw values;
`PaymentForm` escapes them on the way out, once.

And a seal is compared with `hash_equals`, case-insensitively — the platform
documents the seal it sends in upper case and the one it expects in lower.

## 🧱 How it's built

```
src/
  Monetico.php              the four verbs: form, notification, capture, refund
  Discovery.php             finds a PSR-18 client, if the application has one
  Configuration/
    Credentials.php         terminal + key + company, issued and replaced together
    Environment.php         sandbox or production, and the three URLs that follow
  Security/
    SecurityKey.php         40 hex characters -> 20 bytes, kept out of dumps
    Hmac.php                RFC 2104, tested against RFC 2202's own vectors
    Seal.php                40 hex characters, compared in constant time
    SealAlgorithm.php       how a message becomes the string that gets hashed
    AlphabeticalSeal.php      the current calculation
    LegacyPositionalSeal.php  the one orders created before the migration use
    SealVerifier.php        tries each, and says which one matched
  Field/                    Amount, Currency, Reference, TerminalNumber,
                            CompanyCode, Language, DateFormat
  Payment/
    PaymentRequest.php      one order, as fields - raw, unescaped, unsealed
    PaymentForm.php         the sealed fields, as inputs, HTML or a URL
    PaymentOptions.php      the optional fields, one method per contract option
    Instalments.php         two to four due dates, monthly, adding up
    CofidisCustomer.php     the partner's credit form, pre-filled in hexadecimal
    Context/                the contexte_commande document, for 3D Secure 2
  Notification/
    Notification.php        a notification, read - and nothing in it throws
    NotificationVerifier.php  terminal, then seal, then you may act on it
    Acknowledgement.php     version=2 / cdr=0, which only the seal decides
    Card.php, Authentication.php, FilterHit.php, and the enums
  Service/
    ServiceClient.php       the two server-to-server services, over PSR-18
    CaptureRequest.php      capture, partial capture, cancel, stop recurrence
    RefundRequest.php       whole or partial, with or without the authorisation
    ServiceReply.php        the key=value body both services answer with
  Bridge/Laravel/           a service provider, a config file, two helpers
  Bridge/Symfony/           a bundle, a configuration tree, two helpers
```

### Decisions visible in the code

- **The seal recomputes over what arrived, never over what we expected.** A
  field list is the one thing a verifier must not have; see above.
- **Nothing in `Notification` throws.** The acknowledgement is due in fifteen
  seconds and depends on the seal alone, so an unfamiliar card brand, an
  unparseable date or an amount with three decimals each yield `null` from
  their own accessor and change nothing else. The raw fields stay available in
  full. A card network nobody has heard of must not turn a successful payment
  into an unanswered notification.
- **`cdr` means three different things** in this protocol. In a capture reply
  `1` is accepted and `0` is refused; in a refund reply `0` is success; in the
  acknowledgement, `0` means the seal was valid. `CaptureResult` and
  `RefundResult` are separate classes for that reason alone.
- **A refusal is not a failure.** `isAccepted()` and `isRetryable()` are
  different questions: the bank saying no is an answer, and sending it again
  gets the same one. Only the four errors the documentation says to repeat are
  retryable.
- **`Refus temporaire` is not `Refus critique`.** One says the same card may
  well work in a minute; the other says it never will; `Refus repli VADS` says
  the bank wants the customer authenticated. Telling all three customers "your
  card was declined" loses the sale you had.
- **Money is an integer and a currency.** `62.73` is not representable in
  binary floating point, and one cent of drift between the seal and the amount
  is a rejected payment — one cent between a capture and its order is
  `montant errone`. Currencies with no minor unit (`1000JPY`, not `10.00JPY`)
  are handled, and the seven whose minor unit is a thousandth are refused by
  name rather than quietly rounded, because the field cannot carry them.
- **A valid seal is not a matching order.** It proves the message is authentic,
  not that it is about the order you are holding, for the money you asked for.
  `Notification::matches()` is one line and closes the gap — on value, so
  `15.5EUR` and `15.50EUR` are the same money.
- **The reference has two sets of rules, and they disagree.** The payment form
  takes any printable ASCII up to 50 characters; the capture and refund
  services take letters and digits only. `CMD-2026-0042` is payable and *not*
  collectable, and the way you normally find out is a `commande non
  authentifiee` on the day you try to collect it. `CaptureRequest` and
  `RefundRequest` refuse it up front instead.
- **A capture's amounts must account for the whole order**, and a
  *cancellation's* must not — the documented cancellation of a 100 EUR order
  sends three zeroes. Insisting on the sum rule for both would refuse the one
  example the documentation gives, so `of()` checks it and `cancel()` does not.
- **The dates are Paris local time**, converted rather than assumed. The
  platform reads them as its own clock, the windows are wide (a form lasts
  about twelve hours, a capture twenty-four), so an application sending UTC
  never sees an error — it sees a dashboard where every order is stamped two
  hours early in summer, and a support call about a payment at "08:55" the
  merchant remembers taking at eleven.
- **`payetest` in production is an anomaly**, and so is `paiement` in the
  sandbox. `looksLikeWrongEnvironment()` exists because the failure it catches
  — one wrong terminal number — is either real money landing in a test
  contract or test money being counted as real.
- **The form fields are built from scratch, not from a template.** Optional
  fields with no value are left out rather than sent empty; both are allowed,
  and the shorter message has less to disagree about. (The documentation's own
  example string still carries `options=` and four empty instalment slots from
  an earlier revision of the protocol; `PaymentOptions` has an escape hatch for
  fields newer than this library, and refuses the ones that belong to the
  order.)
- **The two services are not on the payment host.** The form goes to
  `p.monetico-services.com`, capture and refund to `payment-api.e-i.com`.
  Integrations that guessed the second from the first found out on the day of
  their first refund.
- **The key never reaches a log.** `SecurityKey` masks itself in `var_dump()`,
  `dd()` and anything else that walks an object's properties, its constructor
  parameters are `#[SensitiveParameter]`, and the one exception message that
  mentions a bad key does not include it.
- **Refunds have operational limits that are not in the code**: they only work
  from IP addresses Monetico's support has allow-listed, and each address has a
  daily quota agreed with them. `RefundError::NotAuthorised` and
  `TooManyRefunds` are what that looks like at three in the afternoon.
- **Neither service reply is sealed.** Their integrity rests on TLS alone —
  the platform's design, not something a library can improve on. It does mean a
  capture that failed on a transport error must be asked about, never assumed.

## ✅ Tests

```sh
composer test       # 444 tests
composer analyse    # PHPStan, level max
composer test:lint  # Pint
```

No network, no database, no browser: the suite runs in under half a second.

Three groups are worth describing, because they are the ones that would catch
a mistake in this package rather than restate it:

**Against the documentation, not against itself.** Monetico prints the exact
string to hash for an immediate payment, for a split payment and for the older
notification calculation. Those three strings are in the tests, byte for byte,
and the canonical string is compared to them. The HMAC itself is checked
against **RFC 2202's** published vectors, so the key derivation and the hash
are verified by numbers somebody else published.

**Against a fake platform, in both directions.** `tests/Support/FakePlatform`
seals a notification the way Monetico would. A test that only asserts "a wrong
seal is refused" proves nothing — an implementation that refuses everything
passes it. The pair that means something is: a notification sealed exactly as
the platform seals it is accepted, and the same notification with one byte
changed is not. The fake also does what the sandbox does and adds a field with
a random name, which is the test this library exists to pass.

**Against the real frameworks.** The Laravel bridge is tested in a Laravel
application booted by Testbench, and the Symfony bundle by compiling a real
Symfony container. Both earned their keep immediately: the bundle had a service
definition that threw on every application that installed it, and a unit test
would not have noticed.

The historical key transformation gets a test of its own. The official PHP kit
ran the key through a routine that patched up its last two characters; this
package is a plain `hex2bin`, and the suite proves the two agree — exhaustively
over every pair of characters the routine can touch, and over random keys —
rather than asking you to take the docblock's word for it.

## 🤔 Why another package?

There is one other PHP SDK for Monetico,
[`dansmaculotte/monetico-php`](https://github.com/dansmaculotte/monetico-php),
and it deserves credit: it is MIT, it has been there for years, and plenty of
shops have been paid through it. It is also written for PHP 7.2, its last
release is from June 2024, and the three things below were **run** on PHP 8.5
against version 2.0.11 rather than read off its source:

| | |
| :-- | :-- |
| A notification carrying the random field the sandbox adds to **every** payment | fails verification (`validate()` returns `false`) |
| A Conecs meal voucher — `brand=CO`, which the documentation lists | throws `brand value is invalid: CO` before the seal is even checked |
| Constructing the client, and building any form | three deprecation notices, one of them (`${var}` in strings) for syntax **removed in PHP 9** |

The first is the one that matters: the seal is recomputed from a hardcoded list
of field names, so any field Monetico adds — and it adds one on purpose, in the
sandbox, to catch exactly this — makes a good payment look forged. The seals
are also compared with `===` rather than `hash_equals`.

Beyond that, this package exists because the parts above the seal are where the
afternoons go: the amount arithmetic a capture has to satisfy, the two
incompatible rule sets for a reference, the three meanings of `cdr`, the
timezone, the acknowledgement that depends on the seal and not the payment, and
framework wiring that does not quietly put a query parameter into a signature.

## ⚠️ Not included

- **No order, no persistence, no events.** This package verifies and sends; what
  a paid order means is your application's business. The Laravel bridge
  deliberately fires no event: there is nothing it could name that would suit
  every shop.
- **No summary file.** Monetico publishes a daily settlement file; reading it is
  a different job from taking a payment, and it is not here.
- **No additional pre-authorisation invoice**, which goes through the 3D Secure
  emulation service and has its own documentation.
- **No card-alias management screen.** The `aliascb` option is supported; what
  an alias means for a customer's account is yours to decide.
- **No retries, no logging, no queue.** The results say what is worth retrying
  (`isRetryable()`, `isAlreadyDone()`) and your application's own tooling does
  the rest.
- **SHA-1 is not a choice this library makes.** The seal is verified by
  Monetico's servers, so the algorithm is theirs. For what it is worth, the
  collision attacks on SHA-1 do not carry over to HMAC — what protects this
  exchange is the secrecy of the 20-byte key, which is the thing to guard.

## 📚 The documentation this was built from

- Monetico Paiement, *Technical documentation*, version 2.0 (October 2025) —
  the field tables, the seal calculation in appendix 9.3, the service URLs in
  9.8, and the older notification calculation in 9.4.
- RFC 2104 (HMAC) and RFC 2202 (its test vectors).

Where the documentation contradicts itself, the code says so and the tests pin
the behaviour: the notification date appears in three different formats across
three pages (all three are read), and the English edition translates the names
of the return-URL fields in its field table while its own HTML examples keep
`url_retour_ok` and `url_retour_err` (which are the ones sent).

## License

MIT.
