<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Blade;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\BinaryOp\Equal;
use PhpParser\Node\Expr\BinaryOp\Identical;
use PhpParser\Node\Expr\BinaryOp\NotEqual;
use PhpParser\Node\Expr\BinaryOp\NotIdentical;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\InterpolatedStringPart;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NodeConnectingVisitor;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

/**
 * Finds the code that tells a panel or the API by searching the address of the request for a panel word (api, admin, vendor, seller), which
 * takes /vendor-store, api.example.com and /product/api-gadget for the panel or the API. RequestArea matches whole path segments instead.
 *
 * It reads the code as a syntax tree, so the spelling does not matter: positional or named arguments, any argument order, a haystack wrapped
 * in other calls, held in a variable (one assigned from the address, or named after it) or written in a Blade view; a needle written as a
 * string, an array of strings, a variable or a constant. A call is an offence when it is a string search, a pattern or a wildcard test, an
 * argument of it holds the address of the request, and an argument (or the string it is compared with) is a panel word and nothing else (`/admin`,
 * `api/v3`, `~/(admin|vendor)~`): a needle that names a page (`admin/products/list/vendor?request_status=0`, which the admin menu uses to light
 * its entry) does not tell an area. A wildcard test of the request itself (`request()->is('*admin*')`) is an offence when the pattern does not
 * start at the beginning of the path.
 *
 * It does not follow the address into other functions and it leaves whole-value tests alone (`in_array(request()->segment(1), [...])`).
 */
final class AddressSubstringGuard extends NodeVisitorAbstract
{
    private const PANEL_WORDS = ['api', 'admin', 'vendor', 'seller'];

    private const PANEL_CONSTANT = '~(?:^|_)(?:api|admin|vendor|seller)(?:_|$)~i';

    private const ADDRESS_VARIABLE = '~^(?:current|full|request)_?(?:url|uri|path)$~i';

    private const SERVER_ADDRESS_KEYS = ['REQUEST_URI', 'PATH_INFO', 'PHP_SELF', 'SCRIPT_NAME'];

    private const ADDRESS_METHODS_OF_THE_REQUEST = ['url', 'fullurl', 'path', 'decodedpath', 'getpathinfo', 'getrequesturi', 'geturi'];

    private const ADDRESS_FACADES = ['url', 'request'];

    private const ADDRESS_HELPERS = ['currenturl', 'current_url', 'getcurrenturl'];

    private const SEARCH_FUNCTIONS = [
        'strpos', 'stripos', 'strrpos', 'strripos', 'strstr', 'stristr', 'strrchr', 'str_contains', 'str_starts_with', 'str_ends_with',
        'mb_strpos', 'mb_stripos', 'mb_strrpos', 'mb_strstr', 'substr_count', 'substr', 'substr_compare', 'strncmp', 'strncasecmp',
        'explode', 'preg_match', 'preg_match_all', 'preg_split', 'preg_grep', 'preg_replace', 'preg_replace_callback',
    ];

    private const SEARCH_METHODS = ['contains', 'containsall', 'startswith', 'endswith', 'test', 'match', 'matchall', 'position', 'substrcount'];

    private const WILDCARD_METHODS = ['is', 'fullurlis'];

    /** @var list<array{line: int, code: string}> */
    private array $offences = [];

    /** @var list<array<string, true>> */
    private array $addressVariables = [[]];

    /** @var list<array<string, true>> */
    private array $panelWordVariables = [[]];

    private readonly NodeFinder $finder;

    private readonly Standard $printer;

    private function __construct()
    {
        $this->finder = new NodeFinder();
        $this->printer = new Standard();
    }

    /** @return list<array{line: int, code: string}> */
    public static function offencesInPhp(string $source): array
    {
        $guard = new self();
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NodeConnectingVisitor());
        $traverser->addVisitor($guard);
        $traverser->traverse((new ParserFactory())->createForNewestSupportedVersion()->parse($source) ?? []);

        return $guard->offences;
    }

    /** @return list<array{line: int, code: string}> the line numbers are those of the compiled view, which Blade keeps in step with the template */
    public static function offencesInBlade(string $source): array
    {
        return self::offencesInPhp('<?php ' . "?>\n" . Blade::compileString($source));
    }

    public function enterNode(Node $node)
    {
        if ($node instanceof FunctionLike && !$node instanceof ArrowFunction) {
            $this->addressVariables[] = [];
            $this->panelWordVariables[] = [];
        }
        if ($node instanceof Assign && $node->var instanceof Variable && is_string($node->var->name)) {
            $this->rememberAssignment(name: $node->var->name, value: $node->expr);
        }
        if ($node instanceof FuncCall || $node instanceof StaticCall || $node instanceof MethodCall || $node instanceof NullsafeMethodCall) {
            $this->inspect($node);
        }

        return null;
    }

    public function leaveNode(Node $node)
    {
        if ($node instanceof FunctionLike && !$node instanceof ArrowFunction) {
            array_pop($this->addressVariables);
            array_pop($this->panelWordVariables);
        }

        return null;
    }

    private function rememberAssignment(string $name, Node $value): void
    {
        $scope = array_key_last($this->addressVariables);
        unset($this->addressVariables[$scope][$name], $this->panelWordVariables[$scope][$name]);

        if ($this->holdsAddress([$value])) {
            $this->addressVariables[$scope][$name] = true;
        }
        if ($this->holdsPanelWord([$value])) {
            $this->panelWordVariables[$scope][$name] = true;
        }
    }

    private function inspect(FuncCall|StaticCall|MethodCall|NullsafeMethodCall $call): void
    {
        $arguments = $call->args;
        $name = $this->nameOf($call);

        $offence = match (true) {
            $call instanceof FuncCall && in_array($name, self::SEARCH_FUNCTIONS, true) => $this->searchesTheAddress($call, $arguments),
            $call instanceof FuncCall && $name === 'fnmatch' => $this->holdsAddress($arguments) && $this->holdsWildcardWithPanelWord($arguments),
            $call instanceof StaticCall && $this->isStr($call) && in_array($name, self::SEARCH_METHODS, true) => $this->searchesTheAddress($call, $arguments),
            $call instanceof StaticCall && $this->isStr($call) && $name === 'is' => $this->holdsAddress($arguments) && $this->holdsWildcardWithPanelWord($arguments),
            in_array($name, self::WILDCARD_METHODS, true) && $this->isRequest($call) => $this->holdsWildcardWithPanelWord($arguments),
            $call instanceof MethodCall && in_array($name, self::SEARCH_METHODS, true) => $this->searchesTheAddressThroughAString($call, $arguments),
            default => false,
        };

        if ($offence) {
            $this->offences[] = ['line' => $call->getStartLine(), 'code' => preg_replace('/\s+/', ' ', $this->printer->prettyPrintExpr($call))];
        }
    }

    /** @param array<Node> $arguments */
    private function searchesTheAddress(Node $call, array $arguments): bool
    {
        return $this->holdsAddress($arguments) && ($this->holdsPanelWord($arguments) || $this->comparedWithAPanelWord($call));
    }

    /** `Str::of(request()->url())->lower()->contains('/admin')` and `str(url()->current())->startsWith('/api')` */
    private function searchesTheAddressThroughAString(MethodCall|NullsafeMethodCall $call, array $arguments): bool
    {
        $source = $call->var;
        while ($source instanceof MethodCall || $source instanceof NullsafeMethodCall) {
            $source = $source->var;
        }
        $startsFromAString = ($source instanceof StaticCall && $this->isStr($source) && $this->nameOf($source) === 'of')
            || ($source instanceof FuncCall && $this->nameOf($source) === 'str');

        return $startsFromAString && $this->holdsAddress($source->args) && ($this->holdsPanelWord($arguments) || $this->comparedWithAPanelWord($call));
    }

    private function comparedWithAPanelWord(Node $call): bool
    {
        $parent = $call->getAttribute('parent');
        if (!($parent instanceof Equal || $parent instanceof Identical || $parent instanceof NotEqual || $parent instanceof NotIdentical)) {
            return false;
        }

        return $this->holdsPanelWord([$parent->left === $call ? $parent->right : $parent->left]);
    }

    /** @param array<Node> $nodes */
    private function holdsAddress(array $nodes): bool
    {
        return $this->finder->findFirst($nodes, fn (Node $node): bool => $this->isAddress($node)) !== null;
    }

    /** @param array<Node> $nodes */
    private function holdsPanelWord(array $nodes): bool
    {
        return $this->finder->findFirst($nodes, fn (Node $node): bool => match (true) {
            $node instanceof String_, $node instanceof InterpolatedStringPart => $this->namesAnArea($node->value),
            $node instanceof Variable => is_string($node->name) && isset($this->panelWordVariables[array_key_last($this->panelWordVariables)][$node->name]),
            $node instanceof ClassConstFetch => $node->name instanceof Identifier && preg_match(self::PANEL_CONSTANT, $node->name->name) === 1,
            $node instanceof ConstFetch => preg_match(self::PANEL_CONSTANT, $node->name->getLast()) === 1,
            default => false,
        }) !== null;
    }

    /** @param array<Node> $nodes */
    private function holdsWildcardWithPanelWord(array $nodes): bool
    {
        return $this->finder->findFirst($nodes, fn (Node $node): bool => $node instanceof String_ && str_starts_with($node->value, '*') && $this->namesAnArea($node->value)) !== null;
    }

    /** A needle that is a panel word, with the slashes, wildcards, delimiters, alternatives or version of a pattern, and nothing else: it names an area, not a page. */
    private function namesAnArea(string $text): bool
    {
        $words = array_filter(
            preg_split('~[^a-z0-9_]+~i', $text, flags: PREG_SPLIT_NO_EMPTY),
            fn (string $word): bool => strlen($word) > 1 && preg_match('~^v?\d+$~i', $word) !== 1,
        );

        return $words !== [] && array_diff(array_map('strtolower', $words), self::PANEL_WORDS) === [];
    }

    private function isAddress(Node $node): bool
    {
        return match (true) {
            $node instanceof Variable => is_string($node->name)
                && (isset($this->addressVariables[array_key_last($this->addressVariables)][$node->name]) || preg_match(self::ADDRESS_VARIABLE, $node->name) === 1),
            $node instanceof ArrayDimFetch => $node->var instanceof Variable && $node->var->name === '_SERVER'
                && $node->dim instanceof String_ && in_array($node->dim->value, self::SERVER_ADDRESS_KEYS, true),
            $node instanceof MethodCall, $node instanceof NullsafeMethodCall => $this->isAddressMethod($node),
            $node instanceof StaticCall => $this->isAddressFacade($node),
            default => false,
        };
    }

    private function isAddressMethod(MethodCall|NullsafeMethodCall $call): bool
    {
        $name = $this->nameOf($call);

        return ($call->var instanceof FuncCall && $this->nameOf($call->var) === 'url' && $call->var->args === [] && in_array($name, ['current', 'full'], true))
            || ($this->isRequest($call) && in_array($name, self::ADDRESS_METHODS_OF_THE_REQUEST, true))
            || in_array($name, self::ADDRESS_HELPERS, true)
            || $this->readsAServerAddress($call);
    }

    private function isAddressFacade(StaticCall $call): bool
    {
        $name = $this->nameOf($call);
        $facade = $call->class instanceof Name ? strtolower($call->class->getLast()) : '';

        return (in_array($facade, self::ADDRESS_FACADES, true) && in_array($name, ['current', 'full', ...self::ADDRESS_METHODS_OF_THE_REQUEST], true))
            || in_array($name, self::ADDRESS_HELPERS, true);
    }

    /** `request()->server('REQUEST_URI')` and `$request->server->get('REQUEST_URI')` */
    private function readsAServerAddress(MethodCall|NullsafeMethodCall $call): bool
    {
        foreach ($call->args as $argument) {
            if ($argument instanceof Node\Arg && $argument->value instanceof String_ && in_array($argument->value->value, self::SERVER_ADDRESS_KEYS, true)) {
                return true;
            }
        }

        return false;
    }

    private function isRequest(Node $call): bool
    {
        $receiver = $call instanceof StaticCall ? $call->class : ($call instanceof MethodCall || $call instanceof NullsafeMethodCall ? $call->var : null);

        return match (true) {
            $receiver instanceof Name => strtolower($receiver->getLast()) === 'request',
            $receiver instanceof FuncCall => $this->nameOf($receiver) === 'request',
            $receiver instanceof Variable => is_string($receiver->name) && in_array(strtolower($receiver->name), ['request', 'req'], true),
            $receiver instanceof PropertyFetch => $receiver->name instanceof Identifier && strtolower($receiver->name->name) === 'request',
            default => false,
        };
    }

    private function isStr(StaticCall $call): bool
    {
        return $call->class instanceof Name && $call->class->getLast() === 'Str';
    }

    private function nameOf(FuncCall|StaticCall|MethodCall|NullsafeMethodCall $call): string
    {
        $name = $call->name;

        return match (true) {
            $name instanceof Name => strtolower(ltrim($name->toString(), '\\')),
            $name instanceof Identifier => strtolower($name->name),
            default => '',
        };
    }
}
