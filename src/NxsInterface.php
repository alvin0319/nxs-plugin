<?php

declare(strict_types=1);

namespace alvin0319\NXS;

use pmmp\thread\ThreadSafeArray;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\lang\KnownTranslationFactory;
use pocketmine\network\AdvancedNetworkInterface;
use pocketmine\network\mcpe\compression\ZlibCompressor;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\EntityEventBroadcaster;
use pocketmine\network\mcpe\JwtException;
use pocketmine\network\mcpe\JwtUtils;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\PacketBroadcaster;
use pocketmine\network\mcpe\protocol\LoginPacket;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\network\mcpe\ServerPongData;
use pocketmine\network\Network;
use pocketmine\network\PacketHandlingException;
use pocketmine\Server;
use pocketmine\utils\Binary;
use pocketmine\utils\Utils;
use function base64_decode;
use function implode;
use function is_array;
use function is_string;
use function json_decode;
use function mt_rand;
use function ord;
use function spl_object_id;
use function strlen;
use function substr;
use const JSON_THROW_ON_ERROR;
use const PHP_INT_MAX;

final class NxsInterface implements AdvancedNetworkInterface{

	private const STATE_AWAITING_LOGIN = 0;
	private const STATE_VERIFYING = 1;
	private const STATE_APPROVED = 2;

	private NxsThread $thread;

	/** @phpstan-var ThreadSafeArray<int, string> */
	private ThreadSafeArray $toThread;

	/** @phpstan-var ThreadSafeArray<int, string> */
	private ThreadSafeArray $fromThread;

	private int $sleeperNotifierId;

	private int $serverId;

	private Network $network;

	/** @var array<int, NetworkSession> */
	private array $sessions = [];

	/** @var array<int, int> */
	private array $sessionIdsByObject = [];

	/** @var array<int, NxsIdentityKey> */
	private array $identityKeys = [];

	/** @var array<int, int> */
	private array $states = [];

	/** @var array<int, string> */
	private array $handlingPayload = [];

	/** @var array<int, string> */
	private array $pendingPublicKeys = [];

	/**
	 * @var string[][]
	 * @phpstan-var array<int, list<string>>
	 */
	private array $heldPayloads = [];

	/** @var array<int, true> */
	private array $joined = [];

	/**
	 * @phpstan-param list<string>          $command
	 * @phpstan-param array<string, string> $environment
	 */
	public function __construct(
		private Server $server,
		array $command,
		array $environment,
		string $socketPath,
		private PacketBroadcaster $packetBroadcaster,
		private EntityEventBroadcaster $entityEventBroadcaster,
		private TypeConverter $typeConverter
	){
		$this->serverId = mt_rand(0, PHP_INT_MAX);
		$this->toThread = new ThreadSafeArray();
		$this->fromThread = new ThreadSafeArray();

		$sleeperEntry = $this->server->getTickSleeper()->addNotifier(function() : void{
			while(is_string($frame = $this->fromThread->shift())){
				$this->handleFrame($frame);
			}
		});
		$this->sleeperNotifierId = $sleeperEntry->getNotifierId();

		$this->thread = new NxsThread(
			$this->server->getLogger(),
			ThreadSafeArray::fromArray($command),
			ThreadSafeArray::fromArray($environment),
			$socketPath,
			$this->toThread,
			$this->fromThread,
			$sleeperEntry
		);
	}

	public function start() : void{
		$this->server->getLogger()->info("Starting NXS bridge...");
		$this->thread->startAndWait();
		$this->server->getLogger()->info("NXS bridge connected");
	}

	public function setNetwork(Network $network) : void{
		$this->network = $network;
	}

	public function setName(string $name) : void{
		$this->toThread[] = Ipc::pong(ServerPongData::build($this->server, $name, $this->serverId));
	}

	public function tick() : void{
	}

	private function handleFrame(string $frame) : void{
		$type = ord($frame[0]);
		$sessionId = Binary::readLong(substr($frame, 1, 8));
		$body = substr($frame, Ipc::HEADER_LENGTH);

		switch($type){
			case Ipc::S2P_SESSION_OPEN:
				$addressLength = ord($body[0]);
				$this->openSession($sessionId, substr($body, 1, $addressLength), Binary::readShort(substr($body, 1 + $addressLength, 2)));
				break;
			case Ipc::S2P_PACKET:
				$this->handlePacket($sessionId, $body);
				break;
			case Ipc::S2P_SESSION_CLOSE:
				$session = $this->sessions[$sessionId] ?? null;
				$this->removeSession($sessionId);
				$session?->onClientDisconnect($body !== "" ? "NXS: $body" : KnownTranslationFactory::pocketmine_disconnect_clientDisconnect());
				break;
			case Ipc::S2P_IDENTITY_RESULT:
				$this->handleIdentityResult($sessionId, $body[0] === "\x01", substr($body, 1));
				break;
			case Ipc::S2P_PING:
				($this->sessions[$sessionId] ?? null)?->updatePing(Binary::readInt($body));
				break;
			case Ipc::S2P_RECEIPT:
				($this->sessions[$sessionId] ?? null)?->handleAckReceipt(Binary::readInt($body));
				break;
			case Ipc::THREAD_BRIDGE_STOPPED:
				$this->handleBridgeStopped($body);
				break;
			default:
				$this->server->getLogger()->debug("Unknown NXS frame type $type");
		}
	}

	private function openSession(int $sessionId, string $address, int $port) : void{
		$identityKey = new NxsIdentityKey();
		$session = new NetworkSession(
			$this->server,
			$this->network->getSessionManager(),
			PacketPool::getInstance(),
			new NxsPacketSender($sessionId, $this),
			$this->packetBroadcaster,
			$this->entityEventBroadcaster,
			ZlibCompressor::getInstance(),
			$this->typeConverter,
			$address,
			$port,
			$identityKey,
			true
		);
		$this->sessions[$sessionId] = $session;
		$this->sessionIdsByObject[spl_object_id($session)] = $sessionId;
		$this->identityKeys[$sessionId] = $identityKey;
		$this->states[$sessionId] = self::STATE_AWAITING_LOGIN;
	}

	private function handlePacket(int $sessionId, string $payload) : void{
		$session = $this->sessions[$sessionId] ?? null;
		if($session === null){
			return;
		}
		if($this->states[$sessionId] === self::STATE_VERIFYING){
			$this->heldPayloads[$sessionId][] = $payload;
			return;
		}

		$name = $session->getDisplayName();
		$this->handlingPayload[$sessionId] = $payload;
		try{
			$session->handleEncoded($payload);
		}catch(PacketHandlingException $e){
			$session->disconnectWithError(
				reason: "Bad packet: " . $e->getMessage(),
				disconnectScreenMessage: KnownTranslationFactory::pocketmine_disconnect_error_badPacket()
			);
			$session->getLogger()->debug(implode("\n", Utils::printableExceptionInfo($e)));
		}catch(\Throwable $e){
			$this->server->getLogger()->emergency("Crash occurred while handling a packet from session: $name");
			throw $e;
		}finally{
			unset($this->handlingPayload[$sessionId]);
		}
	}

	public function handleDataPacketReceive(DataPacketReceiveEvent $event) : void{
		$sessionId = $this->sessionIdsByObject[spl_object_id($event->getOrigin())] ?? null;
		if($sessionId === null || $this->states[$sessionId] === self::STATE_APPROVED){
			return;
		}
		if($this->states[$sessionId] === self::STATE_VERIFYING){
			$event->cancel();
			return;
		}
		$packet = $event->getPacket();
		if(!($packet instanceof LoginPacket)){
			return;
		}
		$event->cancel();

		$publicKey = self::extractClientPublicKey($packet);
		if($publicKey === null){
			$event->getOrigin()->disconnectWithError(
				reason: "NXS: login does not have a client public key",
				disconnectScreenMessage: KnownTranslationFactory::pocketmine_disconnect_error_authentication()
			);
			return;
		}
		$this->states[$sessionId] = self::STATE_VERIFYING;
		$this->pendingPublicKeys[$sessionId] = $publicKey;
		$this->heldPayloads[$sessionId] = [$this->handlingPayload[$sessionId]];
		$this->toThread[] = Ipc::verifyIdentity($sessionId, $publicKey);
	}

	private static function extractClientPublicKey(LoginPacket $packet) : ?string{
		try{
			$authInfo = json_decode($packet->authInfoJson, associative: true, flags: JSON_THROW_ON_ERROR);
			if(!is_array($authInfo) || !isset($authInfo["Token"]) || !is_string($authInfo["Token"])){
				return null;
			}
			[, $claims, ] = JwtUtils::parse($authInfo["Token"]);
		}catch(\JsonException|JwtException){
			return null;
		}
		if(!isset($claims["cpk"]) || !is_string($claims["cpk"])){
			return null;
		}
		$publicKey = base64_decode($claims["cpk"], strict: true);

		return $publicKey !== false && strlen($publicKey) > 0 ? $publicKey : null;
	}

	private function handleIdentityResult(int $sessionId, bool $approved, string $error) : void{
		$session = $this->sessions[$sessionId] ?? null;
		if($session === null || $this->states[$sessionId] !== self::STATE_VERIFYING){
			return;
		}
		if(!$approved){
			$session->disconnectWithError(
				reason: "NXS identity binding failed: $error",
				disconnectScreenMessage: KnownTranslationFactory::pocketmine_disconnect_error_authentication()
			);
			return;
		}

		$held = $this->heldPayloads[$sessionId];
		unset($this->heldPayloads[$sessionId]);
		$this->identityKeys[$sessionId]->approve($this->pendingPublicKeys[$sessionId]);
		unset($this->pendingPublicKeys[$sessionId]);
		$this->states[$sessionId] = self::STATE_APPROVED;
		foreach($held as $payload){
			if(!isset($this->sessions[$sessionId])){
				break;
			}
			$this->handlePacket($sessionId, $payload);
		}
	}

	public function onPlayerJoin(NetworkSession $session) : void{
		$sessionId = $this->sessionIdsByObject[spl_object_id($session)] ?? null;
		if($sessionId !== null){
			$this->joined[$sessionId] = true;
			$this->toThread[] = Ipc::gameJoined($sessionId);
		}
	}

	public function putPacket(int $sessionId, string $payload, ?int $receiptId) : void{
		if(isset($this->sessions[$sessionId])){
			$this->toThread[] = Ipc::send($sessionId, $payload, $receiptId);
		}
	}

	public function close(int $sessionId, string $reason) : void{
		if(!isset($this->sessions[$sessionId])){
			return;
		}
		if(!isset($this->joined[$sessionId])){
			$this->toThread[] = Ipc::gameRejected($sessionId, $reason);
		}
		$this->removeSession($sessionId);
		$this->toThread[] = Ipc::close($sessionId);
	}

	private function removeSession(int $sessionId) : void{
		$session = $this->sessions[$sessionId] ?? null;
		if($session !== null){
			unset($this->sessionIdsByObject[spl_object_id($session)]);
		}
		unset(
			$this->sessions[$sessionId],
			$this->identityKeys[$sessionId],
			$this->states[$sessionId],
			$this->heldPayloads[$sessionId],
			$this->handlingPayload[$sessionId],
			$this->pendingPublicKeys[$sessionId],
			$this->joined[$sessionId]
		);
	}

	private function handleBridgeStopped(string $reason) : void{
		$this->server->getLogger()->error("NXS bridge stopped: $reason");
		foreach($this->sessions as $sessionId => $session){
			$this->removeSession($sessionId);
			$session->onClientDisconnect("NXS bridge stopped");
		}
	}

	public function shutdown() : void{
		$this->server->getTickSleeper()->removeNotifier($this->sleeperNotifierId);
		$this->thread->quit();
	}

	public function blockAddress(string $address, int $timeout = 300) : void{
		$this->toThread[] = Ipc::blockAddress($address, $timeout);
	}

	public function unblockAddress(string $address) : void{
		$this->toThread[] = Ipc::unblockAddress($address);
	}

	public function sendRawPacket(string $address, int $port, string $payload) : void{
	}

	public function addRawPacketFilter(string $regex) : void{
	}
}
