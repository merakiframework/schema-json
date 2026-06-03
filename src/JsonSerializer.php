<?php
declare(strict_types=1);

namespace Meraki\Schema\Json;

use Meraki\Schema\Facade;
use Meraki\Schema\Field\Set as FieldSet;
use Meraki\Schema\Rule\Set as RuleSet;
use Meraki\Schema\Rule\Builder;

/**
 * Serializes a schema Facade to a JSON string:
 *   { "name": ..., "fields": { <name>: <field>, ... }, "rules": [ <rule>, ... ] }
 */
final class JsonSerializer
{
	public function __construct(
		private readonly FieldSerializer $fields = new FieldSerializer(),
		private readonly RuleSerializer $rules = new RuleSerializer(),
	) {
	}

	public function serialize(Facade $schema): string
	{
		$serialized = (object) [
			'name' => (string) $schema->name,
			'fields' => (object) $this->serializeFields($schema->fields),
			'rules' => $this->serializeRules($schema->rules),
		];

		return json_encode($serialized, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
	}

	/**
	 * @return array<string, \stdClass>
	 */
	private function serializeFields(FieldSet $fields): array
	{
		$serialized = [];

		foreach ($fields as $field) {
			$serialized[(string) $field->name] = $this->fields->serialize($field);
		}

		return $serialized;
	}

	/**
	 * @return list<\stdClass>
	 */
	private function serializeRules(RuleSet $rules): array
	{
		$serialized = [];

		foreach ($rules as $rule) {
			if ($rule instanceof Builder) {
				$rule = $rule->build();
			}

			$serialized[] = $this->rules->serialize($rule);
		}

		return $serialized;
	}
}
