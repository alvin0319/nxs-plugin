<?php

declare(strict_types=1);

namespace alvin0319\NXS;

use pocketmine\network\mcpe\PacketSender;

final class NxsPacketSender implements PacketSender{
	private bool $closed = false;

	public function __construct(
		private int $sessionId,
		private NxsInterface $handler
	){}

	public function send(string $payload, bool $immediate, ?int $receiptId) : void{
		if(!$this->closed){
			$this->handler->putPacket($this->sessionId, $payload, $receiptId);
		}
	}

	public function close(string $reason = "unknown reason") : void{
		if(!$this->closed){
			$this->closed = true;
			$this->handler->close($this->sessionId, $reason);
		}
	}
}
