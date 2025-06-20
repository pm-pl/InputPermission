<?php
declare(strict_types=1);

namespace DavyCraft648\InputPermission;

use DavyCraft648\PMInputAPI\InputPermissionCategory;
use DavyCraft648\PMInputAPI\PMInputAPI;
use DavyCraft648\PMServerUI\ActionFormData;
use DavyCraft648\PMServerUI\ActionFormResponse;
use DavyCraft648\PMServerUI\ModalFormData;
use DavyCraft648\PMServerUI\ModalFormResponse;
use DavyCraft648\PMServerUI\PMServerUI;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\EventPriority;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\lang\Translatable;
use pocketmine\network\mcpe\protocol\AvailableCommandsPacket;
use pocketmine\network\mcpe\protocol\types\command\CommandEnum;
use pocketmine\network\mcpe\protocol\types\command\CommandOverload;
use pocketmine\network\mcpe\protocol\types\command\CommandParameter;
use pocketmine\permission\DefaultPermissions;
use pocketmine\permission\PermissionManager;
use pocketmine\permission\PermissionParser;
use pocketmine\permission\PermissionParserException;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\TextFormat;
use function array_key_first;
use function array_keys;
use function array_map;
use function array_search;
use function array_values;
use function count;
use function implode;
use function is_array;
use function strtolower;

class InputPermission extends PluginBase{

	/** @var InputPermissionCategory[] */
	private const PERMISSION_CATEGORIES = [
		"camera" => InputPermissionCategory::Camera,
		"movement" => InputPermissionCategory::Movement,
		"lateral_movement" => InputPermissionCategory::LateralMovement,
		"sneak" => InputPermissionCategory::Sneak,
		"jump" => InputPermissionCategory::Jump,
		"mount" => InputPermissionCategory::Mount,
		"dismount" => InputPermissionCategory::Dismount,
		"move_forward" => InputPermissionCategory::MoveForward,
		"move_backward" => InputPermissionCategory::MoveBackward,
		"move_left" => InputPermissionCategory::MoveLeft,
		"move_right" => InputPermissionCategory::MoveRight,
	];

	protected function onEnable(): void{
		PMServerUI::register($this, true);
		PMInputAPI::register($this);

		$this->reloadDefault();

		$this->getServer()->getCommandMap()->getCommand("inputpermission")->setDescription(new Translatable("commands.inputpermission.description"));

		$this->getServer()->getPluginManager()->registerEvent(PlayerJoinEvent::class, function(PlayerJoinEvent $event): void{
			$player = $event->getPlayer();
			$this->recheckInputPermissions($player);
			$player->getPermissionRecalculationCallbacks()->add(function() use ($player): void{
				$this->recheckInputPermissions($player);
			});
		}, EventPriority::LOWEST, $this);
		$this->getServer()->getPluginManager()->registerEvent(DataPacketSendEvent::class, function(DataPacketSendEvent $event): void{
			foreach($event->getPackets() as $packet){
				if($packet instanceof AvailableCommandsPacket && isset($packet->commandData["inputpermission"])){
					$player = $event->getTargets()[array_key_first($event->getTargets())]->getPlayer();
					$overloads = [];
					$query = new CommandEnum("Option_Query", ["query"]);
					$set = new CommandEnum("Option_Set", ["set"]);
					$permission = new CommandEnum("permission", array_keys(self::PERMISSION_CATEGORIES));
					$state = new CommandEnum("state", ["enabled", "disabled"]);
					if($player->hasPermission("inputpermission.command.query.self") || $player->hasPermission("inputpermission.command.query.other")){
						$overloads[] = new CommandOverload(chaining: false, parameters: [
							CommandParameter::enum("option", $query, 0),
							CommandParameter::standard("targets", AvailableCommandsPacket::ARG_TYPE_TARGET),
							CommandParameter::enum("permission", $permission, 0),
							CommandParameter::enum("state", $state, 0, true)
						]);
					}
					if($player->hasPermission("inputpermission.command.set.self") || $player->hasPermission("inputpermission.command.set.other")){
						$overloads[] = new CommandOverload(chaining: false, parameters: [
							CommandParameter::enum("option", $set, 0),
							CommandParameter::standard("targets", AvailableCommandsPacket::ARG_TYPE_TARGET),
							CommandParameter::enum("permission", $permission, 0),
							CommandParameter::enum("state", $state, 0)
						]);
					}
					$packet->commandData["inputpermission"]->overloads = $overloads;
				}
			}
		}, EventPriority::LOW, $this);
	}

	public function reloadDefault(): void{
		$this->reloadConfig();
		$config = $this->getConfig();
		$defaults = $config->get("default", []);
		if(!is_array($defaults) || $defaults === []){
			$this->getLogger()->notice("No default permissions found, creating new config...");
			$defaults = [];
		}
		foreach(self::PERMISSION_CATEGORIES as $perm => $_){
			$defaults[$perm] ??= PermissionParser::DEFAULT_TRUE;
		}

		foreach($defaults as $perm => $value){
			if(!isset(self::PERMISSION_CATEGORIES[$perm])){
				$this->getLogger()->warning("\"$perm\" is not a valid permission");
				unset($defaults[$perm]);
				continue;
			}
			try{
				$default = PermissionParser::defaultFromString($value);
			}catch(PermissionParserException $e){
				$this->getLogger()->warning("$perm has invalid default \"$value\"");
				$default = PermissionParser::DEFAULT_TRUE;
			}
			$this->updatePerm("inputpermission.permission.$perm", $default);
		}

		$config->set("default", $defaults);
		$config->save();
	}

	public function updatePerm(string $permName, string $default): void{
		$permManager = PermissionManager::getInstance();
		$opRoot = $permManager->getPermission(DefaultPermissions::ROOT_OPERATOR);
		$everyoneRoot = $permManager->getPermission(DefaultPermissions::ROOT_USER);
		switch($default){
			case PermissionParser::DEFAULT_TRUE:
				$everyoneRoot->addChild($permName, true);
				$opRoot->removeChild($permName);
				break;
			case PermissionParser::DEFAULT_OP:
				$everyoneRoot->removeChild($permName);
				$opRoot->addChild($permName, true);
				break;
			case PermissionParser::DEFAULT_NOT_OP:
				$everyoneRoot->addChild($permName, true);
				$opRoot->addChild($permName, false);
				break;
			default:
				$everyoneRoot->removeChild($permName);
				$opRoot->removeChild($permName);
				break;
		}
	}

	public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool{
		if(!$sender instanceof Player){
			return true;
		}

		switch($args[0] ?? ""){
			case "query":
				if(count($args) < 3){
					$this->showQueryForm($sender, $args[1] ?? null, $args[2] ?? null);
					return true;
				}
				$targetName = $args[1];
				$target = $this->getServer()->getPlayerExact($targetName);
				if($target === null){
					$this->showQueryForm($sender);
					return true;
				}
				if($target === $sender){
					if(!$sender->hasPermission("inputpermission.command.query.self")){
						$sender->sendMessage(TextFormat::RED . "You don't have permission to use this operation");
						return true;
					}
				}else{
					if(!$sender->hasPermission("inputpermission.command.query.other")){
						$sender->sendMessage(TextFormat::RED . "You don't have permission to use this operation");
						return true;
					}
				}
				$permission = self::PERMISSION_CATEGORIES[$rawPerm = strtolower($args[2])] ?? null;
				if($permission === null){
					$this->showQueryForm($sender, $targetName);
					return true;
				}
				$state = $args[3] ?? null;
				if($state !== null){
					$state = strtolower($state);
					if($state !== "disabled" && $state !== "enabled"){
						$this->showQueryForm($sender, $targetName, $rawPerm);
						return true;
					}
					$session = PMInputAPI::getInputManager()->getPlayer($target);
					$enabled = $session->inputPermissions->isPermissionCategoryEnabled($permission);
					if($state === "enabled"){
						//pm doesn't support nested translations :(
						//$sender->sendMessage(new Translatable("commands.inputpermission.query", [new Translatable("commands.inputpermission.$rawPerm"), $enabled ? 1 : 0, new Translatable("commands.inputpermission.enabled")]));
						$sender->sendMessage(new Translatable("commands.inputpermission.query", [$permission->name, $enabled ? 1 : 0, "enabled"]));
					}else{
						//$sender->sendMessage(new Translatable("commands.inputpermission.query", [new Translatable("commands.inputpermission.$rawPerm"), $enabled ? 0 : 1, new Translatable("commands.inputpermission.disabled")]));
						$sender->sendMessage(new Translatable("commands.inputpermission.query", [$permission->name, $enabled ? 0 : 1, "disabled"]));
					}
				}else{
					$session = PMInputAPI::getInputManager()->getPlayer($target);
					$enabled = $session->inputPermissions->isPermissionCategoryEnabled($permission);
					//$sender->sendMessage(new Translatable("commands.inputpermission.queryverbose", [new Translatable("commands.inputpermission.$rawPerm"), $enabled ? 1 : 0, $enabled ? 0 : 1]));
					$sender->sendMessage(new Translatable("commands.inputpermission.queryverbose", [$permission->name, $enabled ? 1 : 0, $enabled ? 0 : 1]));
				}
				break;
			case "set":
				if(count($args) < 4){
					$this->showSetForm($sender, $args[1] ?? null, $args[2] ?? null);
					return true;
				}
				$targetName = $args[1];
				$target = $this->getServer()->getPlayerExact($targetName);
				if($target === null){
					$this->showSetForm($sender);
					return true;
				}
				if($target === $sender){
					if(!$sender->hasPermission("inputpermission.command.set.self")){
						$sender->sendMessage(TextFormat::RED . "You don't have permission to use this operation");
						return true;
					}
				}else{
					if(!$sender->hasPermission("inputpermission.command.set.other")){
						$sender->sendMessage(TextFormat::RED . "You don't have permission to use this operation");
						return true;
					}
				}
				$permission = self::PERMISSION_CATEGORIES[$rawPerm = strtolower($args[2])] ?? null;
				if($permission === null){
					$this->showSetForm($sender, $targetName);
					return true;
				}
				$state = strtolower($args[3]);
				if($state !== "disabled" && $state !== "enabled"){
					$this->showSetForm($sender, $targetName, $rawPerm);
					return true;
				}

				$session = PMInputAPI::getInputManager()->getPlayer($target);
				$session->inputPermissions->setPermissionCategory($permission, $state === "enabled");
				//$sender->sendMessage(new Translatable("commands.inputpermission.set.outputoneplayer", [new Translatable("commands.inputpermission.$rawPerm"), new Translatable("commands.inputpermission.$state"), $targetName]));
				$sender->sendMessage(new Translatable("commands.inputpermission.set.outputoneplayer", [$permission->name, $state, $targetName]));
				break;
			default:
				$this->showInputPermissionForm($sender);
		}
		return true;
	}

	public function showInputPermissionForm(Player $player): void{
		$form = ActionFormData::create()->title("Input Permission")->body(new Translatable("commands.inputpermission.description"));
		$actions = [];
		if($player->hasPermission("inputpermission.command.query.self") || $player->hasPermission("inputpermission.command.query.other")){
			$actions[] = "query";
			$form->button("query");
		}
		if($player->hasPermission("inputpermission.command.set.self") || $player->hasPermission("inputpermission.command.set.other")){
			$actions[] = "set";
			$form->button("set");
		}
		if($player->hasPermission("inputpermission.command.admin")){
			$actions[] = "admin";
			$form->divider();
			$form->button("Plugin Config");
		}
		if($actions === []){
			$form->divider();
			$form->label("You do not have sufficient permissions :(");
		}
		$form->show($player)->then(function(Player $player, ActionFormResponse $response) use ($actions): void{
			if($response->selection === null){
				return;
			}
			switch($actions[$response->selection] ?? ""){
				case "query":
					$this->showQueryForm($player);
					break;
				case "set":
					$this->showSetForm($player);
					break;
				case "admin":
					$config = $this->getConfig();
					ModalFormData::create()
						->title("Plugin Config")
						->label("Default input permission for players")
						->divider()
						->header("Permissions:")
						->label("- false : Permission is not granted by default\n- true : Permission is granted by default to everyone\n- op : Permission is granted by default only to operators\n- notop : Permission is granted by default to all players who are NOT operators")
						->textField(new Translatable("commands.inputpermission.camera"), $config->getNested("default.camera"), $config->getNested("default.camera"), "Player input relating to camera movement.")
						->textField(new Translatable("commands.inputpermission.movement"), $config->getNested("default.movement"), $config->getNested("default.movement"), "Player input relating to all player movement. Disabling this is equivalent to disabling jump, sneak, lateral movement, mount, and dismount.")
						->textField(new Translatable("commands.inputpermission.lateral_movement"), $config->getNested("default.lateral_movement"), $config->getNested("default.lateral_movement"), "Player input for moving laterally in the world. This would be WASD on a keyboard or the movement joystick on gamepad or touch.")
						->textField(new Translatable("commands.inputpermission.sneak"), $config->getNested("default.sneak"), $config->getNested("default.sneak"), "Player input relating to sneak. This also affects flying down.")
						->textField(new Translatable("commands.inputpermission.jump"), $config->getNested("default.jump"), $config->getNested("default.jump"), "Player input relating to jumping. This also affects flying up.")
						->textField(new Translatable("commands.inputpermission.mount"), $config->getNested("default.mount"), $config->getNested("default.mount"), "Player input relating to mounting vehicles.")
						->textField(new Translatable("commands.inputpermission.dismount"), $config->getNested("default.dismount"), $config->getNested("default.dismount"), "Player input relating to dismounting. When disabled, the player can still dismount vehicles by other means, for example on horses players can still jump off and in boats players can go into another boat.")
						->textField(new Translatable("commands.inputpermission.move_forward"), $config->getNested("default.move_forward"), $config->getNested("default.move_forward"), "Player input relating to moving the player forward.")
						->textField(new Translatable("commands.inputpermission.move_backward"), $config->getNested("default.move_backward"), $config->getNested("default.move_backward"), "Player input relating to moving the player backward.")
						->textField(new Translatable("commands.inputpermission.move_left"), $config->getNested("default.move_left"), $config->getNested("default.move_left"), "Player input relating to moving the player left.")
						->textField(new Translatable("commands.inputpermission.move_right"), $config->getNested("default.move_right"), $config->getNested("default.move_right"), "Player input relating to moving the player right.")
						->submitButton(new Translatable("controllerLayoutScreen.save"))
						->show($player)->then(function(Player $player, ModalFormResponse $response): void{
							if($response->formValues === null){
								$this->showInputPermissionForm($player);
								return;
							}
							$config = $this->getConfig();
							$permissions = array_keys(self::PERMISSION_CATEGORIES);
							$updated = [];
							foreach($response->formValues as $key => $value){
								if($key < 4){
									continue;
								}
								$perm = $permissions[$key - 4];
								try{
									$default = PermissionParser::defaultFromString($value);
								}catch(PermissionParserException $e){
									$player->sendMessage("$perm has invalid default \"$value\"");
									continue;
								}
								if($config->getNested("default.$perm") !== $default){
									$config->setNested("default.$perm", $default);
									$this->updatePerm("inputpermission.permission.$perm", $default);
									$updated[] = "$perm ($default)";
								}
							}
							if($config->hasChanged()){
								$player->sendMessage("Configuration updated: " . implode(", ", $updated));
								$config->save();
							}else{
								$player->sendMessage("No changes have been made.");
							}
						})->catch(function(\Throwable $throwable): void{
							$this->getLogger()->logException($throwable);
						});
					break;
				default:
					throw new \RuntimeException("Must have been checked by PMServerUI");
			}
		})->catch(function(\Throwable $throwable): void{
			$this->getLogger()->logException($throwable);
		});
	}

	public function showQueryForm(Player $player, ?string $targetName = null, ?string $rawPerm = null): void{
		$players = array_values(array_map(fn(Player $p): string => $p->getName(), $this->getServer()->getOnlinePlayers()));
		$playerIndex = $targetName !== null ? (int)array_search($targetName, $players) : null;
		$permissions = array_keys(self::PERMISSION_CATEGORIES);
		$tlPerm = array_map(fn(string $p): Translatable => new Translatable("commands.inputpermission.$p"), $permissions);
		$permIndex = $rawPerm !== null ? (int)array_search($rawPerm, $permissions) : null;
		ModalFormData::create()
			->title("Input Permission Query")
			->label("Queries the status of the specified privilege of the target.")
			->divider()
			->dropdown("Player", $players, $playerIndex, "Specifies the owner of the permission.")
			->dropdown("Permission", $tlPerm, $permIndex, "Specifies the authority for the operation.")
			->toggle("State", true, "The status of the specified permission.")
			->show($player)->then(function(Player $player, ModalFormResponse $response) use ($permissions, $players): void{
				if($response->formValues === null){
					$this->showInputPermissionForm($player);
					return;
				}
				$targetName = $players[$response->formValues[2]];
				$rawPerm = $permissions[$response->formValues[3]];
				$state = $response->formValues[4];

				$target = $this->getServer()->getPlayerExact($targetName);
				if($target === null){
					return;
				}
				if($target === $player){
					if(!$player->hasPermission("inputpermission.command.query.self")){
						$player->sendMessage(TextFormat::RED . "You don't have permission to use this operation");
						return;
					}
				}else{
					if(!$player->hasPermission("inputpermission.command.query.other")){
						$player->sendMessage(TextFormat::RED . "You don't have permission to use this operation");
						return;
					}
				}
				$permission = self::PERMISSION_CATEGORIES[$rawPerm];
				$session = PMInputAPI::getInputManager()->getPlayer($target);
				$enabled = $session->inputPermissions->isPermissionCategoryEnabled($permission);
				if($state){
					ActionFormData::create()
						->title("Input Permission Query")
						->body(new Translatable("commands.inputpermission.query", [new Translatable("commands.inputpermission.$rawPerm"), $enabled ? 1 : 0, new Translatable("commands.inputpermission.enabled")]))
						->button(new Translatable("gui.back"))
						->show($player)->then(function(Player $player, ActionFormResponse $response): void{
							if($response->selection === 0){
								$this->showQueryForm($player);
							}
						})->catch(function(\Throwable $throwable): void{
							$this->getLogger()->logException($throwable);
						});
				}else{
					ActionFormData::create()->title("Input Permission Query")
						->body(new Translatable("commands.inputpermission.query", [new Translatable("commands.inputpermission.$rawPerm"), $enabled ? 0 : 1, new Translatable("commands.inputpermission.disabled")]))
						->button(new Translatable("gui.back"))
						->show($player)->then(function(Player $player, ActionFormResponse $response): void{
							if($response->selection === 0){
								$this->showQueryForm($player);
							}
						})->catch(function(\Throwable $throwable): void{
							$this->getLogger()->logException($throwable);
						});
				}
			})->catch(function(\Throwable $throwable): void{
				$this->getLogger()->logException($throwable);
			});
	}

	public function showSetForm(Player $player, ?string $targetName = null, ?string $rawPerm = null): void{
		$players = array_values(array_map(fn(Player $p): string => $p->getName(), $this->getServer()->getOnlinePlayers()));
		$playerIndex = $targetName !== null ? (int)array_search($targetName, $players) : null;
		$permissions = array_keys(self::PERMISSION_CATEGORIES);
		$tlPerm = array_map(fn(string $p): Translatable => new Translatable("commands.inputpermission.$p"), $permissions);
		$permIndex = $rawPerm !== null ? (int)array_search($rawPerm, $permissions) : null;
		ModalFormData::create()
			->title("Input Permission Set")
			->label("Modifies the status of the specified privilege of the target.")
			->divider()
			->dropdown("Player", $players, $playerIndex, "Specifies the owner of the permission.")
			->dropdown("Permission", $tlPerm, $permIndex, "Specifies the authority for the operation.")
			->toggle("State", true, "The status of the specified permission.")
			->show($player)->then(function(Player $player, ModalFormResponse $response) use ($permissions, $players): void{
				if($response->formValues === null){
					$this->showInputPermissionForm($player);
					return;
				}
				$targetName = $players[$response->formValues[2]];
				$target = $this->getServer()->getPlayerExact($targetName);
				if($target === null){
					return;
				}
				if($target === $player){
					if(!$player->hasPermission("inputpermission.command.set.self")){
						$player->sendMessage(TextFormat::RED . "You don't have permission to use this operation");
						return;
					}
				}else{
					if(!$player->hasPermission("inputpermission.command.set.other")){
						$player->sendMessage(TextFormat::RED . "You don't have permission to use this operation");
						return;
					}
				}
				$permission = self::PERMISSION_CATEGORIES[$rawPerm = $permissions[$response->formValues[3]]];
				$state = $response->formValues[4];

				$session = PMInputAPI::getInputManager()->getPlayer($target);
				$session->inputPermissions->setPermissionCategory($permission, $state);
				ActionFormData::create()
					->title("Input Permission Set")
					->body(new Translatable("commands.inputpermission.set.outputoneplayer", [new Translatable("commands.inputpermission.$rawPerm"), new Translatable("commands.inputpermission." . ($state ? "enabled" : "disabled")), $targetName]))
					->button(new Translatable("gui.back"))
					->show($player)->then(function(Player $player, ActionFormResponse $response): void{
						if($response->selection === 0){
							$this->showSetForm($player);
						}
					})->catch(function(\Throwable $throwable): void{
						$this->getLogger()->logException($throwable);
					});
			})->catch(function(\Throwable $throwable): void{
				$this->getLogger()->logException($throwable);
			});
	}

	public function recheckInputPermissions(Player $player): void{
		$session = PMInputAPI::getInputManager()->getPlayer($player);
		foreach(self::PERMISSION_CATEGORIES as $rawPerm => $permission){
			if($player->hasPermission("inputpermission.permission.$rawPerm")){
				$session->inputPermissions->setPermissionCategory($permission, true);
			}else{
				$session->inputPermissions->setPermissionCategory($permission, false);
			}
		}
	}
}