<?php
use scripts\linktitles\entities\linktitles_setting;

function web_linktitles(?string $error = null): never
{
    $app = web_app();
    $networks = $app['svc']->listNetworks();
    // One network-scoped row each (channel = null) for the common case.
    $rows = $app['em']->getRepository(linktitles_setting::class)->findBy(['channel' => null]);
    $byNet = [];
    foreach ($rows as $r) {
        if ($r->network !== null) { $byNet[$r->network->id] = $r; }
    }
    web_render('linktitles.twig', ['active' => 'linktitles', 'section' => 'Linktitles', 'networks' => $networks, 'byNet' => $byNet, 'error' => $error]);
}

function web_linktitles_save(int $netId): never
{
    $app = web_app();
    try { web_verify_csrf(); } catch (\Throwable $e) { web_linktitles($e->getMessage()); }
    $net = $app['svc']->getNetwork($netId);
    if ($net === null) { http_response_code(404); echo "No such network"; exit; }
    // Checkbox: absent means false.
    $app['svc']->setLinktitlesSetting($net, null, 'enabled', array_key_exists('enabled', $_POST));
    foreach (['url_log_chan','ai_vision_model','ai_vision_prompt','ai_vision_reasoning_effort'] as $k) {
        $raw = trim(is_string($_POST[$k] ?? null) ? $_POST[$k] : '');
        if ($raw === '') { $app['svc']->resetLinktitlesSetting($net, null, $k); continue; }
        $app['svc']->setLinktitlesSetting($net, null, $k, $raw);
    }
    web_redirect('/linktitles');
}
