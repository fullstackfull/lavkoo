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

    public function test_nothing_is_ever_sent_as_nil(): void
    {
        // `<nil/>` is an extension, not XML-RPC 1.0, and whether an Odoo accepts it depends on how its server was started. A
        // named field with nothing to say is left out of the struct instead, which is what `create` wants.
        //
        // An earlier version of this test also passed a null inside a LIST and asserted it was dropped. That assertion encoded
        // a defect: dropping it shifts every later position. The list case is now a refusal, covered by its own tests above.
        $xml = OdooXmlRpc::request('execute_kw', [['ref' => 'R-1', 'partner_id' => null]]);

        $this->assertStringNotContainsString('nil', $xml);
        $this->assertStringNotContainsString('partner_id', $xml);
        $this->assertStringContainsString('<name>ref</name>', $xml);
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

    // ---- a malformed document is never a meaningful answer -------------------------------------------------------

    public function test_an_array_with_no_data_element_is_refused_and_not_read_as_empty(): void
    {
        // THE distinction this codec exists to keep. `<array><data></data></array>` means Odoo holds no entry under this
        // reference, which is a green light to create one. `<array></array>` is a broken document. Reading the second as the
        // first is how the same cost gets posted twice.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no data element/');
        OdooXmlRpc::response($this->responseOf('<array></array>'));
    }

    public function test_a_real_empty_result_still_comes_back_as_an_empty_array(): void
    {
        $this->assertSame([], OdooXmlRpc::response($this->responseOf('<array><data></data></array>')));
    }

    public function test_a_broken_array_and_an_empty_result_do_not_read_alike(): void
    {
        // Stated as one test, because the two being distinguishable is the property that matters rather than either alone.
        $empty = OdooXmlRpc::response($this->responseOf('<array><data></data></array>'));
        $broken = null;
        try {
            $broken = OdooXmlRpc::response($this->responseOf('<array></array>'));
        } catch (RuntimeException) {
            $broken = 'refused';
        }

        $this->assertNotSame($empty, $broken);
    }

    /** @return array<string, array{0: string}> */
    public static function numbersThatCannotBeRead(): array
    {
        return [
            // Every one of these is 0 or false under PHP's own coercion, and 0 is a meaningful value here: a record id, a
            // debit, a fault code. A cast would turn an unreadable answer into a plausible one.
            'a word where an int belongs' => ['<int>invalid</int>'],
            'an empty int' => ['<int></int>'],
            // The nastiest of the set: PHP would truncate this to 12 without a word.
            'digits with a tail' => ['<int>12abc</int>'],
            'an int that is a float' => ['<int>1.5</int>'],
            'a word where a double belongs' => ['<double>abc</double>'],
            'an empty double' => ['<double></double>'],
            'a boolean that is a word' => ['<boolean>yes</boolean>'],
            'a boolean that is a number' => ['<boolean>2</boolean>'],
            'an empty boolean' => ['<boolean></boolean>'],
        ];
    }

    #[DataProvider('numbersThatCannotBeRead')]
    public function test_a_number_that_cannot_be_read_is_refused_rather_than_coerced(string $value): void
    {
        $this->expectException(RuntimeException::class);
        OdooXmlRpc::response($this->responseOf($value));
    }

    /** @return array<string, array{0: string, 1: mixed}> */
    public static function numbersThatCanBeRead(): array
    {
        return [
            'a record id' => ['<int>412</int>', 412],
            'zero, which is real' => ['<int>0</int>', 0],
            'a negative' => ['<int>-5</int>', -5],
            'a signed positive' => ['<int>+5</int>', 5],
            'an i4' => ['<i4>7</i4>', 7],
            'an amount' => ['<double>1234.5</double>', 1234.5],
            'a negative amount' => ['<double>-0.01</double>', -0.01],
            'zero as a double' => ['<double>0</double>', 0.0],
            'exponent notation' => ['<double>1e3</double>', 1000.0],
            'true' => ['<boolean>1</boolean>', true],
            'false, which is real' => ['<boolean>0</boolean>', false],
        ];
    }

    #[DataProvider('numbersThatCanBeRead')]
    public function test_a_number_that_can_be_read_still_is(string $value, mixed $expected): void
    {
        // The strictness must not have made the decoder useless: zero and false are real answers and have to survive.
        $this->assertSame($expected, OdooXmlRpc::response($this->responseOf($value)));
    }

    // ---- a null shifts a position, so it is refused rather than dropped -------------------------------------------

    public function test_a_null_in_a_positional_list_is_refused_rather_than_dropped(): void
    {
        // `execute_kw` is positional: database, uid, key, model, method, args, kwargs. Dropping the null in
        // ['db', null, 'key'] puts the key where the user id belongs, and the request is well formed while saying the wrong
        // thing -- the hardest kind of bug to see.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/shift position 1/');
        OdooXmlRpc::request('execute_kw', [['db', null, 'key']]);
    }

    public function test_the_refusal_names_the_position_so_a_caller_can_find_it(): void
    {
        try {
            OdooXmlRpc::request('execute_kw', [[1, 2, 3, null]]);
            $this->fail('a null in a list must be refused');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('position 3', $exception->getMessage());
        }
    }

    public function test_a_null_member_of_a_struct_is_still_dropped_because_a_name_shifts_nothing(): void
    {
        // The opposite case keeps its own policy on purpose: struct members are named, so leaving one out moves nothing, and
        // that is exactly what Odoo's `create` wants for a field with nothing to say.
        $xml = OdooXmlRpc::request('execute_kw', [['ref' => 'R-1', 'partner_id' => null]]);

        $this->assertStringNotContainsString('partner_id', $xml);
        $this->assertStringContainsString('<name>ref</name>', $xml);
        $this->assertStringNotContainsString('nil', $xml);
    }

    public function test_a_list_of_real_values_is_unaffected_by_the_rule(): void
    {
        $xml = OdooXmlRpc::request('execute_kw', [['db', 2, 'key']]);

        $this->assertStringContainsString('<value><string>db</string></value>', $xml);
        $this->assertStringContainsString('<value><int>2</int></value>', $xml);
        $this->assertStringContainsString('<value><string>key</string></value>', $xml);
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
