<?php

namespace App\Services\DeveloperPortal\Generators;

use App\Services\DeveloperPortal\Support\RuleTranslator;

/**
 * Working code for every endpoint, in the languages this ecosystem is actually written in.
 *
 * The reason these are generated rather than written: a hand-written example is correct on the day
 * it is pasted in and slowly stops being correct afterwards. It keeps the old field name, keeps the
 * parameter that became required, keeps the header that changed. A developer copies it, it fails,
 * and they conclude the documentation cannot be trusted — which, by then, it cannot.
 *
 * Every snippet here is assembled from the same manifest the reference table is drawn from: the
 * same path, the same auth header, the same field names and the same synthetic example values. If
 * the endpoint changes, the snippet changes with it in the same request.
 */
class CodeExampleGenerator
{
    /** Dart and Kotlin are first-class here: the storefront's apps are what call this API most. */
    public const LANGUAGES = ['curl', 'dart', 'kotlin', 'swift', 'javascript', 'php'];

    public function __construct(private readonly RuleTranslator $translator)
    {
    }

    /**
     * @param  array<string, mixed>  $endpoint
     * @return array<string, array{label: string, language: string, code: string}>
     */
    public function all(array $endpoint, string $baseUrl): array
    {
        $method = $endpoint['methods'][0] ?? 'GET';
        $context = $this->context($endpoint, $baseUrl, $method);

        return [
            'curl' => ['label' => 'cURL', 'language' => 'bash', 'code' => $this->curl($context)],
            'dart' => ['label' => 'Dart / Flutter', 'language' => 'dart', 'code' => $this->dart($context)],
            'kotlin' => ['label' => 'Kotlin / Android', 'language' => 'kotlin', 'code' => $this->kotlin($context)],
            'swift' => ['label' => 'Swift / iOS', 'language' => 'swift', 'code' => $this->swift($context)],
            'javascript' => ['label' => 'JavaScript', 'language' => 'javascript', 'code' => $this->javascript($context)],
            'php' => ['label' => 'PHP', 'language' => 'php', 'code' => $this->php($context)],
        ];
    }

    // -------------------------------------------------------------------------------------------

    /**
     * Everything the six generators need, worked out once.
     *
     * @return array<string, mixed>
     */
    private function context(array $endpoint, string $baseUrl, string $method): array
    {
        $path = $endpoint['path'];

        // Path parameters become obvious placeholders rather than an id from this database: an
        // example carrying a real order number is a small privacy leak and a large support burden.
        foreach ($endpoint['path_parameters'] as $parameter) {
            $placeholder = ($parameter['type'] ?? 'string') === 'integer' ? '123' : 'example-slug';
            $path = str_replace(
                ['{' . $parameter['name'] . '?}', '{' . $parameter['name'] . '}'],
                $placeholder,
                $path,
            );
        }

        $isQuery = $method === 'GET';
        $body = [];
        $query = [];

        foreach ($endpoint['body'] as $field) {
            $value = $this->translator->example($field);

            if ($isQuery) {
                if (!empty($field['required'])) {
                    $query[$field['name']] = $value;
                }

                continue;
            }

            $body[$field['name']] = $value;
        }

        $token = match ($endpoint['auth']['mechanism'] ?? '') {
            'passport', 'sanctum' => 'CUSTOMER_ACCESS_TOKEN',
            'seller_token' => 'VENDOR_AUTH_TOKEN',
            'delivery_token' => 'DELIVERY_MAN_TOKEN',
            default => ($endpoint['auth']['optional_auth'] ?? false) ? 'CUSTOMER_ACCESS_TOKEN' : null,
        };

        $hasFile = false;
        foreach ($endpoint['body'] as $field) {
            if (($field['type'] ?? null) === 'file') {
                $hasFile = true;
            }
        }

        return [
            'method' => $method,
            'url' => rtrim($baseUrl, '/') . $path . ($query !== [] ? '?' . http_build_query($query) : ''),
            'path' => $path,
            'body' => $body,
            'json' => $body === [] ? null : json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'compactJson' => $body === [] ? null : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'token' => $token,
            'optionalToken' => (bool) ($endpoint['auth']['optional_auth'] ?? false),
            'multipart' => $hasFile,
            'audience' => $endpoint['audience'],
        ];
    }

    private function curl(array $context): string
    {
        $lines = ["curl -X {$context['method']} '{$context['url']}' \\"];
        $lines[] = "  -H 'Accept: application/json' \\";

        if ($context['token'] !== null) {
            $comment = $context['optionalToken'] ? '   # optional: omit to be treated as a guest' : '';
            $lines[] = "  -H 'Authorization: Bearer \${$context['token']}' \\{$comment}";
        }

        if ($context['json'] !== null && !$context['multipart']) {
            $lines[] = "  -H 'Content-Type: application/json' \\";
            $lines[] = "  -d '" . $context['compactJson'] . "'";
        } elseif ($context['multipart']) {
            foreach (array_keys($context['body']) as $field) {
                $lines[] = "  -F '{$field}=@/path/to/file' \\";
            }
        }

        return rtrim(implode("\n", $lines), " \\\n");
    }

    private function dart(array $context): string
    {
        $headers = ["'Accept': 'application/json'"];

        if ($context['token'] !== null) {
            $headers[] = "'Authorization': 'Bearer \$token'";
        }

        if ($context['json'] !== null) {
            $headers[] = "'Content-Type': 'application/json'";
        }

        // The app-version header is what makes safe deprecation possible, and the client id is what
        // makes an app visitor countable as one visitor rather than one carrier NAT. Every
        // generated client sends both, so nobody has to remember to add them later.
        $headers[] = "'X-App-Version': appVersion";
        $headers[] = "'X-Client-Id': clientId";

        $method = strtolower($context['method']);
        $body = $context['json'] !== null ? ",\n  body: jsonEncode(" . $this->dartMap($context['body']) . ')' : '';

        return "import 'dart:convert';\nimport 'package:http/http.dart' as http;\n\n"
            . "final response = await http.{$method}(\n"
            . "  Uri.parse('{$context['url']}'),\n"
            . "  headers: {\n    " . implode(",\n    ", $headers) . ",\n  }{$body},\n);\n\n"
            . "if (response.statusCode == 200) {\n"
            . "  final data = jsonDecode(response.body);\n"
            . "} else {\n"
            . "  // Errors come back as {\"errors\":[{\"code\":\"...\",\"message\":\"...\"}]}\n"
            . "  final errors = jsonDecode(response.body)['errors'];\n"
            . "}";
    }

    private function kotlin(array $context): string
    {
        $builder = ["val request = Request.Builder()", "    .url(\"{$context['url']}\")"];
        $builder[] = '    .header("Accept", "application/json")';

        if ($context['token'] !== null) {
            $builder[] = '    .header("Authorization", "Bearer $token")';
        }

        $builder[] = '    .header("X-App-Version", BuildConfig.VERSION_NAME)';
        $builder[] = '    .header("X-Client-Id", clientId)';

        if ($context['json'] !== null) {
            $builder[] = '    .' . strtolower($context['method']) . '(body)';
        } elseif ($context['method'] !== 'GET') {
            $builder[] = '    .' . strtolower($context['method']) . '(EMPTY_REQUEST)';
        }

        $bodyDeclaration = $context['json'] !== null
            ? "val json = \"\"\"\n{$context['json']}\n\"\"\".trimIndent()\n"
                . "val body = json.toRequestBody(\"application/json\".toMediaType())\n\n"
            : '';

        return "import okhttp3.*\nimport okhttp3.MediaType.Companion.toMediaType\nimport okhttp3.RequestBody.Companion.toRequestBody\n\n"
            . $bodyDeclaration
            . implode("\n", $builder) . "\n    .build()\n\n"
            . "OkHttpClient().newCall(request).execute().use { response ->\n"
            . "    val payload = response.body?.string()\n"
            . "    // A non-2xx carries {\"errors\":[{\"code\":\"...\",\"message\":\"...\"}]}\n"
            . '}';
    }

    private function swift(array $context): string
    {
        $lines = ["var request = URLRequest(url: URL(string: \"{$context['url']}\")!)"];
        $lines[] = "request.httpMethod = \"{$context['method']}\"";
        $lines[] = 'request.setValue("application/json", forHTTPHeaderField: "Accept")';

        if ($context['token'] !== null) {
            $lines[] = 'request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")';
        }

        $lines[] = 'request.setValue(appVersion, forHTTPHeaderField: "X-App-Version")';
        $lines[] = 'request.setValue(clientId, forHTTPHeaderField: "X-Client-Id")';

        if ($context['json'] !== null) {
            $lines[] = 'request.setValue("application/json", forHTTPHeaderField: "Content-Type")';
            $lines[] = 'request.httpBody = try JSONSerialization.data(withJSONObject: ' . $this->swiftDictionary($context['body']) . ')';
        }

        return "import Foundation\n\n" . implode("\n", $lines) . "\n\n"
            . "let (data, response) = try await URLSession.shared.data(for: request)\n"
            . "let payload = try JSONSerialization.jsonObject(with: data)";
    }

    private function javascript(array $context): string
    {
        $headers = ["'Accept': 'application/json'"];

        if ($context['token'] !== null) {
            $headers[] = "'Authorization': `Bearer \${token}`";
        }

        if ($context['json'] !== null) {
            $headers[] = "'Content-Type': 'application/json'";
        }

        $body = $context['json'] !== null
            ? ",\n  body: JSON.stringify(" . str_replace("\n", "\n  ", (string) $context['json']) . ')'
            : '';

        return "const response = await fetch('{$context['url']}', {\n"
            . "  method: '{$context['method']}',\n"
            . "  headers: {\n    " . implode(",\n    ", $headers) . ",\n  }{$body},\n});\n\n"
            . "const payload = await response.json();\n\n"
            . "if (!response.ok) {\n"
            . "  // { errors: [{ code, message }] }\n"
            . "  throw new Error(payload.errors?.[0]?.message ?? 'Request failed');\n"
            . '}';
    }

    private function php(array $context): string
    {
        $headers = ["'Accept' => 'application/json'"];

        if ($context['token'] !== null) {
            $headers[] = "'Authorization' => 'Bearer ' . \$token";
        }

        $call = $context['json'] !== null
            ? "->" . strtolower($context['method']) . "('{$context['url']}', " . $this->phpArray($context['body']) . ')'
            : "->" . strtolower($context['method']) . "('{$context['url']}')";

        return "use Illuminate\\Support\\Facades\\Http;\n\n"
            . "\$response = Http::withHeaders([\n    " . implode(",\n    ", $headers) . ",\n])\n    {$call};\n\n"
            . "if (\$response->failed()) {\n"
            . "    // ['errors' => [['code' => '...', 'message' => '...']]]\n"
            . "    \$errors = \$response->json('errors');\n"
            . "}\n\n"
            . '$payload = $response->json();';
    }

    // -------------------------------------------------------------------------------------------

    private function dartMap(array $body): string
    {
        if ($body === []) {
            return '{}';
        }

        $pairs = [];

        foreach ($body as $key => $value) {
            $pairs[] = "    '{$key}': " . $this->literal($value, 'dart');
        }

        return "{\n" . implode(",\n", $pairs) . ",\n  }";
    }

    private function swiftDictionary(array $body): string
    {
        if ($body === []) {
            return '[:]';
        }

        $pairs = [];

        foreach ($body as $key => $value) {
            $pairs[] = "    \"{$key}\": " . $this->literal($value, 'swift');
        }

        return "[\n" . implode(",\n", $pairs) . "\n]";
    }

    private function phpArray(array $body): string
    {
        if ($body === []) {
            return '[]';
        }

        $pairs = [];

        foreach ($body as $key => $value) {
            $pairs[] = "        '{$key}' => " . $this->literal($value, 'php');
        }

        return "[\n" . implode(",\n", $pairs) . ",\n    ]";
    }

    private function literal(mixed $value, string $language): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_array($value)) {
            return $language === 'swift' ? '[]' : '[]';
        }

        $quote = $language === 'php' || $language === 'dart' ? "'" : '"';

        return $quote . str_replace($quote, '\\' . $quote, (string) $value) . $quote;
    }
}
