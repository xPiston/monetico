<?php

declare(strict_types=1);

namespace Piston\Monetico\Tests\Unit\Field;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Piston\Monetico\Field\DateFormat;

final class DateFormatTest extends TestCase
{
    public function test_it_writes_the_order_date_the_way_the_form_wants_it(): void
    {
        $moment = new DateTimeImmutable('2019-05-24 10:00:25', new DateTimeZone('Europe/Paris'));

        $this->assertSame('24/05/2019:10:00:25', DateFormat::dateTime($moment));
        $this->assertSame('24/05/2019', DateFormat::day($moment));
    }

    /**
     * The quiet one. An application running in UTC that formats its own
     * timestamps sends a time the platform reads as Paris local - so every
     * order is stamped two hours early in summer, and nothing ever fails.
     */
    public function test_it_converts_to_the_platform_timezone_rather_than_assuming_one(): void
    {
        $utc = new DateTimeImmutable('2019-06-24 08:00:25', new DateTimeZone('UTC'));

        $this->assertSame('24/06/2019:10:00:25', DateFormat::dateTime($utc));
    }

    public function test_the_conversion_can_cross_midnight(): void
    {
        $utc = new DateTimeImmutable('2019-06-23 23:30:00', new DateTimeZone('UTC'));

        // Half past eleven in London is half past one the next morning in
        // Paris, and the order date has to say so.
        $this->assertSame('24/06/2019:01:30:00', DateFormat::dateTime($utc));
        $this->assertSame('24/06/2019', DateFormat::day($utc));
    }

    public function test_a_shop_can_insist_on_its_own_zone(): void
    {
        $utc = new DateTimeImmutable('2019-06-24 08:00:25', new DateTimeZone('UTC'));

        $this->assertSame('24/06/2019:08:00:25', DateFormat::dateTime($utc, new DateTimeZone('UTC')));
    }

    #[DataProvider('notificationDates')]
    public function test_it_reads_the_notification_date_in_each_shape_the_documentation_shows(string $raw): void
    {
        $parsed = DateFormat::parseNotification($raw);

        $this->assertInstanceOf(DateTimeImmutable::class, $parsed);
        $this->assertSame('2006-12-05 11:55:23', $parsed->format('Y-m-d H:i:s'));
        $this->assertSame('Europe/Paris', $parsed->getTimezone()->getName());
    }

    /** @return iterable<string, array{string}> */
    public static function notificationDates(): iterable
    {
        yield 'the sample payloads' => ['05/12/2006_a_11:55:23'];
        yield 'the field table' => ['05/12/2006:11:55:23'];
        yield 'the English prose' => ['05/12/2006_at_11:55:23'];
        yield 'with surrounding space' => [' 05/12/2006_a_11:55:23 '];
    }

    /**
     * A date this library cannot read must not stop a notification from being
     * acknowledged, so it is null and not an exception.
     */
    public function test_an_unreadable_date_is_null(): void
    {
        $this->assertNull(DateFormat::parseNotification(''));
        $this->assertNull(DateFormat::parseNotification('yesterday'));
        $this->assertNull(DateFormat::parseNotification('2006-12-05T11:55:23Z'));
    }

    public function test_the_platform_timezone_is_paris(): void
    {
        $this->assertSame('Europe/Paris', DateFormat::platformTimezone()->getName());
    }
}
