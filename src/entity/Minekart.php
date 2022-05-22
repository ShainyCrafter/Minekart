<?php

declare(strict_types=1);

namespace shainy\minekart\entity;

use pocketmine\entity\Living;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\math\Vector3;
use pocketmine\math\Vector2;
use pocketmine\network\mcpe\protocol\SetActorLinkPacket;
use pocketmine\network\mcpe\protocol\types\entity\EntityLink;
use pocketmine\player\Player;
use pocketmine\network\mcpe\protocol\AnimateEntityPacket;
use pocketmine\Server;
use pocketmine\item\StringToItemParser;
use pocketmine\network\mcpe\protocol\types\entity\Attribute as NetworkAttribute;
use pocketmine\entity\Attribute;
use pocketmine\entity\AttributeFactory;
use pocketmine\network\mcpe\protocol\PlaySoundPacket;
use pocketmine\network\mcpe\protocol\AddActorPacket;
use pocketmine\color\Color;
use pocketmine\block\Redstone;
use shainy\minekart\form\MinekartForm;

class Minekart extends Living
{
  protected $regenerationTicks = 10;
  protected $maxDeadTicks = 0;
  protected $stepHeight = 1.0;

  protected ?Player $rider = null;
  protected $acceleration = 0.0;
  protected $steering = 0.0;
  protected $steeringWhileDrifting = 0.0;
  protected $drifting = false;
  protected $speed = 0.0;
  protected $turbo = 0;

  protected Color $color;
  protected bool $rainbow;

  protected function addAttributes(): void
  {
    parent::addAttributes();
    $this->attributeMap->add(AttributeFactory::getInstance()->mustGet(Attribute::HORSE_JUMP_STRENGTH));
  }

  public static function getNetworkTypeId(): string
  {
    return 'minekart:minekart';
  }

  protected function getInitialSizeInfo(): EntitySizeInfo
  {
    return new EntitySizeInfo(0.6, 1.2);
  }

  public function getName(): string
  {
    return "Minekart";
  }

  public function getDrops(): array
  {
    return [StringToItemParser::getInstance()->parse('minekart')->setCount(1)];
  }

  public function initEntity(CompoundTag $nbt): void
  {
    $this->setMaxHealth(6);
    parent::initEntity($nbt);

    $this->setColor(Color::fromRGB($nbt->getInt("MinekartColor", 0xff0000)));
    $this->setRainbow((bool) $nbt->getByte("MinekartIsRainbow", 0));
  }

  public function saveNBT(): CompoundTag
  {
    $nbt = parent::saveNBT();
    $nbt->setInt("MinekartColor", ($this->color->getR() << 16) | ($this->color->getG() << 8) | $this->color->getB());

    return $nbt;
  }

  public function canBreathe(): bool
  {
    return true;
  }

  protected function sendSpawnPacket(Player $player): void
  {
    $player->getNetworkSession()->sendDataPacket(AddActorPacket::create(
      $this->getId(),
      $this->getId(),
      static::getNetworkTypeId(),
      $this->location->asVector3(),
      $this->getMotion(),
      $this->location->pitch,
      $this->location->yaw,
      $this->location->yaw,
      array_map(function (Attribute $attr): NetworkAttribute {
        return new NetworkAttribute($attr->getId(), $attr->getMinValue(), $attr->getMaxValue(), $attr->getValue(), $attr->getDefaultValue());
      }, $this->attributeMap->getAll()),
      $this->getAllNetworkData(),
      ($this->rider && $this->rider->isOnline()) ? [new EntityLink(
        $this->getId(),
        $this->rider->getId(),
        EntityLink::TYPE_PASSENGER,
        false,
        false
      )] : []
    ));
  }

  protected function calculateFallDamage(float $fallDistance): float
  {
    return 0;
  }

  protected function doHitAnimation(): void
  {
    $animate = AnimateEntityPacket::create('animation.minekart.hurt', 'default', 'query.any_animation_finished', 0, '__runtime_controller', 0, [$this->getId()]);
    $this->getWorld()->broadcastPacketToViewers($this->getLocation(), $animate);
  }

  public function attack(EntityDamageEvent $source): void
  {
    parent::attack($source);
    $this->attackTime = 0;
  }

  public function knockBack(float $x, float $z, float $force = 0.4, ?float $verticalLimit = 0.4): void
  {
  }

  protected function entityBaseTick(int $tickDiff = 1): bool
  {
    $hasUpdate = parent::entityBaseTick($tickDiff);

    if ($this->isAlive()) {
      if ($this->regenerationTicks > 0) {
        $this->regenerationTicks -= $tickDiff;
        if ($this->regenerationTicks <= 0) {
          $this->setHealth($this->getHealth() + 1);
          $this->regenerationTicks = 10;
          $hasUpdate = true;
        }
      }
    }

    return $hasUpdate;
  }

  protected function startDeathAnimation(): void
  {
  }

  protected function tryChangeMovement(): void
  {
    $friction = 1 - $this->drag;

    $mY = $this->motion->y;

    if ($this->applyDragBeforeGravity()) {
      $mY *= $friction;
    }

    if ($this->gravityEnabled) {
      $mY -= $this->gravity;
    }

    if (!$this->applyDragBeforeGravity()) {
      $mY *= $friction;
    }

    if ($this->onGround) {
      $frictionFactor = $this->getWorld()->getBlockAt((int) floor($this->location->x), (int) floor($this->location->y - 1), (int) floor($this->location->z))->getFrictionFactor();
      $friction *= $this->drifting ? 0.98 : $frictionFactor;
    }

    $this->motion = new Vector3($this->motion->x * $friction, $mY, $this->motion->z * $friction);
  }

  public function onInteract(Player $player, Vector3 $clickPos): bool
  {
    if (!$this->rider || !$this->rider->isOnline()) {
      if (!$player->isSneaking()) {
        $properties = $player->getNetworkProperties();
        $properties->setVector3(EntityMetadataProperties::RIDER_SEAT_POSITION, new Vector3(0, 1.5, 0));
        $properties->setByte(EntityMetadataProperties::RIDER_ROTATION_LOCKED, 1);

        $this->setRider($player);

        return true;
      } else {
        $player->sendForm(new MinekartForm($this));
        return true;
      }
    }

    return false;
  }

  protected function syncNetworkData(EntityMetadataCollection $properties): void
  {
    parent::syncNetworkData($properties);
    $properties->setGenericFlag(EntityMetadataFlags::CAN_POWER_JUMP, true);
    $properties->setInt(EntityMetadataProperties::VARIANT, $this->color->getR() * 1000 + $this->color->getG());
    $properties->setInt(EntityMetadataProperties::MARK_VARIANT, $this->color->getB());
    $properties->setInt(EntityMetadataProperties::SKIN_ID, (int) (floor(($this->drifting ? $this->steeringWhileDrifting : $this->steering) * 450) * 1000 + floor($this->speed * 100) + 500));
    $properties->setGenericFlag(EntityMetadataFlags::CHARGED, $this->turbo > 0);
    $properties->setGenericFlag(EntityMetadataFlags::SHEARED, $this->drifting);
    $properties->setGenericFlag(EntityMetadataFlags::IGNITED, $this->rainbow);
  }

  public function getColor(): Color
  {
    return $this->color;
  }

  public function setColor(Color $color): void
  {
    $this->color = $color;
    $this->networkPropertiesDirty = true;
  }

  public function isRainbow(): bool
  {
    return $this->rainbow;
  }

  public function setRainbow(bool $isRainbow): void
  {
    $this->rainbow = $isRainbow;
    $this->networkPropertiesDirty = true;
  }

  public function getRider(): ?Player
  {
    return $this->rider;
  }

  public function setRider(?Player $player): void
  {
    $packets = [];

    if ($this->rider && $this->rider->isOnline()) {
      $packets[] = SetActorLinkPacket::create(new EntityLink(
        $this->getId(),
        $this->rider->getId(),
        EntityLink::TYPE_REMOVE,
        false,
        false
      ));
    }

    if ($player) {
      $packets[] = SetActorLinkPacket::create(new EntityLink(
        $this->getId(),
        $player->getId(),
        EntityLink::TYPE_RIDER,
        false,
        false
      ));

      $packets[] = PlaySoundPacket::create('minekart.start', $this->location->x, $this->location->y, $this->location->z, 1, 1);
    }

    $this->rider = $player;

    $this->accelerate(0.0);

    if ($packets) $this->server->broadcastPackets($this->getWorld()->getPlayers(), $packets);
  }

  public function accelerate(float $value): void
  {
    $this->acceleration = $value;
  }

  public function steer(float $value): void
  {
    if ($this->drifting) {
      $this->steeringWhileDrifting = $value;
    } else $this->steering = $value;
    $this->networkPropertiesDirty = true;
  }

  public function drift(bool $value): void
  {
    if ($this->drifting === $value) return;
    $this->drifting = $value;
    $this->networkPropertiesDirty = true;
  }

  public function turbo(float $value): void
  {
    $this->turbo = $value;
    $this->networkPropertiesDirty = true;
  }

  public function onUpdate(int $currentTick): bool
  {
    $beforeSpeed = $this->speed;

    $this->speed += $this->acceleration / 30;
    $this->speed = min(1, max(-1, $this->speed));
    if (abs($this->speed) > 0.02) $this->speed += ($this->speed < 0 ? 1 : -1) * 0.02;
    else $this->speed = 0;

    if ($this->turbo > 0) {
      $this->speed = 1.25;
      $this->turbo--;
      if ($this->turbo <= 0) $this->networkPropertiesDirty = true;
    }

    if ($beforeSpeed !== $this->speed) $this->networkPropertiesDirty = true;

    $packets = [];

    $location = $this->location->subtractVector($this->motion->multiply(2));

    if ($this->isOnGround()) {
      $block = $this->getWorld()->getBlockAt((int) floor($this->location->x), (int) floor($this->location->y - 1), (int) floor($this->location->z));
      if ($block instanceof Redstone) {
        $this->turbo(20 * 2);
      }

      $direction = $this->getDirectionPlane();
      $motion = new Vector2($this->motion->x, $this->motion->z);
      $deg = 0.0;
      $div = (sqrt($direction->dot($direction)) * sqrt($motion->dot($motion)));
      if ($div) $deg = rad2deg(acos($direction->dot($motion) / $div));
      $isMovingBack = $deg > 90.0;
      if ($isMovingBack) $deg = 180.0 - $deg;

      $yawDiff = ($this->steering + ($this->drifting ? $this->steeringWhileDrifting * 0.4 : 0)) * 8 * ((90 - $deg) / 90.0) * $motion->length() * ($isMovingBack ? 1 : -1);

      if ($this->drifting) {
        $packets[] = PlaySoundPacket::create('minekart.slip', $location->x, $location->y, $location->z, min(1, abs($this->speed) * 0.5), 0.5 + min(1.5, abs($this->speed) * 0.5));
      } else $yawDiff *= 0.5;

      if (!is_nan($yawDiff)) {
        $this->setRotation($this->location->yaw + $yawDiff, $this->location->pitch);
      }

      $motion = $direction->multiply($this->speed * 1.25 * ($this->drifting ? 0.03 : 1));
      $this->addMotion($motion->x, 0, $motion->y);
    }

    $packets[] = PlaySoundPacket::create('minekart.base', $location->x, $location->y, $location->z, 0.5 + min(0.5, abs($this->speed) * 0.5), min(2, abs($this->speed) * 0.5));
    $server = Server::getInstance();
    $server->broadcastPackets($this->getViewers(), $packets);

    return parent::onUpdate($currentTick);
  }
}
