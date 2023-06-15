# lolbot
This is just a quick bot I put together for friends in IRC, nothing serious.

It's using the IRC library found here https://github.com/TorbenKoehn/php-irc
which I modified a little to use Amphp.

## Running the bot
I just run the bot inside a tmux session left open.

To setup you need to edit config.yaml, artconfig.yaml or multiartconfig.yaml depending on what kind of bot you will run.
There are many API keys that need to be obtained by you and placed in the config file, if you leave them commented out then the commands or functionality that need them will be disabled.

For the normal sopel-like channel bot
```
php lolbot.php
```

For a artbot that uses 1 irc connection
```
php artbot.php
```

For an artbot that uses multiple irc connections (for slow networks)
```
php multiartbot.php
```


For the youtube thumbnails support and the @img command you need to download and compile https://github.com/knivey/p2u then set the path to it in your config.yaml
```
p2u: "/path/to/p2u"
```

For a2m you need to setup https://github.com/tat3r/a2m and edit the appropriate config setting for that also.


## Ignores

Currently, all bots read from ignores.txt to ignore commands from hostmasks. It's a good idea to put other bots in the ignores to prevent them from endlessly triggering each other.

The file is just one hostmask per line.

You can edit the file while bots are running, They will reread it without needing to be restarted.

## Additional configuration
The notifier system has a file in ```scripts/notifier/notifier_keys.yaml```

If you plan to use external tools that send notifications for the bot to display in channels you will need to add keys to that.