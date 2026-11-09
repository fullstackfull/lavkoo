<?php

namespace Tests\Unit\Accounting;

use App\Services\Accounting\Odoo\OdooFault;
use App\Services\Accounting\Odoo\OdooXmlRpc;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The XML-RPC codec, which exists because PHP dropped `ext-xmlrpc` in 8.0 and this project adds no dependency for one protocol.
 *
 * Two kinds of bug are what these tests are for, and both are expensive:
 *
 *  - **A wrong type corrupts money.** XML-RPC distinguishes `<int>` from `<double>`, and PHP will hand you `18` for `18.0`. An
 *    amount sent as an int silently loses its fraction, and nothing downstream would ever notice.
 *  - **An unreadable answer must never decode to nothing.** A `<fault>`, an HTML error page from a proxy, an empty body: each
 *    has to raise. The one thing that must not happen is a parse failure being read as an empty result, because an empty result
 *    from a search means "Odoo holds no such entry" — and that is the answer that leads to posting the same cost twice.
 */
class OdooXmlRpcTest extends TestCase
{
    // ---- types, because a wrong one corrupts money ---------------------------------------------------------------

    /** @return array<string, array{0: mixed, 1: string}> */
    public static function phpValuesAndTheirXmlRpcTypes(): array
    {
        return [
            'an int is an int' => [3, '<int>3</int>'],
            'a float keeps its fraction' => [1234.50, '<double>1234.5</double>'],
            'a float that is whole stays a double' => [100.0, '<double>100</double>'],
            'true is a boolean' => [true, '<boolean>1</boolean>'],
            'false is a boolean, not an empty string' => [false, '<boolean>0</boolean>'],
            'a numeric string stays a string' => ['18.0', '<string>18.0</string>'],
            'a reference is a string' => ['PKSA-COST-7-ab12cd34', '<string>PKSA-COST-7-ab12cd34</string>'],
        ];
    }

    #[DataProvider('phpValuesAndTheirXmlRpcTypes')]
    public function test_a_value_is_sent_as_the_type_php_says_it_is(mixed $value, string $expected): void
    {
        $this->assertStringContainsString($expected, OdooXmlRpc::request('m', [$value]));
    }

    public function test_a_monetary_amount_is_never_sent_as_an_integer(): void
    {
        // The whole reason the types are chosen from PHP's own and not from the value's shape. `49.00` as `<int>49</int>` would
        // post the right number today and the wrong one the moment a figure has halalas in it.
        $xml = OdooXmlRpc::request('execute_kw', [['credit' => 49.00, 'debit' => 0.0]]);

        $this->assertStringContainsString('<double>49</double>', $xml);
        $this->assertStringNotContainsString('<int>49</int>', $xml);
    }

    public function test_a_struct_carries_its_member_names_and_a_list_becomes_an_array(): void
    {
        $xml = OdooXmlRpc::request('execute_kw', [['ref' => 'R-1'], [1, 2]]);

        $this->assertStringContainsString('<struct><member><name>ref</name>', $xml);
        $this->assertStringContainsString('<array><data><value><int>1</int></value>', $xml);
    }

    public function test_nothing_is_ever_sent_as_nil_and_a_null_member_is_dropped(): void
    {
        // `<nil/>` is an extension, not XML-RPC 1.0, and whether an Odoo accepts it depends on how its server was started.
        $xml = OdooXmlRpc::request('execute_kw', [['ref' => 'R-1', 'partner_id' => null], [null, 'kept']]);

        $this->assertStringNotContainsString('nil', $xml);
        $this->assertStringNotContainsString('partner_id', $xml);
        $this->assertStringContainsString('kept', $xml);
    }

    public function test_text_that_would_break_the_document_is_escaped(): void
    {
        $xml = OdooXmlRpc::request('m', ['a&b<c>"d"']);

        $this->assertStringContainsString('a&amp;b&lt;c&gt;', $xml);
        $this->assertStringContainsString('<methodName>m</methodName>', $xml);
    }

    public function test_the_call_carries_its_parameters_in_the_order_given(): void
    {
        // `execute_kw` is positional -- database, uid, key, model, method, args, kwargs -- so the order is not cosmetic: shifted
        // by one, the client would authenticate with the model name.
        $xml = OdooXmlRpc::request('execute_kw', ['db', 2, 'key', 'account.move', 'create']);

        $this->assertSame([
            '<value><string>db</string></value>',
            '<value><int>2</int></value>',
            '<value><string>key</string></value>',
            '<value><string>account.move</string></value>',
            '<value><string>create</string></value>',
        ], $this->parametersOf($xml));
    }

    // ---- an unreadable answer never decodes to nothing -----------------------------------------------------------

    public function test_the_id_a_create_returns_comes_back_as_an_integer(): void
    {
        $value = OdooXmlRpc::response($this->responseOf('<int>412</int>'));

        $this->assertSame(412, $value);
    }

    public function test_a_search_read_row_comes_back_as_an_array_of_structs(): void
    {
        $value = OdooXmlRpc::response($this->responseOf(
            '<array><data><value><struct>'
            . '<member><name>id</name><value><int>9</int></value></member>'
            . '<member><name>ref</name><value><string>PKSA-COST-9-zz</string></value></member>'
            . '</struct></value></data></array>',
        ));

        $this->assertSame([['id' => 9, 'ref' => 'PKSA-COST-9-zz']], $value);
    }

    public function test_an_empty_search_result_is_an_empty_array_and_not_an_error(): void
    {
        // This is a real answer and the caller must be able to act on it: Odoo holds no entry under that reference.
        $this->assertSame([], OdooXmlRpc::response($this->responseOf('<array><data></data></array>')));
    }

    public function test_a_fault_is_raised_with_its_code_and_odoos_own_words(): void
    {
        try {
            OdooXmlRpc::response(
                '<?xml version="1.0"?><methodResponse><fault><value><struct>'
                . '<member><name>faultCode</name><value><int>3</int></value></member>'
                . '<member><name>faultString</name><value><string>AccessError: not allowed to create account.move</string></value></member>'
                . '</struct></value></fault></methodResponse>',
            );
            $this->fail('a fault must never decode to a value');
        } catch (OdooFault $fault) {
            $this->assertSame(3, $fault->faultCode);
            $this->assertStringContainsString('AccessError', $fault->faultString);
            // The exception MESSAGE stays free of Odoo's prose: it can carry a Python traceback, and a message is logged.
            $this->assertStringNotContainsString('AccessError', $fault->getMessage());
        }
    }

    /** @return array<string, array{0: string}> */
    public static function answersNothingCanBeReadFrom(): array
    {
        return [
            'not xml at all' => ['not xml at all'],
            'a proxy error page' => ['<html><body>502 Bad Gateway</body></html>'],
            'an empty body' => [''],
            'whitespace only' => ["  \n  "],
            'a response with neither params nor fault' => ['<?xml version="1.0"?><methodResponse></methodResponse>'],
            'a param with no value' => ['<?xml version="1.0"?><methodResponse><params><param></param></params></methodResponse>'],
        ];
    }

    #[DataProvider('answersNothingCanBeReadFrom')]
    public function test_an_unreadable_answer_raises_rather_than_decoding_to_nothing(string $body): void
    {
        // RuntimeException specifically, for every one of them: an empty body raises ValueError from the parser, which extends
        // Error and not Exception, so a caller catching Exception would let it escape as a fatal.
        $this->expectException(RuntimeException::class);
        OdooXmlRpc::response($body);
    }

    public function test_an_unknown_value_type_is_refused_rather_than_guessed(): void
    {
        $this->expectException(RuntimeException::class);
        OdooXmlRpc::response($this->responseOf('<decimal>1.5</decimal>'));
    }

    public function test_a_value_with_no_type_element_is_the_string_the_spec_says_it_is(): void
    {
        $this->assertSame('plain', OdooXmlRpc::response($this->responseOf('plain')));
    }

    // ---- the parser refuses a document type, whatever is in it ---------------------------------------------------

    /** @return array<string, array{0: string}> */
    public static function documentsCarryingADoctype(): array
    {
        return [
            // Classic XXE: make this process read a local file and hand its contents back as a value.
            'an external entity reading a file' => [
                '<!DOCTYPE r [<!ENTITY x SYSTEM "file:///etc/passwd">]>'
                . '<methodResponse><params><param><value><string>&x;</string></value></param></params></methodResponse>',
            ],
            // XXE as an outbound request: a cloud metadata endpoint is the usual target.
            'an external entity calling a host' => [
                '<!DOCTYPE r SYSTEM "http://169.254.169.254/latest/meta-data/">'
                . '<methodResponse><params><param><value><int>1</int></value></param></params></methodResponse>',
            ],
            // A nest of internal entities needs no network at all: it expands until the machine runs out of memory.
            'internal entities that expand' => [
                '<!DOCTYPE r [<!ENTITY a "AAAAAAAAAA"><!ENTITY b "&a;&a;&a;&a;&a;&a;&a;&a;&a;&a;">]>'
                . '<methodResponse><params><param><value><string>&b;</string></value></param></params></methodResponse>',
            ],
            // Refused too, and on purpose: a valid XML-RPC response has no use for a document type, so there is no reason to
            // inspect one and decide. One check closes every case above.
            'a harmless doctype with nothing in it' => [
                '<!DOCTYPE methodResponse>'
                . '<methodResponse><params><param><value><int>7</int></value></param></params></methodResponse>',
            ],
        ];
    }

    #[DataProvider('documentsCarryingADoctype')]
    public function test_a_document_type_declaration_is_refused_unread(string $body): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/document type declaration/');
        OdooXmlRpc::response('<?xml version="1.0"?>' . $body);
    }

    public function test_nothing_from_the_file_system_can_reach_a_decoded_value(): void
    {
        // The assertion that matters is not merely that it threw: it is that no file content came back as a value. Written
        // against a file this test creates, so it proves the read was refused rather than that the path happened not to exist.
        $path = tempnam(sys_get_temp_dir(), 'odoo-xxe-');
        file_put_contents($path, 'SECRET-FILE-CONTENTS');

        try {
            $decoded = null;
            try {
                $decoded = OdooXmlRpc::response(
                    '<?xml version="1.0"?><!DOCTYPE r [<!ENTITY x SYSTEM "file://' . $path . '">]>'
                    . '<methodResponse><params><param><value><string>&x;</string></value></param></params></methodResponse>',
                );
            } catch (RuntimeException) {
                // Refused, which is the point.
            }

            $this->assertStringNotContainsString('SECRET-FILE-CONTENTS', (string) json_encode($decoded));
        } finally {
            @unlink($path);
        }
    }

    public function test_a_response_without_a_doctype_is_still_read_normally(): void
    {
        // The guard must not have made the parser useless: an ordinary answer still decodes.
        $this->assertSame(412, OdooXmlRpc::response($this->responseOf('<int>412</int>')));
    }

    /**
     * The `<value>` of each `<param>`, in order, exactly as the document carries it.
     *
     * @return list<string>
     */
    private function parametersOf(string $xml): array
    {
        preg_match_all('#<param>(.*?)</param>#s', $xml, $matches);

        return array_map('trim', $matches[1]);
    }

    private function responseOf(string $value): string
    {
        return '<?xml version="1.0"?><methodResponse><params><param><value>' . $value . '</value></param></params></methodResponse>';
    }
}
