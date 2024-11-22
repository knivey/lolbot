<?php
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Form;
use Amp\Http\Client\Request;

use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Options;
use knivey\cmdr\attributes\Syntax;

#[Cmd("translate", "trans")]
#[Syntax('<input>...')]
#[Options("--langs")]
function translate_cmd($args, \Irc\Client $bot, \knivey\cmdr\Args $cmdArgs)
{
    try {
        $text = $cmdArgs['input'];
        $fromLang = "auto";
        $toLang = "en";

        $languages = $cmdArgs->getOpt("--langs");
        if ($languages) {
            $languages = validateLanguages($languages);
            if ($languages == false) {
                $bot->pm($args->chan, "invalid languages");
                return;
            }
            
            extract($languages, EXTR_OVERWRITE);
        }

        $url = "https://translate.googleapis.com/translate_a/single?client=gtx&dt=t";
        $client = HttpClientBuilder::buildDefault();
        $request = new Request($url, "POST");
        $body = new Form();
        $body->addField('sl', $fromLang);
        $body->addField('tl', $toLang);
        $body->addField('q', $text);
        $request->setBody($body);
        $response = $client->request($request);
        if ($response->getStatus() != 200) {
            $body = $response->getBody()->buffer();
            echo $body;
            throw new \Exception("gTranslate returned {$response->getStatus()}");
        }
        $respBody = $response->getBody()->buffer();
        $responseArray = json_decode($respBody, true);

        $translatedSentences = [];
        if (is_array($responseArray)) {
            foreach ($responseArray[0] as $translation) {
                $translatedSentence = urldecode($translation[0]);
                $translatedSentences[] = $translatedSentence;
            }
        }

        $translation = implode("", $translatedSentences);

        $bot->pm($args->chan, $translation);
    } catch (Exception $e) {
        echo $e;
        $bot->pm($args->chan, $e->getMessage());
    }

}

function validateLanguages($languages)
{

    $validLanguages = [
      'auto' => 'Automatic',
      'af' => 'Afrikaans',
      'sq' => 'Albanian',
      'am' => 'Amharic',
      'ar' => 'Arabic',
      'hy' => 'Armenian',
      'az' => 'Azerbaijani',
      'eu' => 'Basque',
      'be' => 'Belarusian',
      'bn' => 'Bengali',
      'bs' => 'Bosnian',
      'bg' => 'Bulgarian',
      'ca' => 'Catalan',
      'ceb' => 'Cebuano',
      'ny' => 'Chichewa',
      'zh' => 'Chinese Simplified',
      'zh-cn' => 'Chinese Simplified',
      'zh-tw' => 'Chinese Traditional',
      'co' => 'Corsican',
      'hr' => 'Croatian',
      'cs' => 'Czech',
      'da' => 'Danish',
      'nl' => 'Dutch',
      'en' => 'English',
      'eo' => 'Esperanto',
      'et' => 'Estonian',
      'tl' => 'Filipino',
      'fi' => 'Finnish',
      'fr' => 'French',
      'fy' => 'Frisian',
      'gl' => 'Galician',
      'ka' => 'Georgian',
      'de' => 'German',
      'el' => 'Greek',
      'gu' => 'Gujarati',
      'ht' => 'Haitian Creole',
      'ha' => 'Hausa',
      'haw' => 'Hawaiian',
      'he' => 'Hebrew',
      'iw' => 'Hebrew',
      'hi' => 'Hindi',
      'hmn' => 'Hmong',
      'hu' => 'Hungarian',
      'is' => 'Icelandic',
      'ig' => 'Igbo',
      'id' => 'Indonesian',
      'ga' => 'Irish',
      'it' => 'Italian',
      'ja' => 'Japanese',
      'jw' => 'Javanese',
      'kn' => 'Kannada',
      'kk' => 'Kazakh',
      'km' => 'Khmer',
      'rw' => 'Kinyarwanda',
      'ko' => 'Korean',
      'ku' => 'Kurdish (Kurmanji)',
      'ky' => 'Kyrgyz',
      'lo' => 'Lao',
      'la' => 'Latin',
      'lv' => 'Latvian',
      'lt' => 'Lithuanian',
      'lb' => 'Luxembourgish',
      'mk' => 'Macedonian',
      'mg' => 'Malagasy',
      'ms' => 'Malay',
      'ml' => 'Malayalam',
      'mt' => 'Maltese',
      'mi' => 'Maori',
      'mr' => 'Marathi',
      'mn' => 'Mongolian',
      'my' => 'Myanmar (Burmese)',
      'ne' => 'Nepali',
      'no' => 'Norwegian',
      'or' => 'Odia (Oriya)',
      'ps' => 'Pashto',
      'fa' => 'Persian',
      'pl' => 'Polish',
      'pt' => 'Portuguese',
      'pa' => 'Punjabi',
      'ro' => 'Romanian',
      'ru' => 'Russian',
      'sm' => 'Samoan',
      'gd' => 'Scots Gaelic',
      'sr' => 'Serbian',
      'st' => 'Sesotho',
      'sn' => 'Shona',
      'sd' => 'Sindhi',
      'si' => 'Sinhala',
      'sk' => 'Slovak',
      'sl' => 'Slovenian',
      'so' => 'Somali',
      'es' => 'Spanish',
      'su' => 'Sundanese',
      'sw' => 'Swahili',
      'sv' => 'Swedish',
      'tg' => 'Tajik',
      'ta' => 'Tamil',
      'tt' => 'Tatar',
      'te' => 'Telugu',
      'th' => 'Thai',
      'tr' => 'Turkish',
      'tk' => 'Turkmen',
      'uk' => 'Ukrainian',
      'ur' => 'Urdu',
      'ug' => 'Uyghur',
      'uz' => 'Uzbek',
      'vi' => 'Vietnamese',
      'cy' => 'Welsh',
      'xh' => 'Xhosa',
      'yi' => 'Yiddish',
      'yo' => 'Yoruba',
      'zu' => 'Zulu',
    ];

    $languages = explode("-", $languages);

    if (count($languages) != 2) {
        return false;
    }

    if (array_key_exists($languages[0], $validLanguages) && array_key_exists($languages[1], $validLanguages)) {
        return ['fromLang' => $languages[0], 'toLang' => $languages[1]];
    } else {
        return false;
    }
}
