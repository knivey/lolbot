<?php
use lolbot\config\LinktitlesDefaults;
use lolbot\config\LinktitlesResolved;
use lolbot\config\SettingsResolver;
use lolbot\entities\Channel;
use lolbot\entities\Network;
use scripts\linktitles\entities\linktitles_setting;

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
    $shown = static function (bool|string|null $v): string {
        if (is_bool($v)) {
            return $v ? 'on' : 'off';
        }
        if ($v === null) {
            return '(none)';
        }
        return (string)$v;
    };
    $hint = static fn(bool|string|null $v, string $src): string => 'inherits: ' . $shown($v) . ' (from ' . $src . ')';

    return [
        web_lt_desc('enabled', 'enabled', 'bool', $r->enabled ? 'on' : 'off', $sources['enabled'], $hint($r->enabled, $sources['enabled'])),
        web_lt_desc('ai_vision_disabled', 'ai vision disabled', 'bool', $r->aiVisionDisabled ? 'on' : 'off', $sources['ai_vision_disabled'], $hint($r->aiVisionDisabled, $sources['ai_vision_disabled'])),
        web_lt_desc('url_log_chan', 'url log chan', 'text', $r->urlLogChan ?? '', $sources['url_log_chan'], $hint($r->urlLogChan, $sources['url_log_chan'])),
        web_lt_desc('ai_vision_model', 'ai vision model', 'text', $r->aiVisionModel, $sources['ai_vision_model'], $hint($r->aiVisionModel, $sources['ai_vision_model'])),
        web_lt_desc('ai_vision_prompt', 'ai vision prompt', 'textarea', $r->aiVisionPrompt, $sources['ai_vision_prompt'], $hint($r->aiVisionPrompt, $sources['ai_vision_prompt'])),
        web_lt_desc('ai_vision_reasoning_effort', 'reasoning effort', 'text', $r->aiVisionReasoningEffort ?? '', $sources['ai_vision_reasoning_effort'], $hint($r->aiVisionReasoningEffort, $sources['ai_vision_reasoning_effort'])),
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
            'reasoningJson' => $resolved->aiVisionReasoning !== null ? json_encode($resolved->aiVisionReasoning, JSON_PRETTY_PRINT) : '',
            'channels' => web_lt_channels($net),
        ];
    }

    web_render('linktitles.twig', [
        'active' => 'linktitles',
        'section' => 'Linktitles',
        'globalFields' => web_lt_global_fields($globalRow),
        'globalReasoningJson' => ($globalRow !== null && $globalRow->ai_vision_reasoning !== null) ? json_encode($globalRow->ai_vision_reasoning, JSON_PRETTY_PRINT) : '',
        'networks' => $networks,
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
        'reasoningJson' => $resolved->aiVisionReasoning !== null ? json_encode($resolved->aiVisionReasoning, JSON_PRETTY_PRINT) : '',
        'thisTier' => 'channel',
        'error' => $error,
    ]);
}

/**
 * Apply posted linktitles settings for a scope. Empty text / "inherit" radio
 * resets the field to inherit; otherwise the value is stored.
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
