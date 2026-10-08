<?php

namespace library\testenv;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Loads test-environment profiles from testenv/profiles/<name>.yaml with an
 * optional secrets overlay from testenv/secrets.yaml, validates required
 * keys, and exposes the parsed structure to the test harness.
 */
class Profile
{
    private string $name;

    /** @var array<string, mixed> */
    private array $data;

    /**
     * @param array<string, mixed> $data merged profile data (secrets already applied)
     */
    public function __construct(string $name, array $data)
    {
        $this->name = $name;
        $this->data = $data;
    }

    /**
     * Resolve and load a profile by name. Throws ProfileException when the
     * profile file is missing, the YAML is invalid, or validation fails.
     */
    public static function load(string $name): self
    {
        $file = self::root() . 'testenv/profiles/' . $name . '.yaml';
        if (!is_file($file)) {
            throw new ProfileException(sprintf("profile '%s' not found (expected %s)", $name, $file));
        }
        try {
            $parsed = Yaml::parseFile($file);
        } catch (ParseException $e) {
            throw new ProfileException(sprintf("profile '%s' is not valid YAML: %s", $name, $e->getMessage()), 0, $e);
        }
        if (!is_array($parsed)) {
            throw new ProfileException(sprintf("profile '%s' must be a YAML mapping", $name));
        }
        /** @var array<string, mixed> $parsed */
        $data = self::applySecrets($name, $parsed);
        $profile = new self($name, $data);
        $profile->validate();
        return $profile;
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function networks(): array
    {
        /** @var array<int, array<string, mixed>> $networks validated by validate() */
        $networks = $this->data['networks'];
        return $networks;
    }

    /**
     * @return array<string, mixed>
     */
    public function driver(): array
    {
        /** @var array<string, mixed> $driver validated by validate() */
        $driver = $this->data['driver'];
        return $driver;
    }

    /**
     * Seed users for a network.
     *
     * Rules:
     *  - network declares `seed_users: none` -> empty array (manual bootstrap)
     *  - network declares a `seed_users` list -> returned as declared
     *  - absent -> default of the driver nick with admin flags
     *
     * @return array<int, array<string, mixed>>
     */
    public function seedUsers(string $network): array
    {
        foreach ($this->networks() as $net) {
            if (($net['name'] ?? null) !== $network) {
                continue;
            }
            $seed = $net['seed_users'] ?? null;
            if ($seed === 'none') {
                return [];
            }
            if (is_array($seed)) {
                /** @var array<int, array<string, mixed>> $seed */
                return $seed;
            }
            return [['name' => $this->driver()['nick'], 'flags' => ['admin']]];
        }
        throw new ProfileException(sprintf("profile '%s' has no network '%s'", $this->name, $network));
    }

    /**
     * Raw lines the driver client sends right after 001 (e.g. GameSurge
     * AuthServ — that network has no SASL; auth happens post-welcome).
     * Accepts a single string or a list; non-string/empty entries are
     * dropped; absent or garbage yields no lines.
     *
     * @return list<string>
     */
    public function driverOnConnect(): array
    {
        $driver = $this->data['driver'] ?? null;
        $onConnect = is_array($driver) ? ($driver['on_connect'] ?? null) : null;
        if (is_string($onConnect) && $onConnect !== '') {
            return [$onConnect];
        }
        if (!is_array($onConnect)) {
            return [];
        }
        $lines = [];
        foreach ($onConnect as $line) {
            if (is_string($line) && $line !== '') {
                $lines[] = $line;
            }
        }
        return $lines;
    }

    /**
     * Walk the required keys and throw ProfileException naming the first
     * missing or malformed key path.
     */
    private function validate(): void
    {
        $driver = $this->data['driver'] ?? null;
        if (!is_array($driver) || !isset($driver['nick']) || !is_string($driver['nick']) || $driver['nick'] === '') {
            throw $this->missing('driver.nick');
        }
        $networks = $this->data['networks'] ?? null;
        if (!is_array($networks) || $networks === [] || !array_is_list($networks)) {
            throw $this->missing('networks');
        }
        foreach ($networks as $ni => $net) {
            if (!is_array($net) || !isset($net['name']) || !is_string($net['name']) || $net['name'] === '') {
                throw $this->missing("networks.$ni.name");
            }
            $servers = $net['servers'] ?? null;
            if (!is_array($servers) || $servers === [] || !array_is_list($servers)) {
                throw $this->missing("networks.$ni.servers");
            }
            foreach ($servers as $si => $server) {
                if (!is_array($server) || !isset($server['address']) || !is_string($server['address']) || $server['address'] === '') {
                    throw $this->missing("networks.$ni.servers.$si.address");
                }
            }
            $bots = $net['bots'] ?? null;
            if (!is_array($bots) || $bots === [] || !array_is_list($bots)) {
                throw $this->missing("networks.$ni.bots");
            }
            foreach ($bots as $bi => $bot) {
                if (!is_array($bot) || !isset($bot['name']) || !is_string($bot['name']) || $bot['name'] === '') {
                    throw $this->missing("networks.$ni.bots.$bi.name");
                }
            }
        }
    }

    private function missing(string $path): ProfileException
    {
        return new ProfileException(
            sprintf("profile '%s' missing required key '%s'", $this->name, $path)
        );
    }

    /**
     * Deep-merge the secrets overlay for this profile: `driver` keys and
     * per-network `bots` entries (e.g. sasl_pass) on top of the profile data.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function applySecrets(string $name, array $data): array
    {
        $secretsFile = self::root() . 'testenv/secrets.yaml';
        if (!is_file($secretsFile)) {
            return $data;
        }
        try {
            $secrets = Yaml::parseFile($secretsFile);
        } catch (ParseException $e) {
            throw new ProfileException('testenv/secrets.yaml is not valid YAML: ' . $e->getMessage(), 0, $e);
        }
        if ($secrets === null) {
            return $data;
        }
        if (!is_array($secrets)) {
            throw new ProfileException('testenv/secrets.yaml must be a mapping of profile names to secret values');
        }
        $overlay = $secrets[$name] ?? null;
        if ($overlay === null) {
            return $data;
        }
        if (!is_array($overlay)) {
            throw new ProfileException(sprintf("testenv/secrets.yaml key '%s' must be a mapping", $name));
        }
        $driverOverlay = $overlay['driver'] ?? null;
        if (is_array($driverOverlay)) {
            $driver = is_array($data['driver'] ?? null) ? $data['driver'] : [];
            $data['driver'] = array_merge($driver, $driverOverlay);
        }
        $netOverlays = $overlay['networks'] ?? null;
        if (is_array($netOverlays)) {
            $networks = is_array($data['networks'] ?? null) ? $data['networks'] : [];
            foreach ($netOverlays as $ni => $netOverlay) {
                $botOverlays = is_array($netOverlay) ? ($netOverlay['bots'] ?? null) : null;
                if (!is_array($botOverlays)) {
                    continue;
                }
                $net = $networks[$ni] ?? null;
                if (!is_array($net)) {
                    continue;
                }
                $bots = $net['bots'] ?? null;
                if (!is_array($bots)) {
                    continue;
                }
                foreach ($botOverlays as $bi => $botOverlay) {
                    $bot = $bots[$bi] ?? null;
                    if (is_array($bot) && is_array($botOverlay)) {
                        $bots[$bi] = array_merge($bot, $botOverlay);
                    }
                }
                $net['bots'] = $bots;
                $networks[$ni] = $net;
            }
            $data['networks'] = $networks;
        }
        return $data;
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
    }
}
