<?php
function web_ignores_list(?string $error = null): never
{
    $app = web_app();
    web_render('ignores/list.twig', [
        'active' => 'ignores', 'section' => 'Ignores',
        'ignores' => $app['svc']->listIgnores(),
        'networks' => $app['svc']->listNetworks(),
        'error' => $error,
    ]);
}

function web_ignores_create(): never
{
    $app = web_app();
    try { web_verify_csrf(); } catch (\Throwable $e) { web_ignores_list($e->getMessage()); }
    $host = trim(is_string($_POST['hostmask'] ?? null) ? $_POST['hostmask'] : '');
    $reasonRaw = trim(is_string($_POST['reason'] ?? null) ? $_POST['reason'] : '');
    $reason = $reasonRaw !== '' ? $reasonRaw : null;
    $global = isset($_POST['global']);
    $nets = [];
    if (!$global) {
        $netIds = is_array($_POST['networks'] ?? null) ? $_POST['networks'] : [];
        foreach ($netIds as $nid) {
            if (!is_numeric($nid)) continue;
            $n = $app['svc']->getNetwork((int)$nid);
            if ($n !== null) $nets[] = $n;
        }
    }
    if ($host === '') {
        web_ignores_list('Hostmask required');
    }
    if (!$global && !$nets) {
        web_ignores_list('Select at least one network, or check Global');
    }
    try { $app['svc']->addIgnore($host, $reason, $nets); } catch (\Throwable $e) { web_ignores_list($e->getMessage()); }
    web_redirect('/ignores');
}

function web_ignores_delete(int $id): never
{
    $app = web_app();
    try { web_verify_csrf(); } catch (\Throwable $e) { web_ignores_list($e->getMessage()); }
    $ig = $app['svc']->getIgnore($id);
    if ($ig !== null) { $app['svc']->deleteIgnore($ig); }
    web_redirect('/ignores');
}
