<?php

declare(strict_types=1);

namespace shainy\minekart;

use shainy\minekart\entity\Minekart;
use shainy\minekart\item\MinekartItem;
use pocketmine\plugin\PluginBase;
use pocketmine\entity\EntityFactory;
use pocketmine\world\World;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\entity\EntityDataHelper;
use pocketmine\item\ItemIdentifier;
use pocketmine\item\ItemIds;
use pocketmine\item\ItemFactory;
use pocketmine\item\StringToItemParser;
use pocketmine\inventory\CreativeInventory;

class Main extends PluginBase
{
  public function onLoad(): void
  {
    EntityFactory::getInstance()->register(Minekart::class, fn (World $world, CompoundTag $nbt): Minekart => new Minekart(EntityDataHelper::parseLocation($nbt, $world), $nbt), ['Minekart']);

    $item = new MinekartItem(new ItemIdentifier(ItemIds::MINECART, 1), "Minekart");
    $item->setLore(['Minekart']);

    ItemFactory::getInstance()->register($item);
    StringToItemParser::getInstance()->register('minekart', fn (string $input) => $item);
    CreativeInventory::getInstance()->add($item);
  }

  public function onEnable(): void
  {
    $this->getServer()->getPluginManager()->registerEvents(new EventListener(), $this);
  }
}
