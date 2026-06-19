<?php
namespace lolbot\config;

/**
 * Default ChangeNotifier that does nothing. ConfigService uses this until
 * Sub-project 2 provides an HTTP-push implementation that POSTs to each
 * bot's notifier endpoint.
 */
class NoopChangeNotifier implements ChangeNotifier
{
    public function notify(ConfigChange $change): void
    {
        // intentionally empty
    }
}
