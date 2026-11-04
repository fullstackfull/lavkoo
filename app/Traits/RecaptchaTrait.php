<?php

namespace App\Traits;

use Gregwar\Captcha\PhraseBuilder;
use Gregwar\Captcha\CaptchaBuilder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;

trait RecaptchaTrait
{
    protected function isGoogleRecaptchaValid(string $reCaptchaValue): bool
    {
        $secret = getWebConfig(name: 'recaptcha')['secret_key'];
        $response = Http::asForm()->post('https://www.google.com/recaptcha/api/siteverify', [
            'secret' => $secret,
            'response' => $reCaptchaValue,
            'remoteip' => request()->ip(),
        ]);

        return $response->json('success') === true && $response->json('score') > 0.5;
    }

    public function generateDefaultReCaptcha(int $captureLength): CaptchaBuilder
    {
        $phrase = new PhraseBuilder;
        $code = $phrase->build($captureLength);
        $builder = new CaptchaBuilder($code, $phrase);
        $builder->setBackgroundColor(220, 210, 230);
        $builder->setMaxAngle(25);
        $builder->setMaxBehindLines(0);
        $builder->setMaxFrontLines(0);
        $builder->build($width = 100, $height = 40, $font = null);
        return $builder;
    }


    public function saveRecaptchaValueInSession(string $sessionKey, string $sessionValue):void{
        if (Session::has($sessionKey)) {
            Session::forget($sessionKey);
        }
        Session::put($sessionKey, $sessionValue);
    }
}
