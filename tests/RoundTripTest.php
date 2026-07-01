<?php
declare(strict_types=1);

namespace Meraki\Schema\Json;

use Meraki\Schema\Facade;
use Meraki\Schema\Field\Password;
use Meraki\Schema\Field\Passphrase;
use Meraki\Schema\Field\Placeholder;
use Meraki\Schema\Property\Name;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\CoversClass;

#[Group('serialization')]
#[CoversClass(JsonSerializer::class)]
#[CoversClass(JsonDeserializer::class)]
#[CoversClass(FieldSerializer::class)]
#[CoversClass(RuleSerializer::class)]
final class RoundTripTest extends TestCase
{
	#[Test]
	public function it_round_trips_every_field_type_and_a_rule(): void
	{
		$schema = $this->representativeSchema();
		$serializer = new JsonSerializer();
		$deserializer = new JsonDeserializer();

		$json = $serializer->serialize($schema);
		$rebuilt = $deserializer->deserialize($json);

		// Serializing the rebuilt schema must yield byte-identical JSON: proves the
		// format captures everything needed to reconstruct an equivalent Facade.
		$this->assertSame($json, $serializer->serialize($rebuilt));
		$this->assertCount(count($schema->fields), $rebuilt->fields);
		$this->assertCount(count($schema->rules), $rebuilt->rules);
	}

	#[Test]
	public function it_round_trips_a_collection_whose_item_contains_a_composite(): void
	{
		$schema = new Facade('booking');
		$schema->addCollectionField('lessons', function (Facade $item): void {
			$item->addDateTimeField('when');
			$item->addAddressField('pickup');
			$item->addTextField('notes')->makeOptional();
		})->minItems(1);

		$json = (new JsonSerializer())->serialize($schema);
		$rebuilt = (new JsonDeserializer())->deserialize($json);

		// the nested composite is rebuilt and re-prefixed correctly
		$pickup = $rebuilt->fields->findByName('lessons')->fields->findByName('lessons.pickup');
		$this->assertContains('lessons.pickup.street', $pickup->fields->listFieldNames());

		// the format is complete (byte-identical re-serialize) and still validates
		$this->assertSame($json, (new JsonSerializer())->serialize($rebuilt));
		$addr = ['street' => '1 King St', 'city' => 'Brisbane', 'state' => 'QLD', 'postcode' => '4000', 'country' => 'AU'];
		$result = $rebuilt->validate(['lessons' => [['when' => '2026-03-01T10:00:00', 'pickup' => $addr, 'notes' => 'x']]]);
		$this->assertFalse($result->anyFailed());
	}

	#[Test]
	public function it_uses_kebab_cased_class_name_discriminators_not_the_type_property(): void
	{
		$schema = new Facade('cards');
		$schema->addCreditCardField('card');
		$schema->addEmailAddressField('email');

		$json = (new JsonSerializer())->serialize($schema);

		$this->assertStringContainsString('"type": "credit-card"', $json);
		$this->assertStringContainsString('"type": "email-address"', $json);
		$this->assertStringNotContainsString('credit_card', $json);
		$this->assertStringNotContainsString('email_address', $json);
	}

	#[Test]
	public function it_can_deserialize_what_it_serialized_into_a_usable_schema(): void
	{
		$schema = new Facade('signup');
		$schema->addNameField('full_name')->minLengthOf(1)->maxLengthOf(255);
		$schema->addBooleanField('subscribe');

		$json = (new JsonSerializer())->serialize($schema);
		$rebuilt = (new JsonDeserializer())->deserialize($json);

		$result = $rebuilt->validate(['full_name' => 'Jane Doe', 'subscribe' => true]);

		$this->assertFalse($result->anyFailed());
	}

	#[Test]
	public function it_serializes_composites_with_local_nested_keys_and_no_child_list(): void
	{
		$schema = new Facade('checkout');
		$schema->addMoneyField('price', ['AUD' => 2])->minOf('AUD', '0.00');
		$schema->addAddressField('billing');

		$json = (new JsonSerializer())->serialize($schema);
		$data = json_decode($json, true);

		$this->assertArrayHasKey('currency', $data['fields']['price']['value']);
		$this->assertArrayHasKey('amount', $data['fields']['price']['value']);
		$this->assertStringNotContainsString('price.currency', $json);
		$this->assertStringNotContainsString('price.amount', $json);

		// composites reconstruct their children from config, so carry no child list
		$this->assertArrayNotHasKey('fields', $data['fields']['price']);
		$this->assertArrayNotHasKey('fields', $data['fields']['billing']);

		// unset currency maps render as objects, not empty arrays
		$this->assertStringContainsString('"max": {}', $json);
		$this->assertStringContainsString('"step": {}', $json);
	}

	#[Test]
	public function it_serializes_an_empty_field_set_as_an_object(): void
	{
		$json = (new JsonSerializer())->serialize(new Facade('empty'));

		$this->assertStringContainsString('"fields": {}', $json);
	}

	#[Test]
	public function it_round_trips_a_prefilled_composite_value_using_local_keys(): void
	{
		$schema = new Facade('checkout');
		$schema->addMoneyField('price', ['AUD' => 2])
			->minOf('AUD', '0.00')
			->prefill(['currency' => 'AUD', 'amount' => '1500']);

		$serializer = new JsonSerializer();
		$json = $serializer->serialize($schema);
		$rebuilt = (new JsonDeserializer())->deserialize($json);

		$this->assertSame($json, $serializer->serialize($rebuilt));
		$this->assertSame(
			['currency' => 'AUD', 'amount' => '1500'],
			json_decode($json, true)['fields']['price']['value']
		);
	}

	#[Test]
	public function it_round_trips_a_collection_and_a_must_be_accepted_boolean(): void
	{
		$schema = new Facade('booking');
		$schema->addCollectionField('lessons', function (Facade $item): void {
			$item->addDateField('date');
			$item->addTimeField('time');
		})->minItems(1)->maxItems(5);
		$schema->addBooleanField('terms')->mustBeAccepted();

		$serializer = new JsonSerializer();
		$json = $serializer->serialize($schema);
		$rebuilt = (new JsonDeserializer())->deserialize($json);

		// Byte-identical re-serialization proves the format captures everything.
		$this->assertSame($json, $serializer->serialize($rebuilt));
		$this->assertStringContainsString('"type": "collection"', $json);
		$this->assertStringContainsString('"minItems": 1', $json);
		$this->assertStringContainsString('"maxItems": 5', $json);
		$this->assertStringContainsString('"mustBeAccepted": true', $json);

		// The rebuilt schema validates as expected.
		$result = $rebuilt->validate([
			'lessons' => [['date' => '2026-01-01', 'time' => '10:00:00']],
			'terms' => true,
		]);
		$this->assertFalse($result->anyFailed());
	}

	#[Test]
	public function it_round_trips_a_pair_with_rule_using_not_equals_and_ignore(): void
	{
		$schema = new Facade('contact');
		$schema->addEnumField('contact_method', ['email', 'phone'])
			->pairWith(
				new \Meraki\Schema\Field\EmailAddress(new Name('email_address')),
				function (\Meraki\Schema\Rule\FieldBuilder $rule, \Meraki\Schema\Field\EmailAddress $email): void {
					$rule->when($this)->notEquals('email')->thenMakeOptional($email)->thenIgnore($email);
				}
			);

		$serializer = new JsonSerializer();
		$json = $serializer->serialize($schema);
		$rebuilt = (new JsonDeserializer())->deserialize($json);

		$this->assertSame($json, $serializer->serialize($rebuilt));
		$this->assertStringContainsString('"type": "not_equals"', $json);
		$this->assertStringContainsString('"action": "ignore"', $json);
	}

	private function representativeSchema(): Facade
	{
		$schema = new Facade('everything');

		$schema->addNameField('full_name')->minLengthOf(1)->maxLengthOf(255);
		$schema->addTextField('bio')->minLengthOf(0)->maxLengthOf(500)->matches('/^.*$/');
		$schema->addNumberField('age')->minOf(0)->maxOf(120);
		$schema->addBooleanField('subscribe');
		$schema->addDateField('dob')->from('1900-01-01')->to('2010-01-01')->makeOptional();
		$schema->addDateTimeField('appointment')->from('2020-01-01T00:00')->until('2030-01-01T00:00');
		$schema->addTimeField('meeting');
		$schema->addDurationField('session');
		$schema->addEmailAddressField('email');
		$schema->addEnumField('currency_pref', ['AUD', 'USD']);
		$schema->addMoneyField('salary', ['AUD' => 2, 'USD' => 2])->minOf('AUD', '0.00');
		$schema->addUuidField('id');
		$schema->addUriField('website');
		$schema->addPhoneNumberField('phone', ['AU', 'NZ'])->ofType(\Meraki\Schema\Field\PhoneNumber\Type::Mobile);
		$schema->addPasswordField('password');
		$schema->addPassphraseField('passphrase');
		$schema->addFileField('resume');
		$schema->addCreditCardField('card');
		$schema->addAddressField('address');
		$schema->addVariantField('secret', [
			new Password(new Name('password')),
			new Passphrase(new Name('passphrase')),
		]);
		$schema->addField(new Placeholder(new Name('spacer')));

		$schema->whenAllMatch(
			fn($rule) => $rule->whenEquals('#/fields/subscribe/value', true)->thenRequire('#/fields/email')
		);

		return $schema;
	}
}
