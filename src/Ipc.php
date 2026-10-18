<?php

declare(strict_types=1);

namespace alvin0319\NXS;

use pocketmine\utils\Binary;
use function strlen;

final class Ipc{

	public const S2P_SESSION_OPEN = 0;
	public const S2P_PACKET = 1;
	public const S2P_SESSION_CLOSE = 2;
	public const S2P_IDENTITY_RESULT = 3;
	public const S2P_PING = 4;
	public const S2P_RECEIPT = 5;
	public const THREAD_BRIDGE_STOPPED = 0xff;

	public const P2S_SEND = 0;
	public const P2S_CLOSE = 1;
	public const P2S_VERIFY_IDENTITY = 2;
	public const P2S_PONG = 3;
	public const P2S_GAME_JOINED = 4;
	public const P2S_GAME_REJECTED = 5;
	public const P2S_BLOCK_ADDRESS = 6;
	public const P2S_UNBLOCK_ADDRESS = 7;

	public const HEADER_LENGTH = 9;

	private function __construct(){
	}

	private static function frame(int $type, int $sessionId, string $body = "") : string{
		return Binary::writeByte($type) . Binary::writeLong($sessionId) . $body;
	}

	public static function send(int $sessionId, string $payload, ?int $receiptId) : string{
		return self::frame(self::P2S_SEND, $sessionId, Binary::writeInt($receiptId === null ? 0 : $receiptId + 1) . $payload);
	}

	public static function close(int $sessionId) : string{
		return self::frame(self::P2S_CLOSE, $sessionId);
	}

	public static function verifyIdentity(int $sessionId, string $publicKeyDer) : string{
		return self::frame(self::P2S_VERIFY_IDENTITY, $sessionId, $publicKeyDer);
	}

	public static function pong(string $pongData) : string{
		return self::frame(self::P2S_PONG, 0, $pongData);
	}

	public static function gameJoined(int $sessionId) : string{
		return self::frame(self::P2S_GAME_JOINED, $sessionId);
	}

	public static function gameRejected(int $sessionId, string $reason) : string{
		return self::frame(self::P2S_GAME_REJECTED, $sessionId, $reason);
	}

	public static function blockAddress(string $address, int $timeout) : string{
		return self::frame(self::P2S_BLOCK_ADDRESS, 0, Binary::writeByte(strlen($address)) . $address . Binary::writeInt($timeout));
	}

	public static function unblockAddress(string $address) : string{
		return self::frame(self::P2S_UNBLOCK_ADDRESS, 0, Binary::writeByte(strlen($address)) . $address);
	}

	public static function bridgeStopped(string $reason) : string{
		return self::frame(self::THREAD_BRIDGE_STOPPED, 0, $reason);
	}
}
