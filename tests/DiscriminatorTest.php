<?php
declare(strict_types=1);

namespace Meraki\Schema\Json;

use Meraki\Schema\Field\CreditCard;
use Meraki\Schema\Property\Name;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(Discriminator::class)]
final class DiscriminatorTest extends TestCase
{
	#[Test]
	#[DataProvider('classNames')]
	public function it_kebab_cases_short_class_names(string $fqcn, string $expected): void
	{
		$this->assertSame($expected, Discriminator::fromClass($fqcn));
	}

	#[Test]
	public function it_derives_the_discriminator_from_a_field_instance(): void
	{
		$field = new CreditCard(new Name('card'));

		$this->assertSame('credit-card', Discriminator::fromField($field));
	}

	#[Test]
	public function it_round_trips_between_kebab_and_pascal(): void
	{
		$this->assertSame('EmailAddress', Discriminator::toPascalCase('email-address'));
		$this->assertSame('email-address', Discriminator::toKebabCase('EmailAddress'));
	}

	public static function classNames(): array
	{
		return [
			'single word' => ['Meraki\\Schema\\Field\\Date', 'date'],
			'two words' => ['Meraki\\Schema\\Field\\CreditCard', 'credit-card'],
			'date time' => ['Meraki\\Schema\\Field\\DateTime', 'date-time'],
			'email address' => ['Meraki\\Schema\\Field\\EmailAddress', 'email-address'],
			'acronym kept whole' => ['Meraki\\Schema\\Field\\Uuid', 'uuid'],
		];
	}
}
