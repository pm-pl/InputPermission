# InputPermission

https://youtu.be/7pi1HXQzUpM

InputPermission is a PocketMine-MP plugin that implements the functionality of the `/inputpermission` command from
Minecraft Bedrock Edition into your server. This plugin allows server administrators to control whether a player's input
can affect their character in the game, providing granular control over various aspects of player movement and
interaction.

With this plugin, you can control specific inputs like jumping, sneaking, moving, or even camera interaction.

## Key Features

* **`/inputpermission` Command:** Full functionality of the Minecraft Bedrock Edition `/inputpermission` command is
  implemented.
* **Granular Control:** Allow or restrict specific inputs through various permission categories:
    * `camera`: Controls player input relating to camera movement.
    * `movement`: Controls all player movement. Disabling this is equivalent to disabling `jump`, `sneak`,
      `lateral_movement`, `mount`, and `dismount`.
    * `lateral_movement`: Controls player input for moving laterally in the world. This would be WASD on a keyboard or
      the movement joystick on gamepad or touch.
    * `sneak`: Controls player input relating to sneak. This also affects flying down.
    * `jump`: Controls player input relating to jumping. This also affects flying up.
    * `mount`: Controls player input relating to mounting vehicles.
    * `dismount`: Controls player input relating to dismounting.
    * `move_forward`: Controls player input relating to moving the player forward.
    * `move_backward`: Controls player input relating to moving the player backward.
    * `move_left`: Controls player input relating to moving the player left.
    * `move_right`: Controls player input relating to moving the player right.
* **Flexible Permission System:** Control who can use the commands and manage permissions with a comprehensive
  permission system.
* **Form UI Support:** The plugin provides an intuitive Form UI interface for managing input permissions, making it
  easier for administrators unfamiliar with console commands.

## Commands

This plugin adds the `/inputpermission` command to your server.

**Syntax:**
`/inputpermission <option> [target] [permission] [state]`

* `<option>`: The operation to perform (`query` or `set`).
* `[target]`: The name of the player you wish to affect.
* `[permission]`: The input permission category you wish to configure (e.g., `jump`, `movement`, `camera`).
* `[state]`: The state you wish to set (`enabled` or `disabled`). Only relevant for the `set` option.

**Usage Examples:**

* `/inputpermission query DavyCraft648 movement`: Queries the `movement` permission status for player DavyCraft648.
* `/inputpermission set DavyCraft648 jump disabled`: Disables the jumping ability for player DavyCraft648.

## Permissions

The plugin provides the following permissions for granular control:

**Command Permissions:**

* `inputpermission.command` (default: `op`): Allows basic usage of the `/inputpermission` command.
* `inputpermission.command.query.self` (default: `op`): Allows players to query their own input permissions.
* `inputpermission.command.query.other` (default: `op`): Allows players to query other players' input permissions.
* `inputpermission.command.set.self` (default: `op`): Allows players to set their own input permissions.
* `inputpermission.command.set.other` (default: `op`): Allows players to set other players' input permissions.
* `inputpermission.command.admin` (default: `op`): Grants full access to all `inputpermission` command functionalities,
  including plugin configuration.

**Input Category Permissions:**

Each input category has an associated permission that can be managed by external permission plugins to control default
player input. If a player **does not have** this permission, the related input will be **disabled**. By default, all
these input permissions are set to `true` for all players.

* `inputpermission.permission.camera` (default: `true`)
* `inputpermission.permission.dismount` (default: `true`)
* `inputpermission.permission.jump` (default: `true`)
* `inputpermission.permission.lateral_movement` (default: `true`)
* `inputpermission.permission.mount` (default: `true`)
* `inputpermission.permission.move_backward` (default: `true`)
* `inputpermission.permission.move_forward` (default: `true`)
* `inputpermission.permission.move_left` (default: `true`)
* `inputpermission.permission.move_right` (default: `true`)
* `inputpermission.permission.movement` (default: `true`)
* `inputpermission.permission.sneak` (default: `true`)

**Permission Mechanism:**
The plugin actively checks for these input category permissions for each player. If a player has the
`inputpermission.permission.<category>` permission, then the input for that category is enabled. Otherwise, the input is
disabled. This means you can use external permission plugins to grant or revoke these permissions from specific groups
or players, and the InputPermission plugin will automatically apply those changes.

## Configuration

The plugin has a `config.yml` file that allows you to set the default status for each input permission category. This is
useful for defining whether a certain permission should be enabled by default for all players or only for operators,
etc.

Example `config.yml`:

```yaml
# Default input permission for players
default:
  camera: "true" # Permission is granted by default to everyone
  movement: "true"
  lateral_movement: "true"
  sneak: "true"
  jump: "true"
  mount: "true"
  dismount: "true"
  move_forward: "true"
  move_backward: "true"
  move_left: "true"
  move_right: "true"
```

**Default Value Options:**

* `true`: Permission is granted by default to everyone.
* `false`: Permission is not granted by default.
* `op`: Permission is granted by default only to operators.
* `notop`: Permission is granted by default to all players who are NOT operators.

You can modify these values directly in `config.yml` or via the "Plugin Config" Form UI accessible with the
`/inputpermission` command.

## Installation

1. Download the latest release from
   the [GitHub releases page](https://github.com/DavyCraft648/InputPermission/releases).
2. Move the downloaded `.phar` file into your PocketMine-MP server's `plugins/` folder.
3. Restart or reload your server.

## Dependencies

This plugin requires the following virions:

* **PMInputAPI**: Used to manage player input states.
* **PMServerUI**: Used to provide the interactive Form UI.

## Support

If you encounter any bugs or have feature suggestions, please open a new issue on
the [GitHub repository](https://github.com/DavyCraft648/InputPermission/issues).

## License

This plugin is licensed under the MIT License. See the `LICENSE` file for more details.