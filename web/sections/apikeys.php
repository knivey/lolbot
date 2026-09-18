<?php
function web_apikeys_list(?string $error = null): never
{
    $app = web_app();
    web_render('apikeys/list.twig', [
        'active' => 'apikeys', 'section' => 'API keys',
        'keys' => $app['svc']->listApiKeys(),
        'scopes' => \lolbot\entities\ApiKey::SCOPES,
        'error' => $error,
    ]);
}

function web_apikeys_create(): never
{
    $app = web_app();
    try { web_verify_csrf(); } catch (\Throwable $e) { web_apikeys_list($e->getMessage()); }
    $key = trim(is_string($_POST['key'] ?? null) ? $_POST['key'] : '');
    $labelRaw = trim(is_string($_POST['label'] ?? null) ? $_POST['label'] : '');
    $label = $labelRaw !== '' ? $labelRaw : null;
    $scopes = array_values(array_filter(is_array($_POST['scopes'] ?? null) ? $_POST['scopes'] : [], 'is_string'));
    if ($key === '') {
        web_apikeys_list('Key required');
    }
    if (!$scopes) {
        web_apikeys_list('Select at least one scope');
    }
    try { $app['svc']->addApiKey($key, $label, $scopes); } catch (\Throwable $e) { web_apikeys_list($e->getMessage()); }
    web_redirect('/apikeys');
}

function web_apikeys_delete(int $id): never
{
    $app = web_app();
    try { web_verify_csrf(); } catch (\Throwable $e) { web_apikeys_list($e->getMessage()); }
    $apiKey = $app['svc']->getApiKey($id);
    if ($apiKey !== null) { $app['svc']->deleteApiKey($apiKey); }
    web_redirect('/apikeys');
}
