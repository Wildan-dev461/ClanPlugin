<?php

namespace Will;

use pocketmine\command\Command;

use pocketmine\command\CommandSender;

use pocketmine\player\Player;

use pocketmine\plugin\PluginBase;

use pocketmine\utils\Config;

use pocketmine\utils\TextFormat as TF;

use onebone\economyapi\EconomyAPI;

use pocketmine\Server;

use _64FF00\PureChat\PureChat;

class Clan extends PluginBase {

    private $clans = [];

    private $data;

    

    public ?PureChat $pureChat;

    public function onEnable(): void {

        $this->getLogger()->info("ClansPlugin enabled.");

        $this->pureChat = $this->getServer()->getPluginManager()->getPlugin("PureChat");

        $this->getServer()->getPluginManager()->registerEvents(new EventListener(), $this);

        $this->data = new Config($this->getDataFolder() . "clans.json", Config::JSON);

        // Load existing clan data

        if ($this->data->exists("clans")) {

            $this->clans = $this->data->get("clans");

        }

        // Register EconomyAPI as a dependency

        if ($this->getServer()->getPluginManager()->getPlugin("EconomyAPI") === null) {

            $this->getLogger()->error("EconomyAPI is not installed.");

            $this->getServer()->getPluginManager()->disablePlugin($this);

        }

    }

    public function onDisable(): void {

        $this->getLogger()->info("ClansPlugin disabled.");

        // Save clan data

        $this->data->set("clans", $this->clans);

        $this->data->save();

    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {

        if (!$sender instanceof Player) {

            $sender->sendMessage(TF::RED . "This command can only be used in-game.");

            return true;

        }

        switch ($command->getName()) {

            case "createclan":

                // Check permission

                if (!$sender->hasPermission("clans.create")) {

                    $sender->sendMessage(TF::RED . "You don't have permission to create a clan.");

                    return true;

                }

                if (isset($args[0])) {

                    $clanName = ($args[0]);

                    // Check if the clan already exists

                    if (isset($this->clans[$clanName])) {

                        $sender->sendMessage(TF::RED . "A clan with that name already exists.");

                        return true;

                    }

                    // Check if the player has enough money

                    $economy = $this->getServer()->getPluginManager()->getPlugin("EconomyAPI");

                    if ($economy->myMoney($sender) < 1000) {

                        $sender->sendMessage(TF::RED . "You don't have enough money to create a clan.");

                        return true;

                    }

                    // Deduct the money from the player

                    $economy->reduceMoney($sender, 1000);

                    // Create the clan

                    $this->clans[$clanName] = [

                        "leader" => $sender->getName(),

                        "members" => [$sender->getName()],

                        "created_at" => date("Y-m-d H:i:s")

                    ];

                    $sender->sendMessage(TF::GREEN . "Clan created successfully.");

                } else {

                    $sender->sendMessage(TF::RED . "Usage: /createclan <clanname>");

                }

                return true;

            case "invite":

                // Check permission

                if (!$sender->hasPermission("clans.invite")) {

                    $sender->sendMessage(TF::RED . "You don't have permission to invite players to your clan.");

                    return true;

                }

                if (isset($args[0])) {

                    $invitedPlayer = $this->getServer()->getPlayerExact($args[0]);

                    if ($invitedPlayer instanceof Player) {

                        // Get the clan of the inviting player

                        $clan = $this->getPlayerClan($sender);

                        if ($clan !== null) {

                            // Check if the inviting player is the clan leader

                            if ($this->isClanLeader($sender, $clan)) {

                                // Check if the invited player is already a member of the clan

                                if (in_array($invitedPlayer->getName(), $this->clans[$clan]["members"])) {

                                    $sender->sendMessage(TF::RED . $invitedPlayer->getName() . " is already a member of your clan.");

                                    return true;

                                }

                                // Send invitation to the invited player

                                $invitedPlayer->sendMessage(TF::YELLOW . "You have been invited to join the clan " . $clan . ".");

                                $invitedPlayer->sendMessage(TF::YELLOW . "Type /acceptinvite to accept the invitation.");

                                // Store the invitation data

                                $this->clans[$clan]["invitations"][$invitedPlayer->getName()] = $sender->getName();

                                $sender->sendMessage(TF::GREEN . "Invitation sent to " . $invitedPlayer->getName() . ".");

                            } else {

                                $sender->sendMessage(TF::RED . "Only the clan leader can invite players.");

                            }

                        } else {

                            $sender->sendMessage(TF::RED . "You are not in a clan.");

                        }

                    } else {

                        $sender->sendMessage(TF::RED . "Player not found.");

                    }

                } else {

                    $sender->sendMessage(TF::RED . "Usage: /invite <player>");

                }

                return true;

            case "acceptinvite":

                // Check if the player has any pending invitations

                if (isset($this->clans[$sender->getName()])) {

                    $clanName = $this->clans[$sender->getName()];

                    // Check if the clan still exists

                    if (isset($this->clans[$clanName])) {

                        // Add the player to the clan

                        $this->clans[$clanName]["members"][] = $sender->getName();

                        // Remove the invitation data

                        unset($this->clans[$sender->getName()]);

                        $sender->sendMessage(TF::GREEN . "You have joined the clan " . $clanName . ".");

                    } else {

                        unset($this->clans[$sender->getName()]);

                        $sender->sendMessage(TF::RED . "The clan that invited you no longer exists.");

                    }

                } else {

                    $sender->sendMessage(TF::RED . "You don't have any pending clan invitations.");

                }

                return true;

            case "leaveclan":

                // Check permission

                if (!$sender->hasPermission("clans.leave")) {

                    $sender->sendMessage(TF::RED . "You don't have permission to leave a clan.");

                    return true;

                }

                // Check if the player is the leader of their clan

                $clan = $this->getPlayerClan($sender);

                if ($clan !== null && $this->isClanLeader($sender, $clan)) {

                    $sender->sendMessage(TF::RED . "You can't leave the clan as the leader. Use /disbandclan instead.");

                    return true;

                }

                // Check if the player is in a clan

                if ($clan !== null) {

                    $this->removePlayerFromClan($sender, $clan);

                    $sender->sendMessage(TF::GREEN . "You left the clan.");

                } else {

                    $sender->sendMessage(TF::RED . "You are not in a clan.");

                }

                return true;

            case "disbandclan":

                // Check permission

                if (!$sender->hasPermission("clans.disband")) {

                    $sender->sendMessage(TF::RED . "You don't have permission to disband a clan.");

                    return true;

                }

                // Check if the player is the leader of their clan

                $clan = $this->getPlayerClan($sender);

                if ($clan !== null && $this->isClanLeader($sender, $clan)) {

                    $this->disbandClan($clan);

                    $sender->sendMessage(TF::GREEN . "Your clan has been disbanded.");

                } else {

                    $sender->sendMessage(TF::RED . "You are not the leader of a clan.");

                }

                return true;

            case "listclans":

                // Check permission

                if (!$sender->hasPermission("clans.list")) {

                    $sender->sendMessage(TF::RED . "You don't have permission to list all clans.");

                    return true;

                }

                $sender->sendMessage(TF::YELLOW . "All Available Clans: " . implode(", ", array_keys($this->clans)));

                return true;

            case "claninfo":

                // Check permission

                if (!$sender->hasPermission("clans.info")) {

                    $sender->sendMessage(TF::RED . "You don't have permission to view clan info.");

                    return true;

                }

                // Check if the player is in a clan

                $clan = $this->getPlayerClan($sender);

                if ($clan !== null) {

                    $sender->sendMessage(TF::YELLOW . "Clan Information");

                    $sender->sendMessage(TF::YELLOW . "----------------");

                    $sender->sendMessage(TF::YELLOW . "Name: " . $clan);

                    $sender->sendMessage(TF::YELLOW . "Leader: " . $this->clans[$clan]["leader"]);

                    $sender->sendMessage(TF::YELLOW . "Members: " . implode(", ", $this->clans[$clan]["members"]));

                    $sender->sendMessage(TF::YELLOW . "Created At: " . $this->clans[$clan]["created_at"]);

                } else {

                    $sender->sendMessage(TF::RED . "You are not in a clan.");

                }

                return true;

        }

        return false;

    }

    public function getPlayerClan(Player $player): string{

        $playerName = $player->getName();

        foreach ($this->clans as $clanName => $clanData) {

            if (in_array($playerName, $clanData["members"])) {

                return $clanName;

            }

        }

        return "[N\A]";

    }

    private function isClanLeader(Player $player, string $clanName): bool {

        return $this->clans[$clanName]["leader"] === $player->getName();

    }

    private function removePlayerFromClan(Player $player, string $clanName): void {

        unset($this->clans[$clanName]["members"][array_search($player->getName(), $this->clans[$clanName]["members"])]);

    }

    private function disbandClan(string $clanName): void {

        unset($this->clans[$clanName]);

    }

}

