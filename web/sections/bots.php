<?php
// Bots: list / edit / add / delete + channels (live join/part) + live actions.

function web_bots_list(): never
{
    $app = web_app();
    web_render('bots/list.twig', [
        'active' => 'bots', 'section' => 'Bots',
        'bots' => $app['svc']->listBots(),
    ]);
}

function web_bots_new(): never
{
    $app = web_app();
    web_render('bots/edit.twig', [
        'active' => 'bots', 'section' => 'New bot',
        'bot' => null, 'networks' => $app['svc']->listNetworks(),
        'error' => null,
    ]);
}

function web_bots_create(): never
{
    $app = web_app();
    try { web_verify_csrf(); } catch (\Throwable $e) { web_bots_new_error($e->getMessage()); }
    $name = trim(is_string($_POST['name'] ?? null) ? $_POST['name'] : '');
    $netId = (int)(is_string($_POST['network_id'] ?? null) ? $_POST['network_id'] : '0');
    $net = $app['svc']->getNetwork($netId);
    if ($net === null || $name === '') {
        web_bots_new_error('Network and name are required');
    }
    try {
        $app['svc']->createBot($net, $name);
    } catch (\Throwable $e) {
        web_bots_new_error($e->getMessage());
    }
    web_redirect('/bots');
}

function web_bots_new_error(string $error): never
{
    $app = web_app();
    web_render('bots/edit.twig', [
        'active' => 'bots', 'section' => 'New bot',
        'bot' => null, 'networks' => $app['svc']->listNetworks(), 'error' => $error,
    ]);
}

function web_bots_edit(int $id, ?string $error = null): never
{
    $app = web_app();
    $bot = $app['svc']->getBot($id);
    if ($bot === null) { http_response_code(404); echo "No such bot"; exit; }
    web_render('bots/edit.twig', [
        'active' => 'bots', 'section' => 'Edit ' . $bot->name,
        'bot' => $bot, 'networks' => $app['svc']->listNetworks(), 'error' => $error,
    ]);
}

function web_bots_update(int $id): never
{
    $app = web_app();
    try { web_verify_csrf(); } catch (\Throwable $e) { web_bots_edit($id, $e->getMessage()); }
    $bot = $app['svc']->getBot($id);
    if ($bot === null) { http_response_code(404); echo "No such bot"; exit; }
    $bot->name        = trim(is_string($_POST['name'] ?? null) ? $_POST['name'] : $bot->name);
    if ($bot->name === '') { web_bots_edit($id, 'Name required'); }
    $trigRaw          = is_string($_POST['trigger'] ?? null) ? $_POST['trigger'] : '';
    $bot->trigger     = $trigRaw !== '' ? trim($trigRaw) : null;
    $trigReRaw        = is_string($_POST['trigger_re'] ?? null) ? $_POST['trigger_re'] : '';
    $bot->trigger_re  = $trigReRaw !== '' ? trim($trigReRaw) : null;
    $bot->onConnect   = is_string($_POST['onConnect'] ?? null) ? $_POST['onConnect'] : '';
    $saslUserRaw      = is_string($_POST['sasl_user'] ?? null) ? $_POST['sasl_user'] : '';
    $bot->sasl_user   = $saslUserRaw !== '' ? trim($saslUserRaw) : null;
    $saslPassRaw      = is_string($_POST['sasl_pass'] ?? null) ? $_POST['sasl_pass'] : '';
    $bot->sasl_pass   = $saslPassRaw !== '' ? trim($saslPassRaw) : null;
    $bindIpRaw        = is_string($_POST['bindIp'] ?? null) ? trim($_POST['bindIp']) : '0';
    $bot->bindIp      = $bindIpRaw !== '' ? $bindIpRaw : '0';
    try {
        $app['svc']->update($bot, 'bot'); // pushes apply → nick/trigger live; sasl/bindIp/onConnect need respawn
    } catch (\Throwable $e) {
        web_bots_edit($id, $e->getMessage());
    }
    web_redirect('/bots/' . $id);
}

function web_bots_delete(int $id): never
{
    $app = web_app();
    try { web_verify_csrf(); } catch (\Throwable $e) { web_bots_edit($id, $e->getMessage()); }
    $bot = $app['svc']->getBot($id);
    if ($bot !== null) { $app['svc']->deleteBot($bot); } // pushes apply → drop
    web_redirect('/bots');
}

// Channels (live join/part via ConfigService → apply).
function web_bots_add_channel(int $botId): never
{
    $app = web_app();
    try { web_verify_csrf(); } catch (\Throwable $e) { web_error_fragment($e->getMessage()); }
    $bot = $app['svc']->getBot($botId);
    $chan = trim(is_string($_POST['channel'] ?? null) ? $_POST['channel'] : '');
    if ($bot === null) { web_error_fragment('No such bot', 404); }
    if ($chan === '') { web_error_fragment('Channel required'); }
    try { $app['svc']->addChannel($bot, $chan); } catch (\Throwable $e) { web_error_fragment($e->getMessage()); }
    web_bots_channels_fragment($botId);
}

function web_bots_del_channel(int $botId, int $chanId): never
{
    $app = web_app();
    try { web_verify_csrf(); } catch (\Throwable $e) { web_error_fragment($e->getMessage()); }
    $chan = $app['em']->find(\lolbot\entities\Channel::class, $chanId);
    if ($chan === null || $chan->bot->id !== $botId) { web_error_fragment('No such channel', 404); }
    try { $app['svc']->deleteChannel($chan); } catch (\Throwable $e) { web_error_fragment($e->getMessage()); }
    web_bots_channels_fragment($botId);
}

function web_bots_channels_fragment(int $botId): never
{
    $app = web_app();
    $bot = $app['svc']->getBot($botId);
    web_render_fragment('bots/_channels.twig', ['bot' => $bot, 'csrf' => web_twig_csrf()]);
}

// Live actions: reconnect / jump / respawn → bot's /_control/* endpoints.
function web_bots_action(int $botId, string $action): never
{
    $app = web_app();
    try { web_verify_csrf(); } catch (\Throwable $e) { web_error_fragment($e->getMessage()); }
    if (in_array($action, ['reconnect', 'jump', 'respawn'], true)) {
        web_bot_http($app, 'POST', '/_control/' . $action . '/' . $botId);
    }
    web_render_fragment('bots/_actions.twig', ['botId' => $botId, 'csrf' => web_twig_csrf(), 'queued' => $action]);
}
