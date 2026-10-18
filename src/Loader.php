<?php

declare(strict_types=1);

namespace alvin0319\NXS;

use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use pocketmine\utils\Filesystem;
use pocketmine\utils\Utils;
use Symfony\Component\Filesystem\Path;
use function bin2hex;
use function chmod;
use function clearstatcache;
use function copy;
use function fileowner;
use function fileperms;
use function function_exists;
use function get_debug_type;
use function hash_file;
use function implode;
use function in_array;
use function is_array;
use function is_bool;
use function is_dir;
use function is_file;
use function is_int;
use function is_link;
use function is_string;
use function mkdir;
use function php_uname;
use function posix_geteuid;
use function random_bytes;
use function rename;
use function rtrim;
use function socket_close;
use function socket_create;
use function socket_last_error;
use function socket_strerror;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;
use function sys_get_temp_dir;
use function unlink;
use const AF_UNIX;
use const SOCK_STREAM;

final class Loader extends PluginBase{

	private const MAX_SOCKET_PATH_LENGTH = 107;
	private const TOKEN_FILE_PREFIX = "file:";

	protected function onEnable() : void{
		$this->saveDefaultConfig();
		try{
			$this->enableTransport($this->getConfig());
		}catch(\InvalidArgumentException $e){
			$this->getLogger()->error("Unable to enable NXS");
			$this->getLogger()->logException($e);
		}
	}

	/**
	 * @throws \InvalidArgumentException
	 */
	private function enableTransport(Config $config) : void{
		$origin = self::stringOption($config, "provider-origin", "");
		if($origin === ""){
			$this->getLogger()->notice("NXS is disabled. Set provider-origin in config.yml to enable it.");
			return;
		}

		if(!in_array(Utils::getOS(), [Utils::OS_LINUX, Utils::OS_WINDOWS], true)){
			throw new \InvalidArgumentException("NXS is only supported on Linux and Windows");
		}

		self::checkUnixSocketSupport();

		$dataFolder = $this->getDataFolder();
		$binary = self::stringOption($config, "bridge-binary", "");
		if($binary === ""){
			$binary = $this->extractBundledBridge();
		}elseif(!is_file($binary)){
			throw new \InvalidArgumentException("Bridge executable not found at $binary");
		}

		$socketPath = self::stringOption($config, "socket-path", "");
		if($socketPath === ""){
			$socketPath = $dataFolder . "nxs.sock";
		}
		if(strlen($socketPath) > self::MAX_SOCKET_PATH_LENGTH){
			throw new \InvalidArgumentException("Socket path $socketPath is longer than " . self::MAX_SOCKET_PATH_LENGTH . " bytes, set a shorter socket-path in config.yml");
		}

		$capacity = self::intOption($config, "capacity", 0);
		$command = [
			$binary,
			"-socket", $socketPath,
			"-origin", $origin,
			"-state", $dataFolder . "state",
			"-addr", ":" . self::intOption($config, "port", 19133),
			"-endpoints", implode(",", self::stringListOption($config, "endpoints")),
			"-capacity", (string) ($capacity > 0 ? $capacity : $this->getServer()->getMaxPlayers()),
			"-label", self::stringOption($config, "label", ""),
			"-software", $this->getServer()->getName(),
			"-software-version", $this->getServer()->getPocketMineVersion(),
			"-websocket=" . self::flag(self::boolOption($config, "websocket", true)),
			"-assisted-joins=" . self::flag(self::boolOption($config, "assisted-joins", false)),
			"-diagnostics=" . self::flag(self::boolOption($config, "diagnostics", false)),
			"-game-outcomes=" . self::flag(self::boolOption($config, "game-outcomes", true)),
			"-debug=" . self::flag(self::boolOption($config, "debug", false)),
			"-exit-on-stdin-eof",
		];
		$token = self::stringOption($config, "token", "");
		if(str_starts_with($token, self::TOKEN_FILE_PREFIX)){
			$tokenFile = Path::makeAbsolute(substr($token, strlen(self::TOKEN_FILE_PREFIX)), $dataFolder);
			try{
				$token = rtrim(Filesystem::fileGetContents($tokenFile), "\r\n");
			}catch(\RuntimeException $e){
				throw new \InvalidArgumentException("Failed to read token from file: " . $e->getMessage(), 0, $e);
			}
		}
		$environment = $token !== "" ? ["NXS_TOKEN" => $token] : [];

		$transport = new NxsTransport($this->getServer(), $command, $environment, $socketPath);
		$network = $this->getServer()->getNetwork();
		$network->registerTransport(NxsTransport::NAME, $transport);
		$this->getServer()->getPluginManager()->registerEvents(new EventListener($transport), $this);
	}

	private function extractBundledBridge() : string{
		$platform = self::platform();
		$name = "bridge-$platform" . (Utils::getOS() === Utils::OS_WINDOWS ? ".exe" : "");
		$source = $this->getResourcePath($name);
		if(!is_file($source)){
			throw new \InvalidArgumentException("This phar has no bridge for $platform, download " . $this->getName() . "-$platform.phar or build the bridge and set bridge-binary in config.yml");
		}
		$hash = hash_file("sha256", $source);
		if($hash === false){
			throw new \InvalidArgumentException("Unable to compute hash of bundled bridge $name");
		}
		$target = Path::join(self::privateTempDirectory(), "bridge-" . substr($hash, 0, 16) . (Utils::getOS() === Utils::OS_WINDOWS ? ".exe" : ""));
		if(is_file($target) && hash_file("sha256", $target) === $hash){
			return $target;
		}

		$partial = $target . "." . bin2hex(random_bytes(8));
		if(!copy($source, $partial) || (Utils::getOS() !== Utils::OS_WINDOWS && !chmod($partial, 0700)) || !rename($partial, $target)){
			@unlink($partial);
			throw new \InvalidArgumentException("could not extract the bundled bridge to $target");
		}
		return $target;
	}

	/**
	 * @throws \InvalidArgumentException
	 */
	private static function privateTempDirectory() : string{
		if(Utils::getOS() === Utils::OS_WINDOWS){
			$directory = Path::join(sys_get_temp_dir(), "pocketmine-nxs");
			if(!is_dir($directory) && !mkdir($directory) && !is_dir($directory)){
				throw new \InvalidArgumentException("could not create $directory");
			}
			return $directory;
		}

		if(!function_exists("posix_geteuid")){
			throw new \InvalidArgumentException("Posix extension is required to extract the bundled bridge, or set bridge-binary in config.yml");
		}
		$uid = posix_geteuid();
		$directory = Path::join(sys_get_temp_dir(), "pocketmine-nxs-$uid");
		if(!is_dir($directory) && !@mkdir($directory, 0700) && !is_dir($directory)){
			throw new \InvalidArgumentException("could not create $directory");
		}
		clearstatcache(true, $directory);
		if(is_link($directory) || fileowner($directory) !== $uid || (fileperms($directory) & 0077) !== 0){
			throw new \InvalidArgumentException("$directory must be a directory owned by this user with mode 0700");
		}
		return $directory;
	}

	/**
	 * @throws \InvalidArgumentException
	 */
	private static function platform() : string{
		$os = match(Utils::getOS()){
			Utils::OS_WINDOWS => "win",
			Utils::OS_LINUX => "linux",
			default => throw new \InvalidArgumentException("No prebuilt bridge binary for OS " . Utils::getOS() . ", build it and set bridge-binary in config.yml")
		};
		$machine = php_uname("m");
		$arch = match(strtolower($machine)){
			"x86_64", "amd64" => "amd64",
			"aarch64", "arm64" => "arm64",
			default => throw new \InvalidArgumentException("No prebuilt bridge binary for architecture $machine, build it and set bridge-binary in config.yml")
		};
		return "$os-$arch";
	}

	/**
	 * @throws \InvalidArgumentException
	 */
	private static function checkUnixSocketSupport() : void{
		$socket = @socket_create(AF_UNIX, SOCK_STREAM, 0);
		if($socket === false){
			throw new \InvalidArgumentException("UNIX domain sockets are not available (" . socket_strerror(socket_last_error()) . "), Windows 10 1803 or Windows Server 2019 or newer is required on Windows");
		}
		socket_close($socket);
	}

	/**
	 * @throws \InvalidArgumentException
	 */
	private static function stringOption(Config $config, string $key, string $default) : string{
		$value = $config->get($key, $default);
		if(!is_string($value)){
			throw new \InvalidArgumentException("$key must be a string, got " . get_debug_type($value));
		}
		return $value;
	}

	/**
	 * @throws \InvalidArgumentException
	 */
	private static function intOption(Config $config, string $key, int $default) : int{
		$value = $config->get($key, $default);
		if(!is_int($value)){
			throw new \InvalidArgumentException("$key must be an integer, got " . get_debug_type($value));
		}
		return $value;
	}

	/**
	 * @throws \InvalidArgumentException
	 */
	private static function boolOption(Config $config, string $key, bool $default) : bool{
		$value = $config->get($key, $default);
		if(!is_bool($value)){
			throw new \InvalidArgumentException("$key must be true or false, got " . get_debug_type($value));
		}
		return $value;
	}

	/**
	 * @return string[]
	 * @phpstan-return list<string>
	 *
	 * @throws \InvalidArgumentException
	 */
	private static function stringListOption(Config $config, string $key) : array{
		$value = $config->get($key, []);
		if(!is_array($value)){
			throw new \InvalidArgumentException("$key must be a list, got " . get_debug_type($value));
		}
		$list = [];
		foreach($value as $entry){
			if(!is_string($entry)){
				throw new \InvalidArgumentException("$key entries must be strings, got " . get_debug_type($entry));
			}
			$list[] = $entry;
		}
		return $list;
	}

	private static function flag(bool $value) : string{
		return $value ? "true" : "false";
	}
}
