<?php
namespace Tests\Config;

use lolbot\config\ConfigService;

require_once __DIR__ . '/../../vendor/autoload.php';

/** Template + section surface test: the CRUD itself is covered by ConfigServiceApiKeyTest. */
class WebApiKeysTest extends ConfigTestCase
{
    private function bootWeb(): void
    {
        $GLOBALS['config'] = ['control_key' => 'sekret'];
        $GLOBALS['entityManager'] = $this->em; // web_app() builds ConfigService from the global EM.
        @session_start();
        $_SESSION = [];
        require_once __DIR__ . '/../../web/app.php';
        require_once __DIR__ . '/../../web/auth.php';
        require_once __DIR__ . '/../../web/sections/apikeys.php';
    }

    public function test_list_template_renders_keys_and_scopes(): void
    {
        $this->bootWeb();
        $svc = new ConfigService($this->em);
        $svc->addApiKey('sitekey', 'image upload site', ['aidesc']);

        $app = web_app();
        $html = $app['twig']->render('apikeys/list.twig', [
            'active' => 'apikeys', 'section' => 'API keys', 'authed' => true,
            'keys' => $svc->listApiKeys(),
            'scopes' => \lolbot\entities\ApiKey::SCOPES,
            'error' => null,
        ]);
        $this->assertStringContainsString('sitekey', $html);
        $this->assertStringContainsString('image upload site', $html);
        $this->assertStringContainsString('aidesc', $html);
        $this->assertStringContainsString('/apikeys', $html);
    }
}
