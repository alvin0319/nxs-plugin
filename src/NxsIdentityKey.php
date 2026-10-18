<?php

declare(strict_types=1);

namespace alvin0319\NXS;

use pocketmine\network\TransportIdentityException;
use pocketmine\network\TransportIdentityKey;
use function hash;
use function hash_equals;

final class NxsIdentityKey implements TransportIdentityKey{

	private ?string $approvedPublicKey = null;

	public function approve(string $publicKeyDer) : void{
		$this->approvedPublicKey = $publicKeyDer;
	}

	public function getKey() : ?string{
		return $this->approvedPublicKey !== null ? hash("sha256", $this->approvedPublicKey, binary: true) : null;
	}

	public function verify(string $clientPublicKey) : void{
		if($this->approvedPublicKey === null){
			throw new TransportIdentityException("NXS admission identity was not verified");
		}
		if(!hash_equals($this->approvedPublicKey, $clientPublicKey)){
			throw new TransportIdentityException("Client public key does not match the NXS admission identity");
		}
	}
}
