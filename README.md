# Smithy PHP Codec

Schema-driven serializers and deserializers for Smithy wire formats. Requires
PHP 8.2 or later.

## Installation

```shell
composer require smithy/codec
```

## Formats

| Namespace | Format |
|---|---|
| `Smithy\Codec\Json` | JSON |
| `Smithy\Codec\Xml` | XML |
| `Smithy\Codec\Cbor` | CBOR |
| `Smithy\Codec\FormUrlEncoded` | Form URL encoding used by query protocols |

Codecs encode and decode message bodies. HTTP bindings and request framing
belong to `smithy/protocol`.

## Serialization

Generated structures implement `SerializableStructInterface` and dispatch
their members to the selected serializer.

```php
use Smithy\Codec\Json\JsonSerializer;

$serializer = new JsonSerializer();
$input->serialize($serializer, 0);
$json = $serializer->flush();
```

Each serializer implements the typed `ShapeSerializerInterface` methods.
Generated code selects the matching method at generation time.

## Deserialization

Codec deserializers read a PSR-7 stream into a positional member buffer.
Schemas define member indexes, target types, traits, and construction
behavior.

```php
use Smithy\Codec\Json\JsonCodec;

$codec = new JsonCodec();
$members = $codec->createMembersBuffer($schema);
$codec->createDeserializer($stream)->deserialize($schema, $members);
```

`$stream` is any PSR-7 `StreamInterface`. Protocol code passes the hydrated
member buffer to the operation output or error factory.

Schema extensions cache wire names, timestamp formats, and dense member
templates for repeated operations.

## Settings

Each format has a settings class for behavior such as default timestamp
formats, wrapper elements, and trait handling. Pass settings to the matching
codec constructor.

The XML codec requires the XMLWriter and SimpleXML extensions. JSON uses the
PHP JSON extension. The CBOR codec uses a PHP implementation and limits
decoded bignum payloads to 1,024 bytes by default. Configure
`CborCodecSettings::maxBigIntegerBytes` when a model requires larger values.

## Dependencies

This package depends on `smithy/core` and `psr/http-message`.

## Design

See [Codec Design](../../docs/design/codec/serialization-codecs.md).

## Contributing

Run the component tests from the monorepo root:

```shell
vendor/bin/phpunit tests/Codec
```
