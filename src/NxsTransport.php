<?php

declare(strict_types=1);

namespace alvin0319\NXS;

use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\EntityEventBroadcaster;
use pocketmine\network\mcpe\PacketBroadcaster;
use pocketmine\network\Transport;
use pocketmine\Server;

final class NxsTransport implements Transport{

	public const NAME = "nxs";

	private ?NxsInterface $interface = null;

	/**
	 * @phpstan-param list<string>          $command
	 * @phpstan-param array<string, string> $environment
	 */
	public function __construct(
		private Server $server,
		private array $command,
		private array $environment,
		private string $socketPath
	){}

	public function createInterfaces(
		PacketBroadcaster $packetBroadcaster,
		EntityEventBroadcaster $entityEventBroadcaster,
		TypeConverter $typeConverter
	) : array{
		$this->interface = new NxsInterface(
			$this->server,
			$this->command,
			$this->environment,
			$this->socketPath,
			$packetBroadcaster,
			$entityEventBroadcaster,
			$typeConverter
		);

		return [$this->interface];
	}

	public function getInterface() : ?NxsInterface{
		return $this->interface;
	}
}
