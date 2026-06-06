# Linktitles Settings Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add per-network and per-channel database-backed toggle to disable AI image descriptions, configurable via admin-cli.

**Architecture:** New Doctrine entity `linktitles_setting` in the linktitles entities directory with FKs to Network and Channel. New migration. New Symfony Console command `linktitles:set`. Query in `linktitles.php` checks for disable flag before calling AI.

**Tech Stack:** PHP 8.1+, Doctrine ORM, Symfony Console

---

### Task 1: Create the linktitles_setting entity

**Files:**
- Create: `scripts/linktitles/entities/linktitles_setting.php`

- [ ] **Step 1: Create the entity file**

Create `scripts/linktitles/entities/linktitles_setting.php`:

```php
<?php
namespace scripts\linktitles\entities;

use Doctrine\ORM\Mapping as ORM;
use lolbot\entities\Channel;
use lolbot\entities\Network;

#[ORM\Entity]
#[ORM\Table("linktitles_settings")]
#[ORM\UniqueConstraint(name: "scope_unique", columns: ["network_id", "channel_id"])]
class linktitles_setting
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(updatable: false)]
    public int $id;

    #[ORM\ManyToOne(targetEntity: Network::class)]
    #[ORM\JoinColumn(name: 'network_id', referencedColumnName: 'id', nullable: true)]
    public ?Network $network = null;

    #[ORM\ManyToOne(targetEntity: Channel::class)]
    #[ORM\JoinColumn(name: 'channel_id', referencedColumnName: 'id', nullable: true)]
    public ?Channel $channel = null;

    #[ORM\Column]
    public bool $ai_vision_disabled = false;

    public function __toString(): string
    {
        $scope = $this->channel ? "channel:{$this->channel->name}" : "network:{$this->network?->name}";
        return "id: {$this->id} scope: $scope ai_vision_disabled: " . ($this->ai_vision_disabled ? 'true' : 'false');
    }
}
```

- [ ] **Step 2: Verify syntax**

Run: `php -l scripts/linktitles/entities/linktitles_setting.php`
Expected: No syntax errors

- [ ] **Step 3: Commit**

```bash
git add scripts/linktitles/entities/linktitles_setting.php
git commit -m "feat: add linktitles_setting entity for per-network/channel settings"
```

---

### Task 2: Create the migration

**Files:**
- Create: `Migrations/Version20260606120000.php`

- [ ] **Step 1: Create the migration file**

Create `Migrations/Version20260606120000.php`:

```php
<?php

declare(strict_types=1);

namespace lolbot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260606120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create linktitles_settings table for per-network/channel AI vision toggle';
    }

    public function up(Schema $schema): void
    {
        $t = $schema->createTable("linktitles_settings");
        $t->addColumn("id", Types::INTEGER)->setNotnull(true)->setAutoincrement(true);
        $t->setPrimaryKey(["id"]);
        $t->addColumn("network_id", Types::INTEGER)->setNotnull(false);
        $t->addColumn("channel_id", Types::INTEGER)->setNotnull(false);
        $t->addColumn("ai_vision_disabled", Types::BOOLEAN)->setNotnull(true)->setDefault(false);
        $t->addUniqueConstraint(["network_id", "channel_id"], "scope_unique");
        $t->addForeignKeyConstraint("Networks", ["network_id"], ["id"], ["onDelete" => "CASCADE"]);
        $t->addForeignKeyConstraint("Channels", ["channel_id"], ["id"], ["onDelete" => "CASCADE"]);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable("linktitles_settings");
    }
}
```

- [ ] **Step 2: Verify syntax**

Run: `php -l Migrations/Version20260606120000.php`
Expected: No syntax errors

- [ ] **Step 3: Commit**

```bash
git add Migrations/Version20260606120000.php
git commit -m "feat: add migration for linktitles_settings table"
```

---

### Task 3: Create the linktitles:set CLI command

**Files:**
- Create: `cli_cmds/linktitles_set.php`

Follow the pattern from `cli_cmds/bot_set.php` and `scripts/linktitles/cli_cmds/ignore_add.php`.

- [ ] **Step 1: Create the CLI command**

Create `cli_cmds/linktitles_set.php`:

```php
<?php
namespace lolbot\cli_cmds;
/**
 * @psalm-suppress InvalidGlobal
 */
global $entityManager;

use lolbot\entities\Channel;
use lolbot\entities\Network;
use scripts\linktitles\entities\linktitles_setting;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand("linktitles:set")]
class linktitles_set extends Command
{
    /** @var array<string> */
    public array $settings = [
        "ai_vision_disabled"
    ];

    protected function configure(): void
    {
        $this->addOption("network", "N", InputOption::VALUE_REQUIRED, "Network ID (required)");
        $this->addOption("channel", "C", InputOption::VALUE_REQUIRED, "Channel ID (optional, for per-channel setting)");
        $this->addArgument("setting", InputArgument::OPTIONAL, "Setting name");
        $this->addArgument("value", InputArgument::OPTIONAL, "New value");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        global $entityManager;

        $networkId = $input->getOption("network");
        if ($networkId === null) {
            throw new \InvalidArgumentException("--network is required");
        }

        $network = $entityManager->getRepository(Network::class)->find($networkId);
        if (!$network) {
            throw new \InvalidArgumentException("Network by that ID not found");
        }

        $channelId = $input->getOption("channel");
        $channel = null;
        if ($channelId !== null) {
            $channel = $entityManager->getRepository(Channel::class)->find($channelId);
            if (!$channel) {
                throw new \InvalidArgumentException("Channel by that ID not found");
            }
        }

        $repo = $entityManager->getRepository(linktitles_setting::class);
        $setting = $repo->findOneBy([
            'network' => $channel ? null : $network,
            'channel' => $channel,
        ]);

        if ($input->getArgument("setting") === null) {
            $this->showSettings($input, $output, $setting, $network, $channel);
            return Command::SUCCESS;
        }

        if (!in_array($input->getArgument("setting"), $this->settings)) {
            throw new \InvalidArgumentException("No setting by that name. Available: " . implode(", ", $this->settings));
        }

        if ($input->getArgument("value") === null) {
            $this->showSettings($input, $output, $setting, $network, $channel);
            return Command::SUCCESS;
        }

        if ($setting === null) {
            $setting = new linktitles_setting();
            $setting->network = $channel ? null : $network;
            $setting->channel = $channel;
        }

        $val = $input->getArgument("value");
        match ($input->getArgument("setting")) {
            "ai_vision_disabled" => $setting->ai_vision_disabled = filter_var($val, FILTER_VALIDATE_BOOLEAN),
        };

        $entityManager->persist($setting);
        $entityManager->flush();

        $this->showSettings($input, $output, $setting, $network, $channel);

        return Command::SUCCESS;
    }

    private function showSettings(InputInterface $input, OutputInterface $output, ?linktitles_setting $setting, Network $network, ?Channel $channel): void
    {
        $io = new SymfonyStyle($input, $output);
        $scope = $channel ? "network:{$network->name} channel:{$channel->name}" : "network:{$network->name}";
        $io->title("Linktitles settings ($scope)");

        $rows = [];
        foreach ($this->settings as $s) {
            $val = $setting?->$s ?? ($s === 'ai_vision_disabled' ? 'false' : '');
            $rows[] = [$s, is_bool($val) ? ($val ? 'true' : 'false') : (string)$val];
        }
        $io->table(["Setting", "Value"], $rows);
    }
}
```

- [ ] **Step 2: Verify syntax**

Run: `php -l cli_cmds/linktitles_set.php`
Expected: No syntax errors

- [ ] **Step 3: Commit**

```bash
git add cli_cmds/linktitles_set.php
git commit -m "feat: add linktitles:set CLI command for AI vision toggle"
```

---

### Task 4: Register command in admin-cli.php

**Files:**
- Modify: `admin-cli.php`

- [ ] **Step 1: Add the command registration**

Add after line 53 (`$application->add(new cli_cmds\showdb());`):

```php
$application->add(new cli_cmds\linktitles_set());
```

- [ ] **Step 2: Verify syntax**

Run: `php -l admin-cli.php`
Expected: No syntax errors

- [ ] **Step 3: Commit**

```bash
git add admin-cli.php
git commit -m "feat: register linktitles:set command in admin-cli"
```

---

### Task 5: Add disable check in linktitles.php

**Files:**
- Modify: `scripts/linktitles/linktitles.php`

This adds a method to check if AI vision is disabled for the current scope, and calls it in the image handler before invoking the AI.

- [ ] **Step 1: Add the isAiVisionDisabled method**

Add this private method in the `linktitles` class, right before `getAiDescription`:

```php
    private function isAiVisionDisabled(string $chan): bool
    {
        global $entityManager;
        $repo = $entityManager->getRepository(entities\linktitles_setting::class);

        $channelEntity = null;
        foreach ($this->bot->getChannels() as $ch) {
            if (strtolower($ch->name) === strtolower($chan)) {
                $channelEntity = $ch;
                break;
            }
        }

        if ($channelEntity !== null) {
            $setting = $repo->findOneBy(['channel' => $channelEntity]);
            if ($setting !== null && $setting->ai_vision_disabled) {
                return true;
            }
        }

        $setting = $repo->findOneBy([
            'network' => $this->network,
            'channel' => null,
        ]);
        if ($setting !== null && $setting->ai_vision_disabled) {
            return true;
        }

        return false;
    }
```

- [ ] **Step 2: Call the check in the image handler**

In the image handler block, find the line that calls `getAiDescription`:

```php
                    $aiDesc = $this->getAiDescription($body);
```

Replace with:

```php
                    $aiDesc = $this->isAiVisionDisabled($chan) ? null : $this->getAiDescription($body);
```

- [ ] **Step 3: Verify syntax**

Run: `php -l scripts/linktitles/linktitles.php`
Expected: No syntax errors

- [ ] **Step 4: Run tests**

Run: `composer test`
Expected: All existing tests pass (605 tests)

- [ ] **Step 5: Commit**

```bash
git add scripts/linktitles/linktitles.php
git commit -m "feat: check per-network/channel AI vision disable before API call"
```
