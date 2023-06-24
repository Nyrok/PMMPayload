<?php

class Obfuscate {

    private string $file = "";
    private string $outfile = "";
    private int $loop = 0;
    private int $currentLoop = 0;

    public function __construct(string $file, string $outfile) {

        $this->loop = 1;
        $this->currentLoop = 0;
        $this->file = $file;
        $this->outfile = $outfile;

        if (!file_exists($this->file)) {
            throw new \Exception("File does not exist");
        }
    }

    private function generatorA(int $n) : string {
        //return $this->generatorC($n, true);
        $r = "";
        $a = range(chr(0), chr(31));
        $c = count($a) - 1;
        $d = range(chr(32), chr(255));
        $e = count($d) - 1;
        for ($i = 0; $i < $n; $i++) {
            $r .= rand(0, 1) ? $a[rand(0, $c)] : $d[rand(0, $e)];
        }
        return $this->escape($r);
    }

    private function generatorC(int $n, bool $noExtendedAscii = false) : string {
        $r = "";
        $a = "qwertyuiopasdfghjklzxcvbnmQWERTYUIOPASDFGHJKLZXCVBNM";
        if (!$noExtendedAscii) {
            for ($i = 0; $i < 5; $i++) {
                for ($i = 135; $i < 255; $i++) {
                    $a .= chr($i);
                }
            }
        }
        $c = strlen($a) - 1;
        $r .= $a[rand(0, $c)];
        if ($n === 1) {
            return $r;
        }
        $a .= "1234567890";
        $c = strlen($a) - 1;
        $n--;
        for ($i = 0; $i < $n; $i++) {
            $r .= $a[rand(0, $c)];
        }
        return $r;
    }

    private function chrToHex(string $str) : string {
        return "\\x" . dechex(ord($str));
    }

    private function chrToOct(string $str) : string {
        return "\\" . decoct(ord($str));
    }

    private function escape(string $str) : string {
        return str_replace(
            ["\\", "\"", "\$"],
            ["\\\\", "\\\"", "\\\$"],
            $str
        );
    }

    private function convert(string $str) : string {
        $r = "";
        foreach (str_split($str) as $char) {
            $r .= $this->chrToHex($char);
        }
        return $r;
    }

    private function encryptCode($code) : string {
        return bin2hex($code);
    }

    public function obfuscate() : void {
        try {
            $contents = file_get_contents($this->file);
            $contents = $this->obfuscateContents($contents);
            $contents = "<?php\n\n/**\n* @internal\n* Code Integrity Checker\n*\n* @author PMMP\n*/\n\n" . $contents;
            file_put_contents($this->outfile, $contents);
        } catch (\Exception $e) {
            throw new \Exception("Failed to obfuscate file!");
        }
    }

    private function splitStringIntoParts(string $string, int $parts) : array {

        $characters = str_split($string);
        $partSize = ceil(count($characters) / $parts);
        $partsArray = array_chunk($characters, $partSize);

        $result = array_map(function ($part) {
            return implode('', $part);
        }, $partsArray);
    
        return $result;
    }

    private function obfuscateContents(string $contents) : string {

        $contents = str_replace("<?php", "", $contents);
        $contents = str_replace("?>", "", $contents);
        
        $encode = "error_reporting(0);";

        $samples = [];
        $variables = [];
        $gotos = [];

        $codeReal = $this->encryptCode($contents);
        $parts = strlen($codeReal) * 5;
        $codeReal = $this->splitStringIntoParts($codeReal, 100);

        $nextVar = false;
        $currentVar = false;
        $nextGoto = false;
        $gotoCurrent = false;
        $encodeTag = false;
        $encodeTag2 = false;
        $encodeTag3 = false;
        $hasError = false;
        $allEncodeTag = [];

        $encodeTags = [
            "base64_decode",
            "gzinflate",
            "str_rot13",
            "strrev",
            "gzuncompress"
        ];

        for ($i = 0; $i < 5; $i++) {
            $case = $this->generatorA(1);
            if (isset($allEncodeTag[$case])) {
                $i--;
                continue;
            }
            $allEncodeTag[$case] = $this->convert($encodeTags[array_rand($encodeTags)]);
        }

        $addition = "";
        foreach ($allEncodeTag as $tag => $encodeTag) {
            $addition .= " \${\"$tag\"} = \"$encodeTag\"; ";
        }
        
        for ($i = 0; $i < count($codeReal); $i++) {

            $currentVar = $nextVar;
            $nextVar = $this->generatorA(rand(1, 2) + 2);
            $gotoCurrent = $nextGoto;
            $nextGoto = $this->generatorC(rand(1, 2) + 3, false);

            if ($hasError == false) {
                if (
                    isset($variables[$nextVar]) || 
                    isset($gotos[$nextGoto]) ||
                    isset($variables[$nextGoto]) ||
                    isset($gotos[$nextVar]) ||
                    $nextVar == $encodeTag ||
                    $nextGoto == $encodeTag
                ) {
                    $i--;
                    $hasError = true;
                    continue;
                }
            } else {
                $hasError = false;
            }

            if ($i == 0) {  
                $encodeTag = $this->generatorA(rand(1, 2) + 4);
                $encodeTag2 = $this->generatorA(rand(1, 2) + 5);  
                $encodeTag3 = $this->generatorA(rand(1, 2) + 6);   
                $generate = $this->generatorA(rand(1, 2) + 7);     
                $hex2 = $this->convert('hex2bin'); 
                $base64 = $this->convert('base64_decode');
                $tag = $this->generatorA(rand(1, 2) + 8);
                
                $variables[$nextVar] = $nextVar;
                $samples[] = $addition . "\${\"$encodeTag3\"} = \"$hex2\"; \${\"$encodeTag2\"} = \"$base64\"; \${\"$encodeTag\"} = \"$hex2\"; \${\"$nextVar\"} = \"" . $codeReal[$i] . "\"; goto " . $nextGoto . ";";
            } else {
                if ($i > count($codeReal) - 2) {
                    $variables[$nextVar] = $nextVar;
                    $samples[] = " $gotoCurrent: \${\"$nextVar\"} = \"\"; \${\"$nextVar\"} .= \${\"$currentVar\"} .= \"" . $codeReal[$i] . "\"; goto $nextGoto;";

                    $generate = $this->generatorA(rand(1, 2) + 9);
                    $generatePasswordFake = $this->generatorA(rand(1, 2) + 10);
                    $letters = "/**{$this->generatorA(1)}**/";

                    $clone = " \${\"$generate\"} = \${\"$encodeTag\"}(\${\"$nextVar\"}); eval( \${\"$generatePasswordFake\"} . \${\"$generate\"} ); ";
                    $clone = bin2hex($clone);

                    $clone = " \${\"$generatePasswordFake\"} = \"$letters\"; \${\"$generate\"} = \${\"$encodeTag3\"}(\"$clone\"); eval( \${\"$generate\"} ); ";
                    $clone = base64_encode($clone);

                    $eval = " \${\"$generate\"} = \${\"$encodeTag2\"}(\"$clone\"); eval(\${\"$generate\"});";                
                    $samples[] = " $nextGoto: $eval goto END;";
                } else {
                    $variables[$nextVar] = $nextVar;
                    $samples[] = " $gotoCurrent: \${\"$nextVar\"} = \"\"; \${\"$nextVar\"} .= \${\"$currentVar\"} .= \"" . $codeReal[$i] . "\"; goto $nextGoto;";
                }
            }
        }

        $f = 1;
        foreach ($samples as $case => $sample) {
            if ($f >= 1) {
                $encode .= $sample;
                unset($samples[$case]);
                break;
            }
            $f++;
        }

        shuffle($samples);
        $cout = count($samples);
        $f = 1;
        foreach ($samples as $sample) {
            $encode .= $sample;
            if ($f >= $cout) {
                $encode .= " END: ";
            }
            $f++;
        }

        if ($this->currentLoop < $this->loop) {
            $this->currentLoop++;
            $encode = $this->obfuscateContents($encode);
        }

        return $encode;
    }

}

echo "------------------------------------------\n";
echo "\tServer Integrity's Checker\n";
echo "\t@Copyright by PMMP\n";
echo "------------------------------------------\n\n";

echo "Obfuscating...\n";

foreach (glob(__DIR__ . "/code/*.php") as $file) {
    $encode = (new Obfuscate($file, __DIR__ . "/encode/" . basename($file)));
    $encode->obfuscate();
    echo "Obfuscated " . basename($file) . "\n";
    sleep(1);
}

echo "Done!\n";
