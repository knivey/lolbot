<?php
namespace lolbot\config;

/**
 * Seam called by ConfigService after every successful mutation.
 * The default NoopChangeNotifier does nothing; Sub-project 2 provides an
 * HTTP-push implementation that POSTs to each bot's notifier endpoint.
 */
interface ChangeNotifier
{
    public function notify(ConfigChange $change): void;
}
