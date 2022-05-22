<?php

declare(strict_types=1);

namespace shainy\minekart\form;

use pocketmine\form\Form;
use pocketmine\player\Player;
use shainy\minekart\entity\Minekart;
use pocketmine\color\Color;

class MinekartForm implements Form
{
  public Minekart $minekart;

  public function __construct(Minekart $minekart)
  {
    $this->minekart = $minekart;
  }

  public function handleResponse(Player $player, $data): void
  {
    if ($data === null) return;
    $this->minekart->setColor(new Color((int) $data[0], (int) $data[1], (int) $data[2]));
    $this->minekart->setRainbow($data[3]);
  }

  public function jsonSerialize()
  {
    $color = $this->minekart->getColor();
    return [
      'type' => 'custom_form',
      'title' => 'minekart.form.title',
      'content' => [
        [
          'type' => 'slider',
          'text' => '§cR',
          'min' => 0,
          'max' => 255,
          'default' => $color->getR()
        ],
        [
          'type' => 'slider',
          'text' => '§aG',
          'min' => 0,
          'max' => 255,
          'default' => $color->getG()
        ],
        [
          'type' => 'slider',
          'text' => '§9B',
          'min' => 0,
          'max' => 255,
          'default' => $color->getB()
        ],
        [
          'type' => 'toggle',
          'text' => 'minekart.form.rainbow',
          'default' => $this->minekart->isRainbow()
        ]
      ]
    ];
  }
}
