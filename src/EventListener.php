<?php

declare(strict_types=1);

namespace alvin0319\NXS;

use pocketmine\event\EventPriority;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\server\DataPacketReceiveEvent;

final class EventListener implements Listener{

	public function __construct(
		private NxsTransport $transport
	){}

	/**
	 * @priority LOWEST
	 */
	public function onDataPacketReceive(DataPacketReceiveEvent $event) : void{
		$this->transport->getInterface()?->handleDataPacketReceive($event);
	}

	/**
	 * @priority MONITOR
	 */
	public function onPlayerJoin(PlayerJoinEvent $event) : void{
		$this->transport->getInterface()?->onPlayerJoin($event->getPlayer()->getNetworkSession());
	}
}
