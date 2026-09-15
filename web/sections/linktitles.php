<?php

use lolbot\config\LinktitlesDefaults;
use lolbot\config\LinktitlesResolved;
use lolbot\config\SettingsResolver;
use lolbot\entities\Channel;
use lolbot\entities\Network;
use scripts\linktitles\entities\hostignore;
use scripts\linktitles\entities\ignore;
use scripts\linktitles\entities\ignore_type;
use scripts\linktitles\entities\linktitles_setting;
use scripts\linktitles\IgnoreMatcher;

/**
 * Render a bool as the form's on/off radio value. Kept as a helper so
 * class-constant defaults (always false) don't trip constant-fold ternaries.
 */
function web_lt_bool_str(bool $b): string
{
    return $b ? 'on' : 'off';
}

/**
 * Build one linktitles field descriptor for the template's inherit_field macro.
 *
 * @return array{name:string,label:string,type:string,value:string,source:string,hint:string}
 */
function web_lt_desc(string $name, string $label, string $type, string $value, string $source, string $hint): array
{
    return ['name' => $name, 'label' => $label, 'type' => $type, 'value' => $value, 'source' => $source, 'hint' => $hint];
}

/**
 * Parse a reasoning (JSON) form value. Must decode to an object (a
 * string-keyed array, possibly empty); non-empty lists, scalars and invalid
 * syntax are rejected so ConfigService::setLinktitlesSetting can never throw
 * mid-apply.
 *
 * @return array<string, mixed>
 */
function web_lt_parse_reasoning_json(string $raw): array
{
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new \InvalidArgumentException('reasoning must be a JSON object');
    }
    foreach ($decoded as $k => $_) {
        if (!is_string($k)) {
            throw new \InvalidArgumentException('reasoning must be a JSON object with string keys');
        }
    }
    return $decoded;
}

/**
 * Global-tier fields. Source is 'global' when the global row sets the field
 * (non-null), else 'default'. Hints always describe the code default.
 *
 * @return list<array{name:string,label:string,type:string,value:string,source:string,hint:string}>
 */
function web_lt_global_fields(?linktitles_setting $g): array
{
    $fields = [];

    if ($g !== null && $g->enabled !== null) {
        $val = $g->enabled ? 'on' : 'off';
        $src = 'global';
    } else {
        $val = web_lt_bool_str(LinktitlesDefaults::ENABLED);
        $src = 'default';
    }
    $fields[] = web_lt_desc('enabled', 'enabled', 'bool', $val, $src, 'default: ' . web_lt_bool_str(LinktitlesDefaults::ENABLED));

    if ($g !== null && $g->ai_vision_disabled !== null) {
        $val = $g->ai_vision_disabled ? 'on' : 'off';
        $src = 'global';
    } else {
        $val = web_lt_bool_str(LinktitlesDefaults::AI_VISION_DISABLED);
        $src = 'default';
    }
    $fields[] = web_lt_desc('ai_vision_disabled', 'ai vision disabled', 'bool', $val, $src, 'default: ' . web_lt_bool_str(LinktitlesDefaults::AI_VISION_DISABLED));

    if ($g !== null && $g->url_log_chan !== null) {
        $val = $g->url_log_chan;
        $src = 'global';
    } else {
        $val = '';
        $src = 'default';
    }
    $fields[] = web_lt_desc('url_log_chan', 'url log chan', 'text', $val, $src, 'default: (none)');

    if ($g !== null && $g->ai_vision_model !== null) {
        $val = $g->ai_vision_model;
        $src = 'global';
    } else {
        $val = LinktitlesDefaults::MODEL;
        $src = 'default';
    }
    $fields[] = web_lt_desc('ai_vision_model', 'ai vision model', 'text', $val, $src, 'default: ' . LinktitlesDefaults::MODEL);

    if ($g !== null && $g->ai_vision_prompt !== null) {
        $val = $g->ai_vision_prompt;
        $src = 'global';
    } else {
        $val = LinktitlesDefaults::PROMPT;
        $src = 'default';
    }
    $fields[] = web_lt_desc('ai_vision_prompt', 'ai vision prompt', 'textarea', $val, $src, 'default: ' . LinktitlesDefaults::PROMPT);

    if ($g !== null && $g->ai_vision_reasoning_effort !== null) {
        $val = $g->ai_vision_reasoning_effort;
        $src = 'global';
    } else {
        $val = '';
        $src = 'default';
    }
    $fields[] = web_lt_desc('ai_vision_reasoning_effort', 'reasoning effort', 'text', $val, $src, 'default: (none)');

    if ($g !== null && $g->ai_vision_reasoning !== null) {
        $encoded = json_encode($g->ai_vision_reasoning, JSON_UNESCAPED_SLASHES);
        $val = $encoded === false ? '' : $encoded;
        $src = 'global';
    } else {
        $val = '';
        $src = 'default';
    }
    $fields[] = web_lt_desc('ai_vision_reasoning', 'reasoning (JSON)', 'json', $val, $src, 'default: (none)');

    return $fields;
}

/**
 * Resolved (network/channel) fields. Source comes from the cascade; when it
 * matches the form's tier the field is an override, otherwise inherited.
 *
 * @return list<array{name:string,label:string,type:string,value:string,source:string,hint:string}>
 */
function web_lt_resolved_fields(LinktitlesResolved $r): array
{
    $sources = $r->sources;
    $shown = static function (bool|string|array|null $v): string {
        if (is_bool($v)) {
            return $v ? 'on' : 'off';
        }
        if ($v === null) {
            return '(none)';
        }
        if (is_array($v)) {
            $enc = json_encode($v, JSON_UNESCAPED_SLASHES);
            return $enc === false ? '(none)' : $enc;
        }
        return (string)$v;
    };
    $hint = static fn (bool|string|array|null $v, string $src): string => 'inherits: ' . $shown($v) . ' (from ' . $src . ')';

    return [
        web_lt_desc('enabled', 'enabled', 'bool', $r->enabled ? 'on' : 'off', $sources['enabled'], $hint($r->enabled, $sources['enabled'])),
        web_lt_desc('ai_vision_disabled', 'ai vision disabled', 'bool', $r->aiVisionDisabled ? 'on' : 'off', $sources['ai_vision_disabled'], $hint($r->aiVisionDisabled, $sources['ai_vision_disabled'])),
        web_lt_desc('url_log_chan', 'url log chan', 'text', $r->urlLogChan ?? '', $sources['url_log_chan'], $hint($r->urlLogChan, $sources['url_log_chan'])),
        web_lt_desc('ai_vision_model', 'ai vision model', 'text', $r->aiVisionModel, $sources['ai_vision_model'], $hint($r->aiVisionModel, $sources['ai_vision_model'])),
        web_lt_desc('ai_vision_prompt', 'ai vision prompt', 'textarea', $r->aiVisionPrompt, $sources['ai_vision_prompt'], $hint($r->aiVisionPrompt, $sources['ai_vision_prompt'])),
        web_lt_desc('ai_vision_reasoning_effort', 'reasoning effort', 'text', $r->aiVisionReasoningEffort ?? '', $sources['ai_vision_reasoning_effort'], $hint($r->aiVisionReasoningEffort, $sources['ai_vision_reasoning_effort'])),
        web_lt_desc(
            'ai_vision_reasoning',
            'reasoning (JSON)',
            'json',
            $r->aiVisionReasoning !== null ? ((string)(json_encode($r->aiVisionReasoning, JSON_UNESCAPED_SLASHES) ?: '')) : '',
            $sources['ai_vision_reasoning'],
            $hint($r->aiVisionReasoning, $sources['ai_vision_reasoning'])
        ),
    ];
}

/** @return list<array{id:int,name:string,bot:string}> */
function web_lt_channels(Network $net): array
{
    $out = [];
    foreach ($net->getBots() as $bot) {
        foreach ($bot->getChannels() as $chan) {
            $out[] = ['id' => $chan->id, 'name' => $chan->name, 'bot' => $bot->name];
        }
    }
    return $out;
}

function web_linktitles(?string $error = null): never
{
    $app = web_app();
    $em = $app['em'];
    $svc = $app['svc'];
    $resolver = new SettingsResolver($em);

    $globalRow = $em->getRepository(linktitles_setting::class)->findOneBy(['network' => null, 'channel' => null]);

    $networks = [];
    foreach ($svc->listNetworks() as $net) {
        $resolved = $resolver->resolveLinktitles($net, null);
        $networks[] = [
            'net' => $net,
            'fields' => web_lt_resolved_fields($resolved),
            'channels' => web_lt_channels($net),
        ];
    }

    web_render('linktitles.twig', [
        'active' => 'linktitles',
        'section' => 'Linktitles',
        'globalFields' => web_lt_global_fields($globalRow),
        'networks' => $networks,
        'urlIgnores' => $svc->listLinktitlesIgnores(),
        'hostIgnores' => $svc->listLinktitlesHostignores(),
        'allNetworks' => $svc->listNetworks(),
        'allBots' => $svc->listBots(),
        'error' => $error,
    ]);
}

function web_linktitles_channel(int $chanId, ?string $error = null): never
{
    $app = web_app();
    $chan = $app['em']->find(Channel::class, $chanId);
    if ($chan === null) {
        http_response_code(404);
        echo "No such channel";
        exit;
    }
    $net = $chan->bot->network;
    $resolved = (new SettingsResolver($app['em']))->resolveLinktitles($net, $chan);

    web_render('linktitles/channel.twig', [
        'active' => 'linktitles',
        'section' => 'Linktitles',
        'chan' => $chan,
        'net' => $net,
        'fields' => web_lt_resolved_fields($resolved),
        'thisTier' => 'channel',
        'error' => $error,
    ]);
}

/**
 * Apply posted linktitles settings for a scope. Empty text / "inherit" radio
 * resets the field to inherit; otherwise the value is stored. reasoning
 * (JSON) is parsed and validated up front so a bad value aborts the whole
 * save instead of leaving the other fields half-applied.
 */
function web_lt_apply(?Network $net, ?Channel $chan): void
{
    try {
        web_verify_csrf();
    } catch (\Throwable $e) {
        if ($chan !== null) {
            web_linktitles_channel($chan->id, $e->getMessage());
        }
        web_linktitles($e->getMessage());
    }

    $svc = web_app()['svc'];

    $reasoningRaw = trim(is_string($_POST['ai_vision_reasoning'] ?? null) ? $_POST['ai_vision_reasoning'] : '');
    $reasoning = null;
    if ($reasoningRaw !== '') {
        try {
            $reasoning = web_lt_parse_reasoning_json($reasoningRaw);
        } catch (\InvalidArgumentException $e) {
            if ($chan !== null) {
                web_linktitles_channel($chan->id, $e->getMessage());
            }
            web_linktitles($e->getMessage());
        }
    }

    foreach (['url_log_chan', 'ai_vision_model', 'ai_vision_prompt', 'ai_vision_reasoning_effort'] as $k) {
        $raw = trim(is_string($_POST[$k] ?? null) ? $_POST[$k] : '');
        if ($raw === '') {
            $svc->resetLinktitlesSetting($net, $chan, $k);
            continue;
        }
        $svc->setLinktitlesSetting($net, $chan, $k, $raw);
    }
    foreach (['enabled', 'ai_vision_disabled'] as $k) {
        $v = is_string($_POST[$k] ?? null) ? $_POST[$k] : 'inherit';
        if ($v === 'inherit') {
            $svc->resetLinktitlesSetting($net, $chan, $k);
            continue;
        }
        $svc->setLinktitlesSetting($net, $chan, $k, $v === 'on');
    }

    if ($reasoningRaw === '') {
        $svc->resetLinktitlesSetting($net, $chan, 'ai_vision_reasoning');
    } else {
        $svc->setLinktitlesSetting($net, $chan, 'ai_vision_reasoning', $reasoning);
    }
}

function web_linktitles_save_global(): never
{
    web_lt_apply(null, null);
    web_redirect('/linktitles');
}

function web_linktitles_save_network(int $netId): never
{
    $net = web_app()['svc']->getNetwork($netId);
    if ($net === null) {
        http_response_code(404);
        echo "No such network";
        exit;
    }
    web_lt_apply($net, null);
    web_redirect('/linktitles');
}

function web_linktitles_save_channel(int $chanId): never
{
    $chan = web_app()['em']->find(Channel::class, $chanId);
    if ($chan === null) {
        http_response_code(404);
        echo "No such channel";
        exit;
    }
    web_lt_apply(null, $chan);
    web_redirect('/linktitles/channel/' . $chanId);
}

/** Scope label for a linktitles ignore row: "global", "network: N", "bot: B". */
function web_lt_scope_label(ignore|hostignore $ig): string
{
    if ($ig->type === ignore_type::network) {
        return 'network: ' . ($ig->network->name ?? '?');
    }
    if ($ig->type === ignore_type::bot) {
        return 'bot: ' . ($ig->bot->name ?? '?');
    }
    return 'global';
}

/**
 * Flatten matcher results for the tester fragment: the pattern shown per
 * kind, a scope label, and an invalid-pattern flag (URL kind only, legacy
 * rows that no longer compile).
 *
 * @param list<ignore|hostignore> $matches
 * @return list<array{id:int,pattern:string,scope:string,invalid:bool}>
 */
function web_lt_match_rows(array $matches): array
{
    $rows = [];
    foreach ($matches as $ig) {
        $rows[] = [
            'id' => $ig->id,
            'pattern' => $ig instanceof ignore ? $ig->regex : $ig->hostmask,
            'scope' => web_lt_scope_label($ig),
            'invalid' => $ig instanceof ignore && !IgnoreMatcher::patternIsValid($ig->regex),
        ];
    }
    return $rows;
}

/**
 * Resolve the add-form scope from POST: 'type' plus the matching
 * network/bot select. Throws when the type is unknown or its target is
 * missing (surfaced as the page error alert by the callers). A 'channel'
 * type passes through unresolved (null net/bot) and is rejected
 * downstream by ConfigService.
 *
 * @param array{svc: \lolbot\config\ConfigService} $app
 * @return array{0: ignore_type, 1: ?\lolbot\entities\Network, 2: ?\lolbot\entities\Bot}
 */
function web_lt_ignore_scope_from_post(array $app): array
{
    $raw = is_string($_POST['type'] ?? null) ? $_POST['type'] : '';
    $type = ignore_type::fromString($raw);
    $net = null;
    $bot = null;
    if ($type === ignore_type::network) {
        $nid = is_numeric($_POST['network'] ?? null) ? (int)$_POST['network'] : 0;
        $net = $app['svc']->getNetwork($nid);
        if ($net === null) {
            throw new \InvalidArgumentException('Select a network for network-scoped ignores');
        }
    }
    if ($type === ignore_type::bot) {
        $bid = is_numeric($_POST['bot'] ?? null) ? (int)$_POST['bot'] : 0;
        $bot = $app['svc']->getBot($bid);
        if ($bot === null) {
            throw new \InvalidArgumentException('Select a bot for bot-scoped ignores');
        }
    }
    return [$type, $net, $bot];
}

/**
 * Optional tester scope: empty/0 selects mean null. A selected bot implies
 * its network, so the tester sees exactly what a bot on that network would.
 * Unknown ids and mismatched network+bot pairs throw (surfaced as
 * fragment errors by the callers).
 *
 * @param array{svc: \lolbot\config\ConfigService} $app
 * @return array{0: ?\lolbot\entities\Network, 1: ?\lolbot\entities\Bot}
 */
function web_lt_test_scope_from_post(array $app): array
{
    $net = null;
    $bot = null;
    if (is_numeric($_POST['network'] ?? null) && (int)$_POST['network'] > 0) {
        $net = $app['svc']->getNetwork((int)$_POST['network']);
        if ($net === null) {
            throw new \InvalidArgumentException('Unknown network');
        }
    }
    if (is_numeric($_POST['bot'] ?? null) && (int)$_POST['bot'] > 0) {
        $bot = $app['svc']->getBot((int)$_POST['bot']);
        if ($bot === null) {
            throw new \InvalidArgumentException('Unknown bot');
        }
    }
    if ($net === null && $bot !== null) {
        $net = $bot->network;
    }
    if ($net !== null && $bot !== null && $bot->network->id !== $net->id) {
        throw new \InvalidArgumentException('Bot is not on the selected network');
    }
    return [$net, $bot];
}

// Linktitles ignores (URL regex + hostmask) — same flow as the global
// ignores section: CSRF → validate → ConfigService → redirect.
function web_linktitles_ignores_create(): never
{
    $app = web_app();
    try {
        web_verify_csrf();
    } catch (\Throwable $e) {
        web_linktitles($e->getMessage());
    }
    try {
        [$type, $net, $bot] = web_lt_ignore_scope_from_post($app);
        $pattern = is_string($_POST['pattern'] ?? null) ? $_POST['pattern'] : '';
        $app['svc']->addLinktitlesIgnore($pattern, $type, $net, $bot);
    } catch (\Throwable $e) {
        web_linktitles($e->getMessage());
    }
    web_redirect('/linktitles');
}

function web_linktitles_ignores_delete(int $id): never
{
    $app = web_app();
    try {
        web_verify_csrf();
    } catch (\Throwable $e) {
        web_linktitles($e->getMessage());
    }
    $ig = $app['svc']->getLinktitlesIgnore($id);
    if ($ig !== null) {
        $app['svc']->deleteLinktitlesIgnore($ig);
    }
    web_redirect('/linktitles');
}

function web_linktitles_hostignores_create(): never
{
    $app = web_app();
    try {
        web_verify_csrf();
    } catch (\Throwable $e) {
        web_linktitles($e->getMessage());
    }
    try {
        [$type, $net, $bot] = web_lt_ignore_scope_from_post($app);
        $hostmask = is_string($_POST['hostmask'] ?? null) ? $_POST['hostmask'] : '';
        $app['svc']->addLinktitlesHostignore($hostmask, $type, $net, $bot);
    } catch (\Throwable $e) {
        web_linktitles($e->getMessage());
    }
    web_redirect('/linktitles');
}

function web_linktitles_hostignores_delete(int $id): never
{
    $app = web_app();
    try {
        web_verify_csrf();
    } catch (\Throwable $e) {
        web_linktitles($e->getMessage());
    }
    $ig = $app['svc']->getLinktitlesHostignore($id);
    if ($ig !== null) {
        $app['svc']->deleteLinktitlesHostignore($ig);
    }
    web_redirect('/linktitles');
}

// Tester (HTMX fragments). Same matcher the bot enforces with, so results
// can never drift from actual enforcement.
function web_linktitles_ignores_test(): never
{
    $app = web_app();
    try {
        web_verify_csrf();
    } catch (\Throwable $e) {
        web_error_fragment($e->getMessage());
    }
    $url = trim(is_string($_POST['url'] ?? null) ? $_POST['url'] : '');
    if ($url === '') {
        web_error_fragment('URL required');
    }
    try {
        [$net, $bot] = web_lt_test_scope_from_post($app);
        $matches = IgnoreMatcher::findUrlMatches($app['em'], $net, $bot, $url);
        web_render_fragment('linktitles/_test_result.twig', ['rows' => web_lt_match_rows($matches)]);
    } catch (\Throwable $e) {
        web_error_fragment($e->getMessage());
    }
}

function web_linktitles_hostignores_test(): never
{
    $app = web_app();
    try {
        web_verify_csrf();
    } catch (\Throwable $e) {
        web_error_fragment($e->getMessage());
    }
    $fullhost = trim(is_string($_POST['hostmask'] ?? null) ? $_POST['hostmask'] : '');
    if ($fullhost === '') {
        web_error_fragment('Hostmask required');
    }
    try {
        [$net, $bot] = web_lt_test_scope_from_post($app);
        $matches = IgnoreMatcher::findHostMatches($app['em'], $net, $bot, $fullhost);
        web_render_fragment('linktitles/_test_result.twig', ['rows' => web_lt_match_rows($matches)]);
    } catch (\Throwable $e) {
        web_error_fragment($e->getMessage());
    }
}
