# meraki/schema-json

JSON serialization and deserialization for [`meraki/schema`](https://github.com/merakiframework/schema).

This package keeps serialization concerns out of the core schema domain. It reads
and writes only `meraki/schema`'s public API, so the domain classes stay focused
on defining and validating schemas.

## Usage

```php
use Meraki\Schema\Facade;
use Meraki\Schema\Json\JsonSerializer;
use Meraki\Schema\Json\JsonDeserializer;

$schema = new Facade('signup');
$schema->addNameField('full_name')->minLengthOf(1)->maxLengthOf(255);
$schema->addEmailAddressField('email');

$json = (new JsonSerializer())->serialize($schema);   // -> string
$rebuilt = (new JsonDeserializer())->deserialize($json); // -> Facade (accepts a JSON string or a path to a JSON file)
```

## Format notes

- A field's `type` discriminator is the **kebab-cased short class name**
  (`CreditCard` → `credit-card`, `EmailAddress` → `email-address`), derived from
  the class — not from the domain's `type` property.
- Field `value`s are serialized in their raw stored form (no normalisation), which
  round-trips cleanly back through `prefill()`.

## Local development

`composer.json` links the sibling `../schema` checkout via a Composer path
repository, so local changes to `meraki/schema` are picked up immediately.

```
composer install
composer test
```
