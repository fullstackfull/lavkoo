<?php

namespace App\Services;

use Exception;
use stdClass;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use Illuminate\Support\Facades\File;

class LanguageService
{

    public function getAddData(object $request, object $language): array
    {
        $languageArray = [];
        $codes = [];
        foreach (json_decode($language['value'], true) as $key => $data) {
            if ($data['code'] != $request['code']) {
                if (!array_key_exists('default', $data)) {
                    $default = array('default' => $data['code'] == 'en');
                    $data = array_merge($data, $default);
                }
                $languageArray[] = $data;
                $codes[] = $data['code'];
            }
        }
        $codes[] = $request['code'];

        if (!file_exists(base_path('resources/lang/' . $request['code']))) {
            mkdir(base_path('resources/lang/' . $request['code']), 0777, true);
        }

        $messagesNewFile = fopen(base_path('resources/lang/' . $request['code'] . '/' . 'new-messages.php'), "w") or die("Unable to open file!");
        $messagesFile = fopen(base_path('resources/lang/' . $request['code'] . '/' . 'messages.php'), "w") or die("Unable to open file!");
        $messagesFromDefaultLanguage = file_get_contents(base_path('resources/lang/en/messages.php'));

        fwrite($messagesNewFile, $messagesFromDefaultLanguage);
        $messagesFileContents = "<?php\n\nreturn [];\n";
        file_put_contents(base_path('resources/lang/' . $request['code'] . '/messages.php'), $messagesFileContents);

        $translatedMessagesArray = include(base_path('resources/lang/en/messages.php'));
        $newMessagesArray = include(base_path('resources/lang/en/new-messages.php'));
        $allMessages = array_merge($translatedMessagesArray, $newMessagesArray);
        $dataFiltered = [];
        foreach ($allMessages as $key => $data) {
            $dataFiltered[removeSpecialCharacters(text: $key)] = $data;
        }
        $string = "<?php return " . var_export($dataFiltered, true) . ";";
        file_put_contents(base_path('resources/lang/' . $request['code'] . '/new-messages.php'), $string);


        try {
            // For advance search
            $eng = public_path('json/admin/lang/en.json');
            $filename = public_path('json/admin/lang/' . $request['code'] . '.json');
            if (!file_exists($filename)) {
                if (!file_exists(dirname($filename))) {
                    File::makeDirectory(public_path('json/admin/lang'), 0777, true, true);
                }
                if (file_exists($eng)) {
                    $content = file_get_contents($eng);
                    file_put_contents($filename, $content);
                } else {
                    file_put_contents($filename, json_encode(new stdClass(), JSON_PRETTY_PRINT));
                }
            }
        } catch (Exception $e) {
        }

        $languageValue = json_decode($language['value'], true);
        $languageCount = count($languageValue);
        $id = $languageValue[$languageCount - 1]['id'] + 1;

        $languageArray[] = [
            'id' => $id,
            'name' => $request['name'],
            'code' => $request['code'],
            'direction' => $request['direction'],
            'status' => 0,
            'default' => false,
        ];
        session()->put('language', $languageArray);
        return [
            'languages' => $languageArray,
            'codes' => $codes,
        ];
    }

    public function getStatusData(object $request, object $language): array
    {
        $languageArray = [];
        foreach (json_decode($language['value'], true) as $data) {
            if ($data['code'] == $request['code']) {
                $lang = [
                    'id' => $data['id'],
                    'name' => $data['name'],
                    'direction' => $data['direction'] ?? 'ltr',
                    'code' => $data['code'],
                    'status' => $data['status'] == 1 ? 0 : 1,
                    'default' => (array_key_exists('default', $data) ? $data['default'] : $data['code'] == 'en'),
                ];
            } else {
                $lang = [
                    'id' => $data['id'],
                    'name' => $data['name'],
                    'direction' => $data['direction'] ?? 'ltr',
                    'code' => $data['code'],
                    'status' => $data['status'],
                    'default' => (array_key_exists('default', $data) ? $data['default'] : $data['code'] == 'en'),
                ];
            }
            $languageArray[] = $lang;
        }

        return $languageArray;
    }

    public function getDefaultData(object $request, object $language): array
    {
        $languageArray = [];
        foreach (json_decode($language['value'], true) as $data) {
            if ($data['code'] == $request['code']) {
                $lang = [
                    'id' => $data['id'],
                    'name' => $data['name'],
                    'direction' => $data['direction'] ?? 'ltr',
                    'code' => $data['code'],
                    'status' => 1,
                    'default' => true,
                ];
            } else {
                $lang = [
                    'id' => $data['id'],
                    'name' => $data['name'],
                    'direction' => $data['direction'] ?? 'ltr',
                    'code' => $data['code'],
                    'status' => $data['status'],
                    'default' => false,
                ];
            }
            $languageArray[] = $lang;
        }
        return $languageArray;
    }


    public function getUpdateData(object $request, object $language): array
    {
        $languageArray = [];
        foreach (json_decode($language['value'], true) as $data) {
            if ($data['code'] == $request['code']) {
                $lang = [
                    'id' => $data['id'],
                    'name' => $request['name'],
                    'direction' => $request['direction'] ?? 'ltr',
                    'code' => $data['code'],
                    'status' => 0,
                    'default' => (array_key_exists('default', $data) ? $data['default'] : (($data['code'] == 'en') ? true : false)),
                ];
            } else {
                $lang = [
                    'id' => $data['id'],
                    'name' => $data['name'],
                    'direction' => $data['direction'] ?? 'ltr',
                    'code' => $data['code'],
                    'status' => $data['status'],
                    'default' => (array_key_exists('default', $data) ? $data['default'] : (($data['code'] == 'en') ? true : false)),
                ];
            }
            $languageArray[] = $lang;
        }
        session()->put('language', $languageArray);
        return $languageArray;
    }

    public function getLangDelete(object $language, string $code): array
    {
        $del_default = false;
        foreach (json_decode($language['value'], true) as $data) {
            if ($data['code'] == $code && array_key_exists('default', $data) && $data['default']) {
                $del_default = true;
            }
        }

        $languageArray = [];
        foreach (json_decode($language['value'], true) as $data) {
            if ($data['code'] != $code) {
                $lang_data = [
                    'id' => $data['id'],
                    'name' => $data['name'],
                    'direction' => $data['direction'] ?? 'ltr',
                    'code' => $data['code'],
                    'status' => ($del_default && $data['code'] == 'en') ? 1 : $data['status'],
                    'default' => ($del_default && $data['code'] == 'en') ? true : (array_key_exists('default', $data) ? $data['default'] : $data['code'] == 'en'),
                ];
                $languageArray[] = $lang_data;
            }
        }

        $dir = base_path('resources/lang/' . $code);
        if (File::isDirectory($dir)) {
            $it = new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS);
            $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                if ($file->isDir()) {
                    rmdir($file->getRealPath());
                } else {
                    unlink($file->getRealPath());
                }
            }
            rmdir($dir);
        }

        return $languageArray;

    }

    public function getTranslateList(string $language): array
    {
        $data = [];
        $path = base_path('resources/lang/' . $language . '/messages.php');
        if (File::exists($path)) {
            $newMessagesData = include(base_path('resources/lang/' . $language . '/new-messages.php'));
            $oldMessagesData = include(base_path('resources/lang/' . $language . '/messages.php'));
            ksort($newMessagesData);
            ksort($oldMessagesData);

            $index = 1;
            foreach ($newMessagesData as $key => $value) {
                $data[] = [
                    'index' => $index++,
                    'key' => $key,
                    'value' => $value,
                    'encode' => !empty($key) ? base64_encode($key) : '',
                ];
            }

            foreach ($oldMessagesData as $key => $value) {
                $data[] = [
                    'index' => $index++,
                    'key' => $key,
                    'value' => $value,
                    'encode' => !empty($key) ? base64_encode($key) : '',
                ];
            }
        }
        return $data;
    }

    private function normalizeKeyForTranslation(string $key): string
    {
        $text = preg_replace('/[\s_]+/', ' ', $key);
        return trim($text);
    }

    public function getAllMessagesTranslateProcess(string $language, int $count = 999999999): array
    {
        $getNewMessagesArray = include(base_path('resources/lang/' . $language . '/new-messages.php'));
        $translatedMessagesArray = include(base_path('resources/lang/' . $language . '/messages.php'));

        $newMessagesArray = [];
        foreach ($getNewMessagesArray as $key => $value) {
            $cleanKey = self::normalizeKeyForTranslation($key);
            $newMessagesArray[str_replace(' ', '_', $cleanKey)] = $value;
        }
        ksort($newMessagesArray);

        $remainingMessagesFileContents = "<?php\n\nreturn [\n";
        foreach ($newMessagesArray as $newMsgKey => $newMsgValue) {
            $remainingMessagesFileContents .= "\t\"" . $newMsgKey . "\" => \"" . $newMsgValue . "\",\n";
        }
        $remainingMessagesFileContents .= "];\n";
        file_put_contents(base_path('resources/lang/' . $language . '/new-messages.php'), $remainingMessagesFileContents);

        $getNewMessagesArray = include(base_path('resources/lang/' . $language . '/new-messages.php'));

        $response = [
            'status' => 0,
            'message' => translate("Cannot_translate_now"),
            'due_message' => count($newMessagesArray),
        ];

        $translateCountSuccess = 0;
        $translateCount = 0;
        if ($newMessagesArray) {
            if (count($newMessagesArray) <= 0) {
                $response = ['status' => 1, 'message' => translate("All_Messages_are_translated"), 'translateCountSuccess' => $translateCountSuccess];
            }

            $messagesGroup = collect($newMessagesArray)->take($count)->toArray();
            $messagesGroupKeys = array_keys($messagesGroup);

            $cleanStrings = [];
            foreach ($messagesGroupKeys as $key) {
                $cleanStrings[] = str_replace('_', ' ', $key);
            }

            $translatedLines = autoTranslatorBatchedMode(implode("\n", $cleanStrings), 'en', getLanguageCode($language));
            $translatedArray = array_combine($cleanStrings, explode("\n", $translatedLines));

            $translatedVersion = [];
            foreach ($translatedArray as $key => $value) {
                $translatedVersion[base64_encode(str_replace(' ', '_', $key))] = $value;
            }

            $cleanKeys = [];
            foreach ($newMessagesArray as $key => $value) {
                $cleanKey = str_replace(' ', '_', $key);
                if (isset($translatedVersion[base64_encode($cleanKey)])) {
                    $cleanKeys[] = base64_encode($cleanKey);
                    $translated = $translatedVersion[base64_encode($cleanKey)];
                    $this->updateAdvancedSearchKeyWords($language, $key, removeSpecialCharacters($translated));
                    $translatedMessagesArray[$cleanKey] = removeSpecialCharacters($translated);
                    $translateCountSuccess++;
                    $translateCount++;
                }
            }

            self::getAddTranslateNewKey(
                base_path("resources/lang/{$language}/new-messages.php"),
                base_path("resources/lang/{$language}/new-messages.php"),
                $cleanKeys
            );

            $messagesFileContents = "<?php\n\nreturn [\n";
            foreach ($translatedMessagesArray as $k => $tmaValue) {
                $messagesFileContents .= "\t\"" . $k . "\" => \"" . $tmaValue . "\",\n";
            }
            $messagesFileContents .= "];\n";
            file_put_contents(base_path('resources/lang/' . $language . '/messages.php'), $messagesFileContents);

            $response = [
                'status' => 1,
                'message' => translate("Translate_Successful"),
                'due_message' => count(include(base_path('resources/lang/' . $language . '/new-messages.php'))),
                'translateCountSuccess' => $translateCountSuccess
            ];
        } else {
            $response = [
                'status' => 1,
                'message' => translate("All_Messages_are_translated"),
                'due_message' => count(include(base_path('resources/lang/' . $language . '/new-messages.php'))),
                'translateCountSuccess' => $translateCountSuccess
            ];
        }

        return $response;
    }

    function getAddTranslateNewKey($sourcePath, $targetPath, $cleanKeys): void
    {
        $getNewMessagesArray = include($sourcePath);
        ksort($getNewMessagesArray);
        $remainingMessagesFileContents = "<?php\n\nreturn [\n";
        foreach ($getNewMessagesArray as $newMsgKey => $newMsgValue) {
            if (!in_array(base64_encode($newMsgKey), $cleanKeys)) {
                $remainingMessagesFileContents .= "\t\"" . $newMsgKey . "\" => \"" . $newMsgValue . "\",\n";
            }
        }
        $remainingMessagesFileContents .= "];\n";
        file_put_contents($targetPath, $remainingMessagesFileContents);
    }

    public function updateAdvancedSearchKeyWords($lang, $key, $result): void
    {
        try {
            if ($lang !== 'en') {
                $normalizedKey = strtolower(preg_replace('/\s+/', ' ', str_replace(['_', '-', '.', ' '], ' ', $key)));

                $eng = public_path('json/admin/lang/en.json');
                $filename = public_path('json/admin/lang/' . $lang . '.json');


                if (!file_exists($filename)) {
                    if (!file_exists(dirname($filename))) {
                        File::makeDirectory(dirname($filename), 0777, true, true);
                    }

                    if (file_exists($eng)) {
                        file_put_contents($filename, file_get_contents($eng));
                    } else {
                        file_put_contents($filename, json_encode([], JSON_PRETTY_PRINT));
                    }
                }

                $content = file_get_contents($filename);

                $content = preg_replace_callback(
                    '/\{\s*"key"\s*:\s*"[^"]*",\s*"page_title"\s*:\s*"([^"]+)",\s*"page_title_value"\s*:\s*"([^"]*)",\s*"keywords"\s*:\s*\{[^}]*\}\s*\}/',
                    function ($matches) use ($normalizedKey, $result) {
                        $pageTitle = $matches[1];
                        $pageTitleValue = $matches[2];

                        $normalizedPageTitle = strtolower(preg_replace('/\s+/', ' ', str_replace(['_', '-', '.', ' '], ' ', $pageTitle)));

                        if ($normalizedPageTitle === $normalizedKey) {
                            return str_replace(
                                '"page_title_value": "' . $pageTitleValue . '"',
                                '"page_title_value": "' . $result . '"',
                                $matches[0]
                            );
                        }

                        return $matches[0];
                    },
                    $content
                );

                $content = preg_replace_callback(
                    '/"([^"]+)"\s*:\s*"([^"]*)"/',
                    function ($matches) use ($normalizedKey, $result) {
                        $field = $matches[1];
                        $value = $matches[2];

                        $normalizedValue = strtolower(preg_replace('/\s+/', ' ', str_replace(['_', '-', '.', ' '], ' ', $value)));

                        if ($normalizedValue === $normalizedKey && $field !== 'page_title' && $field !== 'page_title_value') {
                            return '"' . $field . '": "' . $result . '"';
                        }

                        return $matches[0];
                    },
                    $content
                );
                $json = json_decode($content, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    file_put_contents($filename, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                } else {
                    throw new Exception("Failed to update JSON. Malformed after replacement.");
                }
            }
        } catch (Exception $e) {
        }
    }
}
