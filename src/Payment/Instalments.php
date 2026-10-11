<?php

declare(strict_types=1);

namespace Piston\Monetico\Payment;

use DateTimeZone;
use Piston\Monetico\Exception\InvalidField;
use Piston\Monetico\Field\Amount;
use Piston\Monetico\Field\DateFormat;

/**
 * A payment in two, three or four instalments.
 *
 * The platform's rules are short and unforgiving: between two and four due
 * dates, monthly, in one currency, and their sum must be the order's total. It
 * checks all of that itself and answers with a rejected form, so this checks
 * first - except for the sum, which is checked by PaymentRequest, the only
 * object that knows the total.
 *
 * One consequence worth knowing before choosing this over four separate
 * orders: the notification for the second and later instalments arrives weeks
 * or months later, as `paiement_pf2`..`paiement_pf4`, and it is sealed with
 * the algorithm that was in force when the *order* was created. That is the
 * whole reason LegacySealVerifier still exists.
 */
final class Instalments
{
    private const MINIMUM = 2;

    private const MAXIMUM = 4;

    /** @var list<Instalment> */
    private readonly array $instalments;

    private function __construct(Instalment ...$instalments)
    {
        // Named arguments make a variadic's keys strings, so the positions
        // the field names are built from come from here and not from PHP.
        $instalments = array_values($instalments);
        $count = count($instalments);

        if ($count < self::MINIMUM || $count > self::MAXIMUM) {
            throw InvalidField::because(
                'nbrech',
                sprintf('a split payment has between %d and %d instalments', self::MINIMUM, self::MAXIMUM),
                (string) $count,
            );
        }

        $currency = $instalments[0]->amount->currency();
        $previous = null;

        foreach ($instalments as $position => $instalment) {
            if (! $instalment->amount->currency()->equals($currency)) {
                throw InvalidField::mixedCurrencies(
                    sprintf('montantech%d', $position + 1),
                    $currency->code(),
                    $instalment->amount->currency()->code(),
                );
            }

            if ($previous !== null && $instalment->dueOn <= $previous) {
                throw InvalidField::because(
                    sprintf('dateech%d', $position + 1),
                    'instalments must be in chronological order, one month apart',
                    $instalment->dueOn->format('d/m/Y'),
                );
            }

            $previous = $instalment->dueOn;
        }

        $this->instalments = $instalments;
    }

    public static function of(Instalment ...$instalments): self
    {
        return new self(...$instalments);
    }

    public function count(): int
    {
        return count($this->instalments);
    }

    public function total(): Amount
    {
        $total = $this->instalments[0]->amount;

        foreach (array_slice($this->instalments, 1) as $instalment) {
            $total = $total->plus($instalment->amount);
        }

        return $total;
    }

    /**
     * The `nbrech`, `dateechN` and `montantechN` fields.
     *
     * @return array<string, string>
     */
    public function fields(?DateTimeZone $timezone = null): array
    {
        $fields = ['nbrech' => (string) $this->count()];

        foreach ($this->instalments as $position => $instalment) {
            $number = $position + 1;
            $fields['dateech'.$number] = DateFormat::day($instalment->dueOn, $timezone);
            $fields['montantech'.$number] = $instalment->amount->toMonetico();
        }

        return $fields;
    }
}
