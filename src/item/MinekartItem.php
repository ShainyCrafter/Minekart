<?php

declare(strict_types=1);

namespace shainy\minekart\item;

use shainy\minekart\entity\Minekart;
use pocketmine\item\SpawnEgg;
use pocketmine\world\World;
use pocketmine\math\Vector3;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;

class MinekartItem extends SpawnEgg
{
  public function createEntity(World $world, Vector3 $pos, float $yaw, float $pitch): Entity
  {
    return new Minekart(Location::fromObject($pos, $world, $yaw, $pitch));
  }

  public function getMaxStackSize(): int
  {
    return 1;
  }
}
