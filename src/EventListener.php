<?php

namespace shainy\minekart;

use shainy\minekart\entity\Minekart;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerToggleSneakEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\network\mcpe\protocol\AvailableActorIdentifiersPacket;
use pocketmine\network\mcpe\protocol\InteractPacket;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\PlayerAuthInputPacket;
use pocketmine\network\mcpe\protocol\AddPlayerPacket;
use pocketmine\network\mcpe\protocol\types\PlayerAuthInputFlags;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\player\Player;
use pocketmine\network\mcpe\protocol\types\entity\EntityLink;
use pocketmine\Server;
use pocketmine\event\entity\EntityDamageEvent;

class EventListener implements Listener
{
  protected $players = [];

  public function onEntityDamageEvent(EntityDamageEvent $event): void
  {
    if ($event->isCancelled() || !($player = $event->getEntity()) instanceof Player || $event->getCause() !== EntityDamageEvent::CAUSE_FALL) return;
    foreach ($player->getWorld()->getNearbyEntities($player->getBoundingBox()->expandedCopy(32, 32, 32)) as $entity) {
      if (!$entity instanceof Minekart) continue;
      if ($entity->getRider() !== $player) continue;
      $event->cancel();
      break;
    }
  }

  public function onPlayerToggleSneakEvent(PlayerToggleSneakEvent $event): void
  {
    $player = $event->getPlayer();

    if (!isset($this->players[$player->getId()])) return;

    $properties = $player->getNetworkProperties();
    $properties->setString(EntityMetadataProperties::INTERACTIVE_TAG, $event->isSneaking() ? 'action.interact.dye' : 'action.interact.ride.minecart');
    $properties->markDirty(EntityMetadataProperties::INTERACTIVE_TAG);
  }

  public function onDataPacketReceive(DataPacketReceiveEvent $event): void
  {
    $packet = $event->getPacket();
    $player = $event->getOrigin()->getPlayer();

    if ($player === null) return;

    if ($packet instanceof InteractPacket) {
      $world = $player->getWorld();
      $entity = $world->getEntity($packet->targetActorRuntimeId);

      if (!$entity || !$entity instanceof Minekart) return;

      switch ($packet->action) {
        case InteractPacket::ACTION_MOUSEOVER:
          $this->players[$player->getId()] = true;
          if ($player->isSneaking()) break;
          $properties = $player->getNetworkProperties();
          $properties->setString(EntityMetadataProperties::INTERACTIVE_TAG, 'action.interact.ride.minecart');
          $properties->markDirty(EntityMetadataProperties::INTERACTIVE_TAG);
          break;
        case InteractPacket::ACTION_LEAVE_VEHICLE:
          $entity->setRider(null);
          break;
      }
    } elseif ($packet instanceof PlayerAuthInputPacket) {
      foreach ($player->getWorld()->getNearbyEntities($player->getBoundingBox()->expandedCopy(32, 32, 32)) as $entity) {
        if (!$entity instanceof Minekart) continue;

        if ($entity->getRider() !== $player) continue;

        $entity->accelerate($packet->getMoveVecZ());
        $entity->steer($packet->getMoveVecX());
        $entity->drift($packet->hasFlag(PlayerAuthInputFlags::JUMP_DOWN));
        break;
      }
    }
  }

  public function onDataPacketSend(DataPacketSendEvent $event): void
  {
    foreach ($event->getPackets() as $packet) {
      if ($packet instanceof AddPlayerPacket) {
        $player = Server::getInstance()->getPlayerByUUID($packet->uuid);
        foreach ($player->getWorld()->getNearbyEntities($player->getBoundingBox()->expandedCopy(32, 32, 32)) as $entity) {
          if (!$entity instanceof Minekart) continue;
          if ($entity->getRider() !== $player) continue;
          $packet->links[] = new EntityLink(
            $entity->getId(),
            $packet->actorRuntimeId,
            EntityLink::TYPE_RIDER,
            false,
            false
          );
          break;
        }
        continue;
      }

      if (!$packet instanceof AvailableActorIdentifiersPacket) continue;
      $add = new CompoundTag();
      $add->setString('bid', '');
      $add->setByte('hasspawnegg', 0);
      $add->setString('id', 'minekart:minekart');
      $add->setByte('summonable', 1);
      $packet->identifiers->getRoot()->getListTag('idlist')->push($add);
    }
  }
}
