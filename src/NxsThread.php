<?php

declare(strict_types=1);

namespace alvin0319\NXS;

use pmmp\thread\Thread as NativeThread;
use pmmp\thread\ThreadSafeArray;
use pocketmine\network\NetworkInterfaceStartException;
use pocketmine\snooze\SleeperHandlerEntry;
use pocketmine\snooze\SleeperNotifier;
use pocketmine\thread\log\ThreadSafeLogger;
use pocketmine\thread\Thread;
use pocketmine\thread\ThreadCrashException;
use pocketmine\utils\Binary;
use function array_values;
use function fclose;
use function fopen;
use function getenv;
use function is_resource;
use function is_string;
use function microtime;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function proc_terminate;
use function socket_close;
use function socket_connect;
use function socket_create;
use function socket_last_error;
use function socket_read;
use function socket_select;
use function socket_set_nonblock;
use function socket_strerror;
use function socket_write;
use function strlen;
use function substr;
use function usleep;
use const AF_UNIX;
use const SOCK_STREAM;

final class NxsThread extends Thread{

	private const SELECT_TIMEOUT_USEC = 10_000;
	private const CONNECT_TIMEOUT = 60.0;
	private const CONNECT_RETRY_USEC = 100_000;
	private const READ_SIZE = 262144;
	private const MAX_FRAME_SIZE = 8 << 20;

	protected bool $ready = false;

	protected ?string $startupError = null;

	/**
	 * @phpstan-param ThreadSafeArray<int, string>    $command
	 * @phpstan-param ThreadSafeArray<string, string> $environment
	 * @phpstan-param ThreadSafeArray<int, string>    $mainToThread
	 * @phpstan-param ThreadSafeArray<int, string>    $threadToMain
	 */
	public function __construct(
		protected ThreadSafeLogger $logger,
		protected ThreadSafeArray $command,
		protected ThreadSafeArray $environment,
		protected string $socketPath,
		protected ThreadSafeArray $mainToThread,
		protected ThreadSafeArray $threadToMain,
		protected SleeperHandlerEntry $sleeperEntry
	){}

	/**
	 * @throws NetworkInterfaceStartException
	 */
	public function startAndWait() : void{
		$this->start(NativeThread::INHERIT_NONE);
		$this->synchronized(function() : void{
			while(!$this->ready && !$this->isTerminated()){
				$this->wait();
			}
		});

		$crashInfo = $this->getCrashInfo();
		if($crashInfo !== null){
			throw new ThreadCrashException("NXS bridge thread failed to start", $crashInfo);
		}
		if($this->startupError !== null){
			throw new NetworkInterfaceStartException($this->startupError);
		}
	}

	protected function onRun() : void{
		\GlobalLogger::set($this->logger);
		$notifier = $this->sleeperEntry->createNotifier();

		$output = fopen("php://stderr", "w");
		if($output === false){
			$this->markReady("Failed to open standard error for the NXS bridge");
			return;
		}
		$process = proc_open(array_values((array) $this->command), [0 => ["pipe", "r"], 1 => $output, 2 => $output], $pipes, null, [...getenv(), ...(array) $this->environment]);
		if(!is_resource($process)){
			$this->markReady("Failed to start the NXS bridge");
			return;
		}

		$socket = $this->connect($process);
		if($socket === null){
			proc_terminate($process);
			proc_close($process);
			return;
		}
		$this->markReady(null);

		$reason = $this->relay($socket, $notifier);

		socket_close($socket);
		fclose($pipes[0]);
		$exitCode = proc_close($process);
		if($reason !== null){
			$this->emit($notifier, Ipc::bridgeStopped("$reason (exit code $exitCode)"));
		}
	}

	private function markReady(?string $error) : void{
		$this->synchronized(function() use ($error) : void{
			$this->startupError = $error;
			$this->ready = true;
			$this->notify();
		});
	}

	/**
	 * @param resource $process
	 */
	private function connect($process) : ?\Socket{
		$deadline = microtime(true) + self::CONNECT_TIMEOUT;
		while(microtime(true) < $deadline){
			if(!proc_get_status($process)["running"]){
				$this->markReady("The NXS bridge exited during startup; see its log output above");
				return null;
			}
			$socket = socket_create(AF_UNIX, SOCK_STREAM, 0);
			if($socket !== false && @socket_connect($socket, $this->socketPath)){
				socket_set_nonblock($socket);
				return $socket;
			}
			usleep(self::CONNECT_RETRY_USEC);
		}
		$this->markReady("Timed out connecting to the NXS bridge at $this->socketPath");
		return null;
	}

	private function relay(\Socket $socket, SleeperNotifier $notifier) : ?string{
		$inbound = "";
		$outbound = "";
		while(!$this->isKilled){
			while(is_string($frame = $this->mainToThread->shift())){
				$outbound .= Binary::writeInt(strlen($frame)) . $frame;
			}

			$read = [$socket];
			$write = $outbound !== "" ? [$socket] : [];
			$except = [];
			if(socket_select($read, $write, $except, 0, self::SELECT_TIMEOUT_USEC) === false){
				return "socket_select failed: " . socket_strerror(socket_last_error($socket));
			}

			if($write !== []){
				$written = @socket_write($socket, $outbound);
				if($written === false){
					return "Write to the NXS bridge failed: " . socket_strerror(socket_last_error($socket));
				}
				$outbound = substr($outbound, $written);
			}

			if($read !== []){
				$chunk = @socket_read($socket, self::READ_SIZE);
				if($chunk === false || $chunk === ""){
					return "The NXS bridge closed the connection";
				}
				$inbound .= $chunk;
				$received = false;
				while(strlen($inbound) >= 4){
					$length = Binary::readInt(substr($inbound, 0, 4));
					if($length < Ipc::HEADER_LENGTH || $length > self::MAX_FRAME_SIZE){
						return "Invalid frame length $length from the NXS bridge";
					}
					if(strlen($inbound) < 4 + $length){
						break;
					}
					$this->threadToMain[] = substr($inbound, 4, $length);
					$inbound = substr($inbound, 4 + $length);
					$received = true;
				}
				if($received){
					$notifier->wakeupSleeper();
				}
			}
		}

		return null;
	}

	private function emit(SleeperNotifier $notifier, string $frame) : void{
		$this->threadToMain[] = $frame;
		$notifier->wakeupSleeper();
	}

	public function getThreadName() : string{
		return "NXS";
	}
}
