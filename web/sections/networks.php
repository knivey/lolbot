<?php

function web_networks_list(): never
{
    web_render('networks/list.twig', ['active' => 'networks', 'section' => 'Networks', 'networks' => web_app()['svc']->listNetworks()]);
}

function web_networks_new(?string $error = null): never
{
    web_render('networks/edit.twig', ['active' => 'networks', 'section' => 'New network', 'net' => null, 'error' => $error]);
}

function web_networks_create(): never
{
    $app = web_app();
    try { web_verify_csrf(); } catch (\Throwable $e) { web_networks_new($e->getMessage()); }
    $name = trim(is_string($_POST['name'] ?? null) ? $_POST['name'] : '');
    if ($name === '') { web_networks_new('Name required'); }
    try { $app['svc']->createNetwork($name); } catch (\Throwable $e) { web_networks_new($e->getMessage()); }
    web_redirect('/networks');
}

function web_networks_edit(int $id, ?string $error = null): never
{
    $net = web_app()['svc']->getNetwork($id);
    if ($net === null) { http_response_code(404); echo "No such network"; exit; }
    web_render('networks/edit.twig', ['active' => 'networks', 'section' => 'Edit ' . $net->name, 'net' => $net, 'error' => $error]);
}

function web_networks_update(int $id): never
{
    $app = web_app();
    try { web_verify_csrf(); } catch (\Throwable $e) { web_networks_edit($id, $e->getMessage()); }
    $net = $app['svc']->getNetwork($id);
    if ($net === null) { http_response_code(404); echo "No such network"; exit; }
    $net->name = trim(is_string($_POST['name'] ?? null) ? $_POST['name'] : $net->name);
    if ($net->name === '') { web_networks_edit($id, 'Name required'); }
    try { $app['svc']->update($net, 'network'); } catch (\Throwable $e) { web_networks_edit($id, $e->getMessage()); }
    web_redirect('/networks/' . $id);
}

function web_networks_delete(int $id): never
{
    $app = web_app();
    try { web_verify_csrf(); } catch (\Throwable $e) { web_networks_edit($id, $e->getMessage()); }
    $net = $app['svc']->getNetwork($id);
    if ($net !== null) { $app['svc']->deleteNetwork($net); }
    web_redirect('/networks');
}

// Servers nested under a network.
function web_networks_add_server(int $netId): never
{
    $app = web_app();
    try { web_verify_csrf(); } catch (\Throwable $e) { web_error_fragment($e->getMessage()); }
    $net = $app['svc']->getNetwork($netId);
    if ($net === null) { web_error_fragment('No such network', 404); }
    $addr = trim(is_string($_POST['address'] ?? null) ? $_POST['address'] : '');
    $port = (int)(is_string($_POST['port'] ?? null) && $_POST['port'] !== '' ? $_POST['port'] : '6667');
    $ssl = isset($_POST['ssl']);
    $throttle = isset($_POST['throttle']);
    $passRaw = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $password = $passRaw !== '' ? $passRaw : null;
    if ($addr !== '') {
        try {
            $app['svc']->addServer($net, $addr, $port, $ssl, $throttle, $password);
        } catch (\Throwable $e) { web_error_fragment($e->getMessage()); }
    }
    web_servers_fragment($netId);
}

function web_networks_del_server(int $netId, int $srvId): never
{
    $app = web_app();
    try { web_verify_csrf(); } catch (\Throwable $e) { web_error_fragment($e->getMessage()); }
    $srv = $app['svc']->getServer($srvId);
    if ($srv === null || $srv->network->id !== $netId) { web_error_fragment('No such server', 404); }
    try { $app['svc']->deleteServer($srv); } catch (\Throwable $e) { web_error_fragment($e->getMessage()); }
    web_servers_fragment($netId);
}

function web_servers_fragment(int $netId): never
{
    $net = web_app()['svc']->getNetwork($netId);
    web_render_fragment('networks/_servers.twig', ['net' => $net, 'csrf' => web_twig_csrf()]);
}

function web_networks_edit_server(int $netId, int $srvId, ?string $error = null): never
{
    $app = web_app();
    $srv = $app['svc']->getServer($srvId);
    if ($srv === null || $srv->network->id !== $netId) { http_response_code(404); echo "No such server"; exit; }
    web_render('networks/edit_server.twig', [
        'active' => 'networks', 'section' => 'Edit server ' . $srv->address,
        'net' => $srv->network, 'srv' => $srv, 'error' => $error,
    ]);
}

function web_networks_update_server(int $netId, int $srvId): never
{
    $app = web_app();
    try { web_verify_csrf(); } catch (\Throwable $e) { web_networks_edit_server($netId, $srvId, $e->getMessage()); }
    $srv = $app['svc']->getServer($srvId);
    if ($srv === null || $srv->network->id !== $netId) { http_response_code(404); echo "No such server"; exit; }
    $srv->address = trim(is_string($_POST['address'] ?? null) ? $_POST['address'] : $srv->address);
    if ($srv->address === '') { web_networks_edit_server($netId, $srvId, 'Address required'); }
    $portRaw = trim(is_string($_POST['port'] ?? null) ? $_POST['port'] : '');
    $srv->port = $portRaw !== '' ? (int)$portRaw : $srv->port;
    $srv->ssl = isset($_POST['ssl']);
    $srv->throttle = isset($_POST['throttle']);
    $passRaw = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $srv->password = $passRaw !== '' ? $passRaw : null;
    try { $app['svc']->update($srv, 'server'); } catch (\Throwable $e) { web_networks_edit_server($netId, $srvId, $e->getMessage()); }
    web_redirect('/networks/' . $netId);
}
