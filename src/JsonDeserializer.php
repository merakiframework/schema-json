<?php
declare(strict_types=1);

namespace Meraki\Schema\Json;

use Meraki\Schema\Facade;
use Meraki\Schema\Field\Set as FieldSet;
use Meraki\Schema\Rule\Set as RuleSet;
use InvalidArgumentException;

/**
 * Rebuilds a schema Facade from a JSON string (or a path to a JSON file)
 * produced by {@see JsonSerializer}, using only `meraki/schema`'s public API.
 */
final class JsonDeserializer
{
	public function __construct(
		private readonly FieldSerializer $fields = new FieldSerializer(),
		private readonly RuleSerializer $rules = new RuleSerializer(),
	) {
	}

	public function deserialize(string $json): Facade
	{
		if (is_readable($json)) {
			$json = (string) file_get_contents($json);
		}

		$data = json_decode($json, false, 512, JSON_THROW_ON_ERROR);

		if (!is_object($data)) {
			throw new InvalidArgumentException('Expected an object: got ' . gettype($data) . '.');
		}

		if (!property_exists($data, 'name') || !is_string($data->name)) {
			throw new InvalidArgumentException('Expected a string "name" property.');
		}

		return new Facade($data->name, $this->deserializeFields($data), $this->deserializeRules($data));
	}

	private function deserializeFields(object $data): FieldSet
	{
		$fields = new FieldSet();

		foreach ((array) ($data->fields ?? []) as $serializedField) {
			if (!is_object($serializedField)) {
				throw new InvalidArgumentException('Each field must be an object.');
			}

			$fields = $fields->add($this->fields->deserialize($serializedField));
		}

		return $fields;
	}

	private function deserializeRules(object $data): RuleSet
	{
		$rules = new RuleSet();

		foreach ((array) ($data->rules ?? []) as $serializedRule) {
			if (!is_object($serializedRule)) {
				throw new InvalidArgumentException('Each rule must be an object.');
			}

			$rules = $rules->add($this->rules->deserialize($serializedRule));
		}

		return $rules;
	}
}
