<?php

namespace Tests\Feature\Security;

use Tests\TestCase;

/**
 * A one-time code, and the text of the message that carries it, must never reach the application log: whoever can read the log could
 * then reset the password of the account. This pins the two places that used to write them (the vendor password reset and the SMS and
 * WhatsApp gateway service) so that a log call cannot start to record a code again.
 */
class OtpLoggingTest extends TestCase
{
    private const VENDOR_RESET = 'app/Http/Controllers/Vendor/Auth/ForgotPasswordController.php';

    private const GATEWAY = 'app/Services/ZenderOtpService.php';

    public function test_the_vendor_password_reset_logs_no_code(): void
    {
        foreach ($this->logCalls(self::VENDOR_RESET) as $call) {
            $this->assertDoesNotMatchRegularExpression(
                '/[\'"][a-z_]*(?:token|otp|code)[a-z_]*[\'"]\s*=>/i',
                $call,
                'a log call in the vendor password reset records a code or token',
            );
        }
    }

    public function test_the_gateway_logs_neither_the_message_nor_a_code(): void
    {
        foreach ($this->logCalls(self::GATEWAY) as $call) {
            $this->assertDoesNotMatchRegularExpression(
                '/[\'"](?:message|token|otp|code)[\'"]\s*=>\s*\$(?:message|token|otp|code)\b/i',
                $call,
                'a log call in the gateway service records the message that carries the code',
            );
        }
    }

    public function test_the_gateway_hides_the_message_in_the_whatsapp_debug_payload(): void
    {
        $source = (string) file_get_contents(base_path(self::GATEWAY));

        $this->assertStringContainsString("\$debugPayload['message'] = '***HIDDEN***';", $source);
    }

    /** @return list<string> the source text of every Log:: call of the file, from the call to its closing brackets */
    private function logCalls(string $relativePath): array
    {
        $source = (string) file_get_contents(base_path($relativePath));
        $this->assertNotSame('', $source, "{$relativePath} was not found: the scan would pass on nothing");

        preg_match_all('/Log::(?:info|debug|notice|warning|error|critical)\s*\(.*?\);/s', $source, $matches);

        return $matches[0];
    }
}
