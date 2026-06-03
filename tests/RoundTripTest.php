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

		$this->assertFalse($result->failed());
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
		$schema->addPhoneNumberField('phone');
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
