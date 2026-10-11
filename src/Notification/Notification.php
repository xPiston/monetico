<?php

declare(strict_types=1);

namespace Piston\Monetico\Notification;

use DateTimeImmutable;
use Piston\Monetico\Configuration\Environment;
use Piston\Monetico\Field\Amount;
use Piston\Monetico\Field\DateFormat;
use Piston\Monetico\Field\Reference;
use Piston\Monetico\Field\TerminalNumber;
use Piston\Monetico\Security\Seal;
use Piston\Monetico\Security\SealAlgorithm;
use Throwable;

/**
 * A notification from the payment platform - the "Response" interface - read
 * into something an application can act on.
 *
 * **Nothing in this class throws.** That is the design, not an oversight. The
 * endpoint has fifteen seconds to answer, the answer depends on the seal
 * alone, and a notification that is not answered is sent again after a warning
 * email to the merchant. So an unfamiliar card brand, a date in a format this
 * library has not seen, an amount with three decimals: each of them yields
 * null from its own accessor and changes nothing else. The raw fields stay
 * available, in full, for the handler and for the log.
 *
 * It is also worth being clear about what a valid seal proves and what it does
 * not. It proves the message was sealed with the terminal's key and has not
 * been altered. It does not prove the amount is the amount of *your* order -
 * `matches()` is for that - and it does not prove this is the first time the
 * message arrives. The platform calls this endpoint after every attempt on the
 * same reference, and again if an acknowledgement goes missing, so a handler
 * that is not idempotent will book the same payment twice.
 */
final class Notification
{
    /**
     * @param  array<string, string>  $fields  every field received, untouched
     */
    private function __construct(
        private readonly array $fields,
        private readonly ?SealAlgorithm $sealedWith = null,
    ) {}

    /**
     * From an already-decoded request body - `$_POST`, Laravel's
     * `$request->all()`, Symfony's `$request->request->all()`.
     *
     * Values must *not* have been decoded twice. PHP has already
     * percent-decoded the body by the time it reaches any of those, and the
     * documentation's instruction to decode before sealing is about raw bytes,
     * not about a second pass: decoding again turns a `texte-libre` containing
     * `100%25` into `100%` and every seal into a mismatch.
     *
     * @param  array<string, mixed>  $post
     */
    public static function fromArray(array $post): self
    {
        $fields = [];

        foreach ($post as $name => $value) {
            if (is_scalar($value)) {
                $fields[(string) $name] = (string) $value;
            }
        }

        return new self($fields);
    }

    /**
     * From the raw request body, for a framework that hands over bytes.
     *
     * Decodes once, by hand, because `parse_str()` renames anything it does
     * not like the look of: it would turn a field called `a.b` into `a_b` and
     * silently drop it out of the seal.
     */
    public static function fromRawBody(string $body): self
    {
        $fields = [];

        foreach (explode('&', $body) as $pair) {
            if ($pair === '') {
                continue;
            }

            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $fields[urldecode($name)] = urldecode($value);
        }

        return self::fromArray($fields);
    }

    /** Which algorithm's seal matched, once a verifier has checked it. */
    public function sealedWith(): ?SealAlgorithm
    {
        return $this->sealedWith;
    }

    public function withSealAlgorithm(SealAlgorithm $algorithm): self
    {
        return new self($this->fields, $algorithm);
    }

    /** @return array<string, string> */
    public function fields(): array
    {
        return $this->fields;
    }

    public function field(string $name): ?string
    {
        $value = $this->fields[$name] ?? null;

        return $value === null || $value === '' ? null : $value;
    }

    public function seal(): ?Seal
    {
        return $this->safely(fn (): Seal => Seal::fromHex($this->fields['MAC'] ?? ''));
    }

    public function terminal(): ?TerminalNumber
    {
        return $this->safely(fn (): TerminalNumber => TerminalNumber::of($this->fields['TPE'] ?? ''));
    }

    public function reference(): ?Reference
    {
        return $this->safely(fn (): Reference => Reference::of($this->fields['reference'] ?? ''));
    }

    /** What was charged. Absent on a terminal in pre-authorisation. */
    public function amount(): ?Amount
    {
        return $this->safely(fn (): Amount => Amount::fromString($this->fields['montant'] ?? ''));
    }

    /** `montantestime`: what was authorised, on a pre-authorisation terminal. */
    public function estimatedAmount(): ?Amount
    {
        return $this->safely(fn (): Amount => Amount::fromString($this->fields['montantestime'] ?? ''));
    }

    /** `montantech`: the amount of this instalment, on a split payment. */
    public function instalmentAmount(): ?Amount
    {
        return $this->safely(fn (): Amount => Amount::fromString($this->fields['montantech'] ?? ''));
    }

    /** `soldecompte`: what is left on a meal-voucher account. */
    public function remainingBalance(): ?Amount
    {
        return $this->safely(fn (): Amount => Amount::fromString($this->fields['soldecompte'] ?? ''));
    }

    public function returnCode(): ?ReturnCode
    {
        return ReturnCode::tryFromWire($this->fields['code-retour'] ?? '');
    }

    public function rawReturnCode(): string
    {
        return $this->fields['code-retour'] ?? '';
    }

    /**
     * Was the payment taken?
     *
     * False for anything this library cannot read as an acceptance, including
     * a return code it has never seen. The failure mode of guessing wrong in
     * the other direction is shipping an order nobody paid for.
     */
    public function isAccepted(): bool
    {
        return $this->returnCode()?->isAccepted() ?? false;
    }

    /** Which instalment of a split payment this is, or null. */
    public function instalment(): ?int
    {
        return $this->returnCode()?->instalment();
    }

    public function authorisationNumber(): ?string
    {
        return $this->field('numauto');
    }

    public function occurredAt(): ?DateTimeImmutable
    {
        return DateFormat::parseNotification($this->fields['date'] ?? '');
    }

    public function freeText(): ?string
    {
        return $this->field('texte-libre');
    }

    public function card(): Card
    {
        return Card::fromFields($this->fields);
    }

    public function refusalReason(): ?RefusalReason
    {
        return RefusalReason::tryFromWire($this->fields['motifrefus'] ?? '');
    }

    public function authorisationRefusal(): ?AuthorisationRefusal
    {
        return AuthorisationRefusal::tryFromWire($this->fields['motifrefusautorisation'] ?? '');
    }

    public function paymentMethod(): ?PaymentMethod
    {
        return PaymentMethod::tryFromWire($this->fields['modepaiement'] ?? '');
    }

    public function authentication(): ?Authentication
    {
        $field = $this->field('authentification');

        return $field === null ? null : Authentication::fromField($field);
    }

    /** @return list<FilterHit> */
    public function filterHits(): array
    {
        return FilterHit::fromFields($this->fields);
    }

    public function clientIp(): ?string
    {
        return $this->field('ipclient');
    }

    /** `originetr`: where the transaction came from. */
    public function transactionCountry(): ?string
    {
        return $this->field('originetr');
    }

    /** `numero_dossier`, on a pre-authorisation terminal. */
    public function fileNumber(): ?string
    {
        return $this->field('numero_dossier');
    }

    /** `issuer`: who issued the meal voucher, when the brand is Conecs. */
    public function mealVoucherIssuer(): ?string
    {
        return $this->field('issuer');
    }

    /**
     * Is this notification from the environment we think we are in?
     *
     * `payetest` only exists in the sandbox, and the documentation says
     * finding it in production is an anomaly. A shop that sees this return
     * true in production is usually one terminal number away from taking real
     * payments into a test contract, or the reverse - and either is worth an
     * alert rather than a silent success.
     */
    public function looksLikeWrongEnvironment(Environment $environment): bool
    {
        $isSandboxPayment = $this->returnCode()?->isSandboxOnly() ?? false;

        return $isSandboxPayment === $environment->isProduction();
    }

    /**
     * Does this notification describe the order the shop remembers?
     *
     * The seal proves the message is authentic. It does not prove it is about
     * the order whose row you just loaded, or that the amount is the amount
     * you asked for - a payment page that was open while the basket changed,
     * a reference reused by mistake, a replayed body. Comparing both is one
     * line and it closes the gap.
     *
     * The amount is compared by value and currency, so `62.7EUR` and
     * `62.70EUR` are the same money, which they are.
     */
    public function matches(Reference $reference, Amount $amount): bool
    {
        $received = $this->amount() ?? $this->estimatedAmount();

        return $this->reference()?->equals($reference) === true
            && $received !== null
            && $received->equals($amount);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $read
     * @return T|null
     */
    private function safely(callable $read): mixed
    {
        try {
            return $read();
        } catch (Throwable) {
            // Every accessor on this class is allowed to fail on its own.
            return null;
        }
    }
}
