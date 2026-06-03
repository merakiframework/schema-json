<?php
declare(strict_types=1);

namespace Meraki\Schema\Json;

use Meraki\Schema\Rule;
use Meraki\Schema\Rule\Condition;
use Meraki\Schema\Rule\Condition\AllOf;
use Meraki\Schema\Rule\Condition\AnyOf;
use Meraki\Schema\Rule\Condition\Equals;
use Meraki\Schema\Rule\Outcome;
use Meraki\Schema\Rule\Outcome\_Require;
use Meraki\Schema\Rule\Outcome\MakeOptional;
use InvalidArgumentException;

/**
 * Converts rules (condition groups + outcomes) to/from their JSON shape via
 * `meraki/schema`'s public API. Condition/outcome discriminators are preserved
 * as-is (`all_of`/`any_of`/`equals`, `require`/`make_optional`).
 */
final class RuleSerializer
{
	public function serialize(Rule $rule): \stdClass
	{
		return (object) [
			'when' => $this->serializeCondition($rule->condition),
			'then' => array_map($this->serializeOutcome(...), $rule->outcomes),
		];
	}

	public function deserialize(\stdClass $data): Rule
	{
		$condition = $this->deserializeCondition($data->when);

		if (!$condition instanceof Rule\ConditionGroup) {
			throw new InvalidArgumentException('A rule\'s top-level "when" must be a condition group.');
		}

		return new Rule($condition, array_map($this->deserializeOutcome(...), $data->then));
	}

	private function serializeCondition(Condition $condition): \stdClass
	{
		return match (true) {
			$condition instanceof AllOf => (object) [
				'type' => 'all_of',
				'conditions' => array_map($this->serializeCondition(...), $condition->conditions()),
			],
			$condition instanceof AnyOf => (object) [
				'type' => 'any_of',
				'conditions' => array_map($this->serializeCondition(...), $condition->conditions()),
			],
			$condition instanceof Equals => (object) [
				'type' => 'equals',
				'target' => $condition->target,
				'expected' => $condition->expected,
			],
			default => throw new InvalidArgumentException('Unknown condition: ' . $condition::class),
		};
	}

	private function deserializeCondition(\stdClass $data): Condition
	{
		return match ($data->type) {
			'all_of' => new AllOf(...array_map($this->deserializeCondition(...), $data->conditions)),
			'any_of' => new AnyOf(...array_map($this->deserializeCondition(...), $data->conditions)),
			'equals' => new Equals($data->target, $data->expected),
			default => throw new InvalidArgumentException('Unknown condition type: ' . $data->type),
		};
	}

	private function serializeOutcome(Outcome $outcome): \stdClass
	{
		return match (true) {
			$outcome instanceof _Require => (object) ['action' => 'require', 'field' => (string) $outcome->getScope()],
			$outcome instanceof MakeOptional => (object) ['action' => 'make_optional', 'field' => (string) $outcome->getScope()],
			default => throw new InvalidArgumentException('Unknown outcome: ' . $outcome::class),
		};
	}

	private function deserializeOutcome(\stdClass $data): Outcome
	{
		return match ($data->action) {
			'require' => new _Require($data->field),
			'make_optional' => new MakeOptional($data->field),
			default => throw new InvalidArgumentException('Unknown outcome action: ' . $data->action),
		};
	}
}
