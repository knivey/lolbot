<?php
function web_services(?string $error = null): never
{
    $app = web_app();
    $ai = $app['em']->getRepository(\lolbot\entities\AiServiceConfig::class)->findOneBy([]) ?? new \lolbot\entities\AiServiceConfig();
    $paste = $app['em']->getRepository(\lolbot\entities\PasteServiceConfig::class)->findOneBy([]) ?? new \lolbot\entities\PasteServiceConfig();
    web_render('services.twig', ['active' => 'services', 'section' => 'Services', 'ai' => $ai, 'paste' => $paste, 'error' => $error]);
}

function web_services_save(string $type): never
{
    $app = web_app();
    try { web_verify_csrf(); } catch (\Throwable $e) { web_services($e->getMessage()); }
    $keys = $type === 'ai'
        ? ['apiKey', 'baseUrl', 'maxDim', 'jpgQuality', 'timeout']
        : ['host', 'key'];
    $intKeys = ['maxDim', 'jpgQuality', 'timeout'];
    foreach ($keys as $k) {
        $raw = is_string($_POST[$k] ?? null) ? $_POST[$k] : '';
        $val = trim($raw);
        if ($val === '') continue;
        $app['svc']->setServiceConfigValue($type, $k, in_array($k, $intKeys, true) ? (int)$val : $val);
    }
    web_redirect('/services');
}
