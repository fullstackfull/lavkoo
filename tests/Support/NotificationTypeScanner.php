<?php

namespace Tests\Support;

use App\Enums\EmailTemplateKey;
use App\Services\Notification\NotificationTypeRegistry;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

/**
 * Reads the code for the notification types it names, one vocabulary at a time, and says where it found each. It reads text, not behaviour, so what it cannot read it does not report: a type
 * built from a variable (a key chosen at run time, a `type` taken from an event's data), and a type nobody wrote as a literal. The few types the patterns below cannot reach are
 * named in NAMED_BY_HAND with the file and the text that names them, and count only while that text is still there.
 *
 *  - push: the messages the admin edits (the list in PushNotificationService and the map in PushNotificationTrait), and the `type` literal of a push payload (an array that holds a
 *    title, an assignment to a `type` key, or a TYPE constant) in a file that calls a push sender;
 *  - e-mail: the constants of EmailTemplateKey, the Mailables sent with ->send(new ...), and the views sent with Mail::send('email-templates.*');
 *  - WhatsApp: the events of the template automation.
 */
final class NotificationTypeScanner
{
    private const PUSH_SENDER = '/sendPushNotificationTo(?:Device|Topic|MultipleDevices)|sendNotificationToHttp|send_push_notif_to_device|sendChattingPushNotificationToDevice/';

    /** The carrier of every EmailTemplate key: it is not a type of its own. */
    private const MAIL_CARRIER = 'SendMail';

    /** @var array<string, array<string, array{file: string, text: string}>> */
    private const NAMED_BY_HAND = [
        NotificationTypeRegistry::PUSH => [
            'maintenance_mode' => ['file' => 'app/Services/BusinessSettingService.php', 'text' => "type: 'maintenance_mode'"],
            'product_restock_update' => ['file' => 'app/Services/ProductService.php', 'text' => "'type' => 'product_restock_update'"],
        ],
        NotificationTypeRegistry::WHATSAPP => [
            'otp' => ['file' => 'app/Services/ZenderOtpService.php', 'text' => 'private function sendWhatsApp('],
        ],
    ];

    /** @return array<string, array<string, string>> channel => type => where the code names it, the first place found */
    public function typesNamedInCode(): array
    {
        return [
            NotificationTypeRegistry::PUSH => $this->pushTypes(),
            NotificationTypeRegistry::EMAIL => $this->emailTypes(),
            NotificationTypeRegistry::WHATSAPP => $this->whatsappTypes(),
        ];
    }

    /** @return array<string, string> */
    private function pushTypes(): array
    {
        $payloadTypes = [];
        foreach ($this->phpFiles() as $file) {
            $code = (string) file_get_contents($file);
            if (preg_match(self::PUSH_SENDER, $code) === 1) {
                $payloadTypes += $this->labelled($this->payloadTypesIn($code), 'a push payload in ' . $this->relative($file));
            }
        }

        return $this->labelled($this->messageKeysOfTheAdminList($this->read('app/Services/PushNotificationService.php')), 'the message list of PushNotificationService')
            + $this->labelled($this->messageKeysOfTheMessageMap($this->read('app/Traits/PushNotificationTrait.php')), 'the message map of PushNotificationTrait')
            + $payloadTypes
            + $this->namedByHand(NotificationTypeRegistry::PUSH);
    }

    /** @return array<string, string> */
    private function emailTypes(): array
    {
        $mailables = [];
        $views = [];
        foreach ($this->phpFiles() as $file) {
            $code = (string) file_get_contents($file);
            $mailables += $this->labelled($this->mailablesSentIn($code), 'a Mailable sent in ' . $this->relative($file));
            $views += $this->labelled($this->viewsMailedIn($code), 'a view mailed in ' . $this->relative($file));
        }

        $templateKeys = array_values(array_filter((new ReflectionClass(EmailTemplateKey::class))->getConstants(), 'is_string'));

        return $this->labelled($templateKeys, 'a constant of EmailTemplateKey') + $mailables + $views;
    }

    /** @return array<string, string> */
    private function whatsappTypes(): array
    {
        return $this->labelled($this->eventsOfTheTemplateAutomation($this->read('app/Http/Controllers/Admin/IntegrationTemplateController.php')), 'an event of IntegrationTemplateController')
            + $this->namedByHand(NotificationTypeRegistry::WHATSAPP);
    }

    /** @return list<string> */
    public function messageKeysOfTheAdminList(string $code): array
    {
        preg_match_all("/^\s*'([a-z_]+)',?\s*$/m", $this->between($code, 'function getMessageKeyData', 'function getAddData'), $keys);

        return $keys[1];
    }

    /** @return list<string> the messages a push can be sent with: the values of the map from an event key to a message key */
    public function messageKeysOfTheMessageMap(string $code): array
    {
        preg_match_all("/'[a-z_]+'\s*=>\s*'([a-z_]+)'/", $this->mapOfTheMessageKeys($code), $keys);

        return $keys[1];
    }

    /** @return list<string> the keys the map answers to: what a sender passes, so a key that is not here finds no message and sends nothing */
    public function keysAnsweredByTheMessageMap(string $code): array
    {
        preg_match_all("/'([a-z_]+)'\s*=>\s*'[a-z_]+'/", $this->mapOfTheMessageKeys($code), $keys);

        return $keys[1];
    }

    private function mapOfTheMessageKeys(string $code): string
    {
        preg_match('/\$notificationKey\s*=\s*\[(.*?)\n\s*\];/s', $code, $map);

        return $map[1] ?? '';
    }

    /**
     * The `type` a push payload carries: a literal next to a title in one array, a literal assigned to a `type` key, or a TYPE constant.
     *
     * @return list<string>
     */
    public function payloadTypesIn(string $code): array
    {
        $types = [];
        $remaining = $code;
        while (preg_match_all('/\[([^\[\]]*)\]/', $remaining, $arrays) > 0) {
            foreach ($arrays[1] as $content) {
                if (preg_match("/'title'\s*=>/", $content) === 1 && preg_match_all("/'type'\s*=>\s*'([a-z_]+)'/", $content, $found) > 0) {
                    array_push($types, ...$found[1]);
                }
            }
            $remaining = (string) preg_replace('/\[[^\[\]]*\]/', '0', $remaining);
        }
        preg_match_all("/\['type'\]\s*=\s*'([a-z_]+)'|const\s+TYPE\s*=\s*'([a-z_]+)'/", $code, $assigned);

        return array_values(array_unique([...$types, ...array_filter($assigned[1]), ...array_filter($assigned[2])]));
    }

    /** @return list<string> the classes of the Mailables sent with ->send(new ...), without the carrier of the templates */
    public function mailablesSentIn(string $code): array
    {
        preg_match_all('/->send\(\s*new\s+\\\\?(?:App\\\\Mail\\\\)?([A-Z]\w*)\s*\(/', $code, $found);

        return array_values(array_diff(array_unique($found[1]), [self::MAIL_CARRIER]));
    }

    /** @return list<string> */
    public function viewsMailedIn(string $code): array
    {
        preg_match_all("/Mail::send\(\s*'(email-templates\.[a-z-]+)'/", $code, $found);

        return array_values(array_unique($found[1]));
    }

    /** @return list<string> */
    public function eventsOfTheTemplateAutomation(string $code): array
    {
        preg_match_all("/'([a-z_]+)'\s*=>\s*'[^']*'/", $this->between($code, 'function events()', 'function recipientFields()'), $events);

        return $events[1];
    }

    /** @return list<string> the files of the application whose code, not its comments, names the text, by their path from the root, sorted */
    public function filesNaming(string $text): array
    {
        return $this->filesNamingEachOf($text)[$text];
    }

    /**
     * The same for several texts, in one pass over the code: a test that asks about more than one of them reads every file once.
     *
     * @return array<string, list<string>> the text => the files that name it, sorted
     */
    public function filesNamingEachOf(string ...$texts): array
    {
        $files = array_fill_keys($texts, []);
        foreach ($this->phpFiles() as $file) {
            $code = $this->withoutComments((string) file_get_contents($file));
            foreach ($texts as $text) {
                if (str_contains($code, $text)) {
                    $files[$text][] = $this->relative($file);
                }
            }
        }
        return array_map(static function (array $named): array {
            sort($named);

            return $named;
        }, $files);
    }

    private function withoutComments(string $code): string
    {
        $stripped = '';
        foreach (token_get_all($code) as $token) {
            if (!is_array($token)) {
                $stripped .= $token;
            } elseif (!in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $stripped .= $token[1];
            }
        }

        return $stripped;
    }

    /** @return array<string, string> */
    private function namedByHand(string $channel): array
    {
        $named = [];
        foreach (self::NAMED_BY_HAND[$channel] as $type => $place) {
            if (str_contains($this->read($place['file']), $place['text'])) {
                $named[$type] = "{$place['file']}, by hand";
            }
        }

        return $named;
    }

    /**
     * @param list<string> $types
     * @return array<string, string>
     */
    private function labelled(array $types, string $place): array
    {
        return array_fill_keys($types, $place);
    }

    private function between(string $code, string $from, string $to): string
    {
        $start = (int) strpos($code, $from);
        $end = strpos($code, $to, $start);

        return substr($code, $start, $end === false ? null : $end - $start);
    }

    /** @return list<string> */
    private function phpFiles(): array
    {
        $files = [];
        foreach ([base_path('app'), ...glob(base_path('Modules/*/app'), GLOB_ONLYDIR)] as $directory) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    private function read(string $path): string
    {
        return (string) file_get_contents(base_path($path));
    }

    private function relative(string $file): string
    {
        return str_replace(base_path() . '/', '', $file);
    }
}
