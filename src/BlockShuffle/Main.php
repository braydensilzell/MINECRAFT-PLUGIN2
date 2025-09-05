<?php
declare(strict_types=1);

namespace BlockShuffle;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\scheduler\ClosureTask;
use pocketmine\utils\TextFormat as C;
use pocketmine\block\BlockTypeIds;

class Main extends PluginBase implements Listener {

    private bool $running = false;
    /** @var array<string, int[]> playerName => list of 3 BlockTypeIds */
    private array $assigned = [];
    /** @var array<string, bool> */
    private array $completed = [];
    /** @var int|null */
    private ?int $taskHandler = null;
    private int $roundSeconds = 300;

    /** @var \xenialdan\apibossbar\BossBar|null */
    private $bossBar = null;

    public function onEnable() : void {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        if (class_exists(\xenialdan\apibossbar\BossBar::class)) {
            $this->bossBar = new \xenialdan\apibossbar\BossBar();
            $this->bossBar->setTitle("Find one of your blocks!");
            $this->bossBar->setPercentage(1.0);
        }
    }

    public function onDisable() : void {
        $this->stopGame(false);
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool {
        switch (strtolower($command->getName())) {
            case "blockshufflestart":
                if ($this->running) {
                    $sender->sendMessage(C::RED . "BlockShuffle is already running.");
                    return true;
                }
                $this->startGame();
                return true;
            case "blockshufflestop":
                if (!$this->running) {
                    $sender->sendMessage(C::RED . "BlockShuffle is not running.");
                    return true;
                }
                $this->stopGame(true);
                return true;
        }
        return false;
    }

    private function startGame() : void {
        $this->running = true;
        $this->getServer()->broadcastMessage(C::GREEN . "BlockShuffle has started!");
        if ($this->bossBar !== null) {
            $this->bossBar->removeAllPlayers();
            foreach ($this->getServer()->getOnlinePlayers() as $p) {
                $this->bossBar->addPlayer($p);
            }
        }
        $this->nextRound();
    }

    private function stopGame(bool $announce) : void {
        $this->running = false;
        $this->assigned = [];
        $this->completed = [];
        if ($this->taskHandler !== null) {
            $this->getScheduler()->cancelTask($this->taskHandler);
            $this->taskHandler = null;
        }
        if ($this->bossBar !== null) {
            $this->bossBar->removeAllPlayers();
        }
        if ($announce) {
            $this->getServer()->broadcastMessage(C::YELLOW . "BlockShuffle has ended.");
        }
    }

    private function nextRound() : void {
        $players = [];
        foreach ($this->getServer()->getOnlinePlayers() as $p) {
            if ($p->isSpectator()) continue;
            $players[] = $p;
        }

        if (count($players) <= 1) {
            $winner = count($players) === 1 ? $players[0]->getName() : "No one";
            $this->getServer()->broadcastMessage(C::GOLD . "Game Over! " . $winner . " wins!");
            $this->stopGame(true);
            return;
        }

        $pool = [
            BlockTypeIds::STONE,
            BlockTypeIds::DIRT,
            BlockTypeIds::COBBLESTONE,
            BlockTypeIds::SAND,
            BlockTypeIds::GRAVEL,
            BlockTypeIds::BRICKS,
            BlockTypeIds::SANDSTONE,
            BlockTypeIds::CLAY,
            BlockTypeIds::BIRCH_PLANKS,
            BlockTypeIds::SPRUCE_PLANKS,
            BlockTypeIds::ACACIA_PLANKS,
            BlockTypeIds::DARK_OAK_PLANKS,
            BlockTypeIds::CHERRY_PLANKS,
            BlockTypeIds::CRIMSON_PLANKS,
            BlockTypeIds::WARPED_PLANKS,
            BlockTypeIds::ANDESITE,
            BlockTypeIds::DIORITE,
            BlockTypeIds::TUFF,
            BlockTypeIds::DEEPSLATE,
            BlockTypeIds::STONE_BRICKS,
            BlockTypeIds::COBBLESTONE_STAIRS,
            BlockTypeIds::STONE_STAIRS
        ];
        shuffle($pool);

        $this->assigned = [];
        $this->completed = [];

        $need = count($players) * 3;
        $unique = count($pool) >= $need;
        $i = 0;

        foreach ($players as $p) {
            $trio = [];
            for ($k = 0; $k < 3; $k++) {
                if ($unique) {
                    $trio[] = $pool[$i++];
                } else {
                    $trio[] = $pool[array_rand($pool)];
                }
            }
            $this->assigned[$p->getName()] = $trio;
            $this->completed[$p->getName()] = false;

            $names = array_map(function(int $t): string {
                static $map = [
                    BlockTypeIds::STONE => "STONE",
                    BlockTypeIds::DIRT => "DIRT",
                    BlockTypeIds::COBBLESTONE => "COBBLESTONE",
                    BlockTypeIds::SAND => "SAND",
                    BlockTypeIds::GRAVEL => "GRAVEL",
                    BlockTypeIds::BRICKS => "BRICKS",
                    BlockTypeIds::SANDSTONE => "SANDSTONE",
                    BlockTypeIds::CLAY => "CLAY",
                    BlockTypeIds::BIRCH_PLANKS => "BIRCH_PLANKS",
                    BlockTypeIds::SPRUCE_PLANKS => "SPRUCE_PLANKS",
                    BlockTypeIds::ACACIA_PLANKS => "ACACIA_PLANKS",
                    BlockTypeIds::DARK_OAK_PLANKS => "DARK_OAK_PLANKS",
                    BlockTypeIds::CHERRY_PLANKS => "CHERRY_PLANKS",
                    BlockTypeIds::CRIMSON_PLANKS => "CRIMSON_PLANKS",
                    BlockTypeIds::WARPED_PLANKS => "WARPED_PLANKS",
                    BlockTypeIds::ANDESITE => "ANDESITE",
                    BlockTypeIds::DIORITE => "DIORITE",
                    BlockTypeIds::TUFF => "TUFF",
                    BlockTypeIds::DEEPSLATE => "DEEPSLATE",
                    BlockTypeIds::STONE_BRICKS => "STONE_BRICKS",
                    BlockTypeIds::COBBLESTONE_STAIRS => "COBBLESTONE_STAIRS",
                    BlockTypeIds::STONE_STAIRS => "STONE_STAIRS"
                ];
                return $map[$t] ?? (string)$t;
            }, $trio);

            $p->sendMessage(C::AQUA . "Your blocks: " . C::YELLOW . implode(C::AQUA . " | " . C::YELLOW, $names));
        }

        $this->startTimer();
    }

    private function startTimer() : void {
        if ($this->taskHandler !== null) {
            $this->getScheduler()->cancelTask($this->taskHandler);
            $this->taskHandler = null;
        }

        $timeLeft = $this->roundSeconds;
        if ($this->bossBar !== null) {
            $this->bossBar->setTitle("Time Left: {$timeLeft}s");
            $this->bossBar->setPercentage(1.0);
        }

        $this->taskHandler = $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function() use (&$timeLeft) : void {
            if (!$this->running) {
                $this->getScheduler()->cancelTask($this->taskHandler);
                $this->taskHandler = null;
                return;
            }

            if ($this->bossBar !== null) {
                $this->bossBar->setTitle("Time Left: {$timeLeft}s");
                $this->bossBar->setPercentage(max(0.0, $timeLeft / $this->roundSeconds));
            } else {
                foreach ($this->getServer()->getOnlinePlayers() as $p) {
                    $p->sendTip(C::BOLD . "Time Left: " . $timeLeft . "s");
                }
            }

            foreach ($this->getServer()->getOnlinePlayers() as $p) {
                $name = $p->getName();
                if (!isset($this->assigned[$name]) || ($this->completed[$name] ?? false)) continue;

                $block = $p->getWorld()->getBlock($p->getPosition()->floor()->add(0, -1, 0));
                if (in_array($block->getTypeId(), $this->assigned[$name], true)) {
                    $this->completed[$name] = true;
                    $this->getServer()->broadcastMessage(C::GREEN . $name . " found one of their blocks!");
                    $p->sendMessage(C::GREEN . "Round complete! You stepped on your block.");
                }
            }

            $all = array_keys($this->assigned);
            $done = array_keys(array_filter($this->completed, fn($v) => $v === true));
            if (count($all) > 0 && count($done) === count($all)) {
                $this->getServer()->broadcastMessage(C::LIGHT_PURPLE . "All players completed the round! Next round...");
                $this->getScheduler()->cancelTask($this->taskHandler);
                $this->taskHandler = null;
                $this->nextRound();
                return;
            }

            $timeLeft--;
            if ($timeLeft < 0) {
                foreach ($this->getServer()->getOnlinePlayers() as $p) {
                    $name = $p->getName();
                    if (!isset($this->assigned[$name])) continue;
                    if (!($this->completed[$name] ?? false)) {
                        $p->setSpectator();
                        $p->sendMessage(C::RED . "You lost. Now spectating.");
                        $this->getServer()->broadcastMessage(C::RED . $name . " failed to find a block and is now spectating.");
                    }
                }
                $this->getScheduler()->cancelTask($this->taskHandler);
                $this->taskHandler = null;
                $this->nextRound();
            }
        }), 20)->getTaskId();
    }
}
