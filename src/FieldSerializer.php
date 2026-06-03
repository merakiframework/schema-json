<?php
declare(strict_types=1);

namespace Meraki\Schema\Json;

use Meraki\Schema\Field;
use Meraki\Schema\Field\Composite;
use Meraki\Schema\Field\Variant;
use Meraki\Schema\Field\Address;
use Meraki\Schema\Field\Boolean;
use Meraki\Schema\Field\CreditCard;
use Meraki\Schema\Field\Date;
use Meraki\Schema\Field\DateTime;
use Meraki\Schema\Field\Duration;
use Meraki\Schema\Field\EmailAddress;
use Meraki\Schema\Field\Enum;
use Meraki\Schema\Field\File;
use Meraki\Schema\Field\Money;
use Meraki\Schema\Field\Name as NameField;
use Meraki\Schema\Field\Number;
use Meraki\Schema\Field\Passphrase;
use Meraki\Schema\Field\Password;
use Meraki\Schema\Field\Placeholder;
use Meraki\Schema\Field\PhoneNumber;
use Meraki\Schema\Field\Text;
use Meraki\Schema\Field\Time;
use Meraki\Schema\Field\Uri;
use Meraki\Schema\Field\Uuid;
use Meraki\Schema\Field\Password\Range;
use Meraki\Schema\Field\EmailAddress\Format;
use Meraki\Schema\Field\Time\Precision as TimePrecisionUnit;
use Meraki\Schema\Field\Time\TruncatePrecision as TimeTruncate;
use Meraki\Schema\Field\Time\PreservePrecision as TimePreserve;
use Meraki\Schema\Field\DateTime\TimePrecision as DateTimePrecisionUnit;
use Meraki\Schema\Field\DateTime\TruncatePrecision as DateTimeTruncate;
use Meraki\Schema\Field\DateTime\PreservePrecision as DateTimePreserve;
use Meraki\Schema\Property\Name as PropertyName;
use Brick\Math\BigDecimal;
use Brick\DateTime\LocalTime;
use Brick\DateTime\LocalDateTime;
use InvalidArgumentException;

/**
 * Converts schema fields to/from their JSON object shape, reading and writing
 * only `meraki/schema`'s public API.
 *
 * Field discriminators are the kebab-cased short class name (see Discriminator),
 * NOT the field's `type` property. Values are serialized in their raw stored form
 * (no normalisation), which round-trips cleanly back through `prefill()`.
 */
final class FieldSerializer
{
	/**
	 * Kebab discriminator => field class. Single source of truth for dispatch;
	 * deliberately independent of Field\Factory's snake_case type map.
	 *
	 * @var array<string, class-string<Field>>
	 */
	private const TYPES = [
		'address' => Address::class,
		'boolean' => Boolean::class,
		'credit-card' => CreditCard::class,
		'date' => Date::class,
		'date-time' => DateTime::class,
		'duration' => Duration::class,
		'email-address' => EmailAddress::class,
		'enum' => Enum::class,
		'file' => File::class,
		'money' => Money::class,
		'name' => NameField::class,
		'number' => Number::class,
		'passphrase' => Passphrase::class,
		'password' => Password::class,
		'phone-number' => PhoneNumber::class,
		'placeholder' => Placeholder::class,
		'text' => Text::class,
		'time' => Time::class,
		'uri' => Uri::class,
		'uuid' => Uuid::class,
		'variant' => Variant::class,
	];

	public function serialize(Field $field): \stdClass
	{
		$data = [
			'type' => Discriminator::fromField($field),
			'name' => (string) $field->name,
			'optional' => $field->optional,
			'value' => $this->serializeValue($field),
		];

		// Only variants carry a child-field list (the type alternatives). Composite
		// fields (Address/CreditCard/Money) recreate their children from their own
		// constructor + config, so they need no children in the JSON.
		if ($field instanceof Variant) {
			$data['fields'] = $this->serializeVariantChildren($field);
		}

		return (object) array_merge($data, $this->extraFor($field));
	}

	public function deserialize(\stdClass $data): Field
	{
		$type = $data->type ?? null;

		if (!is_string($type) || !isset(self::TYPES[$type])) {
			throw new InvalidArgumentException('Unknown field type: ' . var_export($type, true));
		}

		return match (self::TYPES[$type]) {
			Address::class => $this->configureComposite(new Address(new PropertyName($data->name)), $data),
			CreditCard::class => $this->configureComposite(new CreditCard(new PropertyName($data->name)), $data),
			Variant::class => $this->deserializeVariant($data),
			Money::class => $this->deserializeMoney($data),
			Boolean::class => $this->configure(new Boolean(new PropertyName($data->name)), $data),
			Placeholder::class => $this->configure(new Placeholder(new PropertyName($data->name)), $data),
			PhoneNumber::class => $this->configure(new PhoneNumber(new PropertyName($data->name)), $data),
			Date::class => $this->deserializeDate($data),
			DateTime::class => $this->deserializeDateTime($data),
			Duration::class => $this->deserializeDuration($data),
			Time::class => $this->deserializeTime($data),
			Number::class => $this->configure(
				(new Number(new PropertyName($data->name), $data->scale))
					->minOf($data->min)->maxOf($data->max)->inIncrementsOf($data->step),
				$data
			),
			NameField::class => $this->configure(
				(new NameField(new PropertyName($data->name)))->minLengthOf($data->min)->maxLengthOf($data->max),
				$data
			),
			Text::class => $this->configure(
				(new Text(new PropertyName($data->name)))
					->minLengthOf($data->min)->maxLengthOf($data->max)->matches($data->pattern),
				$data
			),
			Uri::class => $this->configure(
				(new Uri(new PropertyName($data->name)))->minLengthOf($data->min)->maxLengthOf($data->max),
				$data
			),
			Uuid::class => $this->configure(
				(new Uuid(new PropertyName($data->name)))->restrictToVersion(...$data->versions),
				$data
			),
			Enum::class => $this->configure(new Enum(new PropertyName($data->name), $data->one_of), $data),
			EmailAddress::class => $this->deserializeEmailAddress($data),
			Password::class => $this->deserializePassword($data),
			Passphrase::class => $this->configure(
				new Passphrase(new PropertyName($data->name), $data->entropy, $data->method, $data->dictionary),
				$data
			),
			File::class => $this->deserializeFile($data),
		};
	}

	/**
	 * Type-specific extra keys, read from each field's public state.
	 *
	 * @return array<string, mixed>
	 */
	private function extraFor(Field $field): array
	{
		return match (true) {
			$field instanceof Date => [
				'from' => (string) $field->from,
				'until' => (string) $field->until,
				'interval' => (string) $field->interval,
			],
			$field instanceof DateTime => [
				'from' => (string) $field->from,
				'until' => (string) $field->until,
				'interval' => (string) $field->interval,
				'precision_unit' => $field->precision->value,
				'precision_mode' => $field->precisionMode(),
			],
			$field instanceof Time => [
				'from' => (string) $field->from,
				'until' => (string) $field->until,
				'step' => (string) $field->step,
				'precision_unit' => $field->precision->value,
				'precision_mode' => $field->precisionMode(),
			],
			$field instanceof Duration => [
				'min' => (string) $field->min,
				'max' => (string) $field->max,
				'step' => (string) $field->step,
			],
			$field instanceof Number => [
				'min' => (string) $field->min,
				'max' => (string) $field->max,
				'step' => (string) $field->step,
				'scale' => $field->scale,
			],
			$field instanceof Text => ['min' => $field->min, 'max' => $field->max, 'pattern' => $field->pattern],
			$field instanceof NameField => ['min' => $field->min, 'max' => $field->max],
			$field instanceof Uri => ['min' => $field->min, 'max' => $field->max],
			$field instanceof Uuid => ['versions' => $field->versions],
			$field instanceof Enum => ['one_of' => $field->oneOf],
			$field instanceof EmailAddress => [
				'format' => $field->format->value,
				'min' => $field->min,
				'max' => $field->max,
				'allowed_domains' => $field->allowedDomains,
				'disallowed_domains' => $field->disallowedDomains,
			],
			$field instanceof Password => [
				'length' => $field->length->toTuple(),
				'lowercase' => $field->lowercase->toTuple(),
				'uppercase' => $field->uppercase->toTuple(),
				'digits' => $field->digits->toTuple(),
				'symbols' => $field->symbols->toTuple(),
				'any_of' => $field->anyOf,
			],
			$field instanceof Passphrase => [
				'entropy' => $field->entropy,
				'method' => $field->method,
				'dictionary' => $field->dictionary,
			],
			$field instanceof File => [
				'min_count' => $field->minCount,
				'max_count' => $field->maxCount,
				'min_size' => $field->minSize,
				'max_size' => $field->maxSize,
				'allowed_types' => $field->allowedTypes,
				'disallowed_types' => $field->disallowedTypes,
				'allowed_sources' => $field->allowedSources,
				'disallowed_sources' => $field->disallowedSources,
			],
			$field instanceof Money => [
				'allowed_currencies' => $field->allowed,
				'min' => (object) $this->flattenDecimals($field->min),
				'max' => (object) $this->flattenDecimals($field->max),
				'step' => (object) $this->flattenDecimals($field->step),
				'scale' => (object) $field->scale,
			],
			default => [],
		};
	}

	private function serializeValue(Field $field): mixed
	{
		if ($field instanceof Composite) {
			return $this->toLocalKeyedValue($field);
		}

		return $field->defaultValue->unwrap();
	}

	/**
	 * Re-keys a composite's value from full prefixed names to the nicer nested
	 * local-name shape, e.g. ['price.currency' => 'AUD'] -> { "currency": "AUD" }.
	 */
	private function toLocalKeyedValue(Composite $field): \stdClass
	{
		$value = (array) $field->defaultValue->unwrap();
		$local = [];

		foreach ($field->fields as $child) {
			$local[(string) $child->name->removePrefix()] = $value[(string) $child->name] ?? null;
		}

		return (object) $local;
	}

	/**
	 * @return list<\stdClass>
	 */
	private function serializeVariantChildren(Variant $field): array
	{
		$children = [];

		foreach ($field->fields as $child) {
			$serialized = $this->serialize($child);
			$serialized->name = (string) $child->name->removePrefix();

			$children[] = $serialized;
		}

		return $children;
	}

	private function configureComposite(Composite $field, \stdClass $data): Composite
	{
		$field->optional = $data->optional;
		$field->prefill($data->value);

		return $field;
	}

	private function deserializeVariant(\stdClass $data): Variant
	{
		// Children are serialized with local names, so passing them to the
		// constructor prefixes them correctly (e.g. "password" -> "secret.password").
		$children = array_map($this->deserialize(...), $data->fields);
		$field = new Variant(new PropertyName($data->name), ...$children);
		$field->optional = $data->optional;
		$field->prefill($data->value);

		return $field;
	}

	private function deserializeMoney(\stdClass $data): Money
	{
		$scale = (array) $data->scale;
		$allowedCurrencies = [];

		foreach ($data->allowed_currencies as $currency) {
			$allowedCurrencies[$currency] = $scale[$currency];
		}

		$field = new Money(new PropertyName($data->name), $allowedCurrencies);
		$field->optional = $data->optional;

		foreach ((array) $data->min as $currency => $amount) {
			$field->minOf($currency, $amount);
		}

		foreach ((array) $data->max as $currency => $amount) {
			$field->maxOf($currency, $amount);
		}

		foreach ((array) $data->step as $currency => $amount) {
			$field->inIncrementsOf($currency, $amount);
		}

		$field->prefill($data->value);

		return $field;
	}

	private function deserializeDate(\stdClass $data): Date
	{
		$field = new Date(new PropertyName($data->name));
		$field->optional = $data->optional;
		$field->prefill($data->value);
		$field->from($data->from);
		$field->until($data->until);
		$field->atIntervalsOf($data->interval);

		return $field;
	}

	private function deserializeDateTime(\stdClass $data): DateTime
	{
		$caster = match ($data->precision_mode) {
			'truncate' => new DateTimeTruncate(),
			'preserve' => new DateTimePreserve(),
			default => throw new InvalidArgumentException('Unknown precision mode: ' . $data->precision_mode),
		};

		$field = new DateTime(new PropertyName($data->name), DateTimePrecisionUnit::from($data->precision_unit), $caster);
		$field->optional = $data->optional;
		$field->prefill($data->value);
		// Set bounds directly: the until()/from() setters truncate to the field's
		// precision, which would mangle a full-precision default bound on round-trip.
		$field->from = LocalDateTime::parse($data->from);
		$field->until = LocalDateTime::parse($data->until);
		$field->inIncrementsOf($data->interval);

		return $field;
	}

	private function deserializeTime(\stdClass $data): Time
	{
		$caster = match ($data->precision_mode) {
			'truncate' => new TimeTruncate(),
			'preserve' => new TimePreserve(),
			default => throw new InvalidArgumentException('Unknown precision mode: ' . $data->precision_mode),
		};

		$field = new Time(new PropertyName($data->name), TimePrecisionUnit::from($data->precision_unit), $caster);
		$field->optional = $data->optional;
		// Set bounds directly: the from()/until() setters truncate to the field's
		// precision, which would mangle a full-precision default bound on round-trip.
		$field->from = LocalTime::parse($data->from);
		$field->until = LocalTime::parse($data->until);

		return $field->inIncrementsOf($data->step)->prefill($data->value);
	}

	private function deserializeDuration(\stdClass $data): Duration
	{
		$field = new Duration(new PropertyName($data->name));
		$field->optional = $data->optional;
		$field->prefill($data->value);
		$field->minOf($data->min);
		$field->maxOf($data->max);
		$field->inIncrementsOf($data->step);

		return $field;
	}

	private function deserializeEmailAddress(\stdClass $data): EmailAddress
	{
		$field = new EmailAddress(new PropertyName($data->name), Format::from($data->format));
		$field->optional = $data->optional;

		return $field->minLengthOf($data->min)
			->maxLengthOf($data->max)
			->allowDomain(...$data->allowed_domains)
			->disallowDomain(...$data->disallowed_domains)
			->prefill($data->value);
	}

	private function deserializePassword(\stdClass $data): Password
	{
		$field = new Password(new PropertyName($data->name));
		$field->optional = $data->optional;
		$field->length = Range::fromTuple($data->length);
		$field->lowercase = Range::fromTuple($data->lowercase);
		$field->uppercase = Range::fromTuple($data->uppercase);
		$field->digits = Range::fromTuple($data->digits);
		$field->symbols = Range::fromTuple($data->symbols);
		$field->anyOf = $data->any_of;

		return $field->prefill($data->value);
	}

	private function deserializeFile(\stdClass $data): File
	{
		$field = new File(new PropertyName($data->name));
		$field->optional = $data->optional;
		$field->allowedSources = $data->allowed_sources;
		$field->disallowedSources = $data->disallowed_sources;

		return $field->atLeast($data->min_count)
			->atMost($data->max_count)
			->minFileSizeOf($data->min_size)
			->maxFileSizeOf($data->max_size)
			->allowTypes(...$data->allowed_types)
			->disallowTypes(...$data->disallowed_types)
			->prefill($data->value);
	}

	private function configure(Field $field, \stdClass $data): Field
	{
		$field->optional = $data->optional;

		return $field->prefill($data->value);
	}

	/**
	 * @param array<string, BigDecimal> $decimals
	 * @return array<string, string>
	 */
	private function flattenDecimals(array $decimals): array
	{
		$flattened = [];

		foreach ($decimals as $currency => $amount) {
			$flattened[strtoupper((string) $currency)] = (string) $amount;
		}

		return $flattened;
	}
}
