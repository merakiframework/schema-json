<?php
declare(strict_types=1);

namespace Meraki\Schema\Json;

use Meraki\Schema\Field;

/**
 * Derives a serialization "type" discriminator from a field's short class name,
 * kebab-cased — deliberately independent of the domain's `type` property (which
 * is a validation concern and may be removed).
 *
 *   CreditCard   -> "credit-card"
 *   DateTime     -> "date-time"
 *   EmailAddress -> "email-address"
 *   Uuid         -> "uuid"
 */
final class Discriminator
{
	public static function fromField(Field $field): string
	{
		return self::fromClass($field::class);
	}

	public static function fromClass(string $fqcn): string
	{
		$shortName = substr((string) strrchr('\\' . $fqcn, '\\'), 1);

		return self::toKebabCase($shortName);
	}

	public static function toKebabCase(string $pascalCase): string
	{
		return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $pascalCase));
	}

	public static function toPascalCase(string $kebabCase): string
	{
		return str_replace(' ', '', ucwords(str_replace('-', ' ', $kebabCase)));
	}
}
