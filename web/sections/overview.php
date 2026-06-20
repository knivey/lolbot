<?php
function web_overview(): never
{
    web_render('overview.twig', ['active' => 'overview', 'section' => 'Overview']);
}

// HTMX-polled fragment: fetches live status from the running bot.
function web_overview_status(): never
{
    $app = web_app();
    web_render_fragment('_status.twig', ['bots' => web_bot_status($app)]);
}
