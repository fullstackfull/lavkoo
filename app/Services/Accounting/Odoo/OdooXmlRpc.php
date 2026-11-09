<?php

namespace App\Services\Accounting\Odoo;

use DOMDocument;
use DOMElement;
use RuntimeException;

/**
 * XML-RPC, written here because PHP has not shipped it since 7.x.
 *
 * `ext-xmlrpc` was removed from PHP in 8.0 and this project adds no dependency for one protocol, so the encoding is done with
 * DOM and the decoding with libxml — both of which PHP does ship. It is a small spec and the whole of what Odoo's 17.0 and 18.0
 * external API needs; on 19.0 and later the JSON-2 transport is used instead and none of this runs.
 *
 * Two decisions worth stating, because both are places a careless codec corrupts money:
 *
 *  - **An integer stays an integer and a float stays a float.** PHP will happily hand you `18` for `18.0`, and XML-RPC has
 *    separate types: sending an Odoo monetary field as `<int>` would silently drop the fraction. So the type is chosen from
 *    PHP's own, never guessed from the value's shape, and a numeric STRING stays a string because that is what the caller asked
 *    for.
 *  - **No `<nil/>`.** It is an extension, not part of XML-RPC 1.0, and whether a given Odoo accepts it depends on how its
 *    server was started (`allow_none`). Nothing here sends a null: a field with nothing to say is left out of the struct
 *    entirely, which is also what Odoo's own `create` wants.
 *
 * The decoder does not fail soft. A fault is an exception and an unreadable document is an exception, because the alternative is
 * a caller that reads a parse failure as an empty result — and an empty result from a search means "Odoo holds no such entry",
 * which is the one answer that must never be invented.
 */
final class OdooXmlRpc
{
    /**
     * The request body for one call.
     *
     * @param list<mixed> $parameters
     */
    public static function request(string $method, array $parameters): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $call = $document->appendChild($document->createElement('methodCall'));
        $call->appendChild($document->createElement('methodName', $method));
        $params = $call->appendChild($document->createElement('params'));
        foreach ($parameters as $parameter) {
            $param = $params->appendChild($document->createElement('param'));
            $param->appendChild(self::value($document, $parameter));
        }

        return (string) $document->saveXML();
    }

    /**
     * The value one response carries.
     *
     * @throws OdooFault when the server answered a `<fault>`
     * @throws RuntimeException when the document is not a readable methodResponse
     */
    public static function response(string $body): mixed
    {
        // An empty body first, because DOMDocument::loadXML('') raises a ValueError -- which extends Error, not Exception, so a
        // caller catching Exception would let it escape as a fatal instead of treating it as the unreadable answer it is.
        if (trim($body) === '') {
            throw new RuntimeException('Odoo answered with an empty body, so nothing can be read from it.');
        }

        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument();
        // No entity loading: the body comes from a network and an XML parser is a file reader if you let it be one.
        $loaded = $document->loadXML($body, LIBXML_NONET | LIBXML_NOENT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded || $document->documentElement === null || $document->documentElement->nodeName !== 'methodResponse') {
            throw new RuntimeException('Odoo did not answer with a readable XML-RPC response.');
        }

        $fault = $document->getElementsByTagName('fault')->item(0);
        if ($fault instanceof DOMElement) {
            $decoded = (array) self::decode(self::firstElement($fault, 'value') ?? throw new RuntimeException('Odoo sent a fault with no value.'));
            throw new OdooFault(
                (int) ($decoded['faultCode'] ?? 0),
                trim((string) ($decoded['faultString'] ?? 'Odoo refused the call and gave no reason we could read.')),
            );
        }

        $params = $document->getElementsByTagName('params')->item(0);
        if (!$params instanceof DOMElement) {
            throw new RuntimeException('Odoo answered an XML-RPC response with neither params nor a fault.');
        }
        $param = self::firstElement($params, 'param') ?? throw new RuntimeException('Odoo answered with an empty params element.');

        return self::decode(self::firstElement($param, 'value') ?? throw new RuntimeException('Odoo answered a param with no value.'));
    }

    private static function value(DOMDocument $document, mixed $value): DOMElement
    {
        $element = $document->createElement('value');

        // The order matters: bool before int, because PHP's is_int() is false for a bool but a careless reorder would not notice.
        match (true) {
            is_bool($value) => $element->appendChild($document->createElement('boolean', $value ? '1' : '0')),
            is_int($value) => $element->appendChild($document->createElement('int', (string) $value)),
            // 14 significant digits is XML-RPC's practical limit and more than any monetary field needs; `%.10F` avoids the
            // exponent notation that some parsers refuse outright.
            is_float($value) => $element->appendChild($document->createElement('double', rtrim(rtrim(sprintf('%.10F', $value), '0'), '.') ?: '0')),
            is_array($value) => $element->appendChild(self::arrayOrStruct($document, $value)),
            default => $element->appendChild(self::text($document, (string) $value)),
        };

        return $element;
    }

    /** A list becomes an `<array>`, anything with string keys a `<struct>`. A null member is dropped rather than sent as nil. */
    private static function arrayOrStruct(DOMDocument $document, array $value): DOMElement
    {
        if (array_is_list($value)) {
            $array = $document->createElement('array');
            $data = $array->appendChild($document->createElement('data'));
            foreach ($value as $item) {
                if ($item !== null) {
                    $data->appendChild(self::value($document, $item));
                }
            }

            return $array;
        }

        $struct = $document->createElement('struct');
        foreach ($value as $name => $item) {
            if ($item === null) {
                continue;
            }
            $member = $struct->appendChild($document->createElement('member'));
            $member->appendChild(self::text($document, (string) $name, 'name'));
            $member->appendChild(self::value($document, $item));
        }

        return $struct;
    }

    /** A text node, created rather than interpolated so that `&`, `<` and a quote in a reference cannot break the document. */
    private static function text(DOMDocument $document, string $value, string $tag = 'string'): DOMElement
    {
        $element = $document->createElement($tag);
        $element->appendChild($document->createTextNode($value));

        return $element;
    }

    private static function decode(DOMElement $value): mixed
    {
        $typed = self::firstChildElement($value);
        if ($typed === null) {
            // `<value>text</value>` with no type element is a string, which the spec allows and Odoo uses.
            return $value->textContent;
        }

        return match ($typed->nodeName) {
            'int', 'i4', 'i8' => (int) trim($typed->textContent),
            'boolean' => trim($typed->textContent) === '1',
            'double' => (float) trim($typed->textContent),
            // Kept as text: an Odoo date is a string to us, and parsing it here would guess at a timezone nobody stated.
            'string', 'dateTime.iso8601', 'base64' => $typed->textContent,
            'array' => self::decodeArray($typed),
            'struct' => self::decodeStruct($typed),
            'nil' => null,
            default => throw new RuntimeException('Odoo sent an XML-RPC value this client does not know: ' . $typed->nodeName),
        };
    }

    /** @return list<mixed> */
    private static function decodeArray(DOMElement $array): array
    {
        $data = self::firstElement($array, 'data');
        $items = [];
        foreach ($data === null ? [] : $data->childNodes as $child) {
            if ($child instanceof DOMElement && $child->nodeName === 'value') {
                $items[] = self::decode($child);
            }
        }

        return $items;
    }

    /** @return array<string, mixed> */
    private static function decodeStruct(DOMElement $struct): array
    {
        $decoded = [];
        foreach ($struct->childNodes as $member) {
            if (!$member instanceof DOMElement || $member->nodeName !== 'member') {
                continue;
            }
            $name = self::firstElement($member, 'name');
            $value = self::firstElement($member, 'value');
            if ($name !== null && $value !== null) {
                $decoded[$name->textContent] = self::decode($value);
            }
        }

        return $decoded;
    }

    private static function firstElement(DOMElement $parent, string $name): ?DOMElement
    {
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && $child->nodeName === $name) {
                return $child;
            }
        }

        return null;
    }

    private static function firstChildElement(DOMElement $parent): ?DOMElement
    {
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement) {
                return $child;
            }
        }

        return null;
    }
}
