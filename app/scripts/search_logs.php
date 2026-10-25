<?php
/**
 * Durchsucht alle Log-Dateien nach Reservierungsnummern.
 *
 * Aufruf:
 *   php app/scripts/search_logs.php 1509 711
 *   php app/scripts/search_logs.php 1509 --dir=/pfad/zu/logging
 *   php app/scripts/search_logs.php 1509 --context   (volle JSON-Kontext-Ausgabe)
 *
 * Sucht in allen *.log-Dateien (inkl. rotierter Tagesdateien) im logging/-Verzeichnis.
 * IDs werden als ganze Zahlen mit Wortgrenze gematcht, damit z. B. "711" nicht
 * fälschlich in "17110" gefunden wird.
 */

$args = array_slice($argv, 1);

$dir = __DIR__ . '/../../logging';
$showContext = false;
$ids = [];
$ipFilters = [];

foreach ($args as $arg) {
    if (str_starts_with($arg, '--dir=')) {
        $dir = substr($arg, 6);
    } elseif (str_starts_with($arg, '--ip=')) {
        // Komma-getrennte Liste von IPs erlaubt: --ip=1.2.3.4,5.6.7.8
        foreach (explode(',', substr($arg, 5)) as $ip) {
            $ip = trim($ip);
            if ($ip !== '') {
                $ipFilters[] = $ip;
            }
        }
    } elseif ($arg === '--context') {
        $showContext = true;
    } elseif (ctype_digit($arg)) {
        $ids[] = (int)$arg;
    } else {
        fwrite(STDERR, "Unbekanntes Argument: $arg\n");
    }
}

if (empty($ids) && empty($ipFilters)) {
    fwrite(STDERR, "Verwendung: php search_logs.php [<reservierungs-id> ...] [--ip=IP[,IP]] [--dir=PFAD] [--context]\n");
    fwrite(STDERR, "  Mindestens eine Reservierungs-ID oder ein --ip-Filter ist erforderlich.\n");
    exit(1);
}

$dir = rtrim($dir, '/\\');
if (!is_dir($dir)) {
    fwrite(STDERR, "Log-Verzeichnis nicht gefunden: $dir\n");
    exit(1);
}

// Rekursiv alle *.log-Dateien einsammeln
$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if ($file->isFile() && strtolower($file->getExtension()) === 'log') {
        $files[] = $file->getPathname();
    }
}
sort($files);

if (empty($files)) {
    fwrite(STDERR, "Keine *.log-Dateien in $dir gefunden.\n");
    exit(1);
}

// Regex: jede gesuchte ID mit Wortgrenze (Ziffern), gegen die rohe Zeile als Vorfilter
$idPattern = $ids ? '/\b(' . implode('|', array_map('preg_quote', $ids)) . ')\b/' : null;

$totalMatches = 0;
$idCounts = array_fill_keys($ids, 0);
$ipCounts = array_fill_keys($ipFilters, 0);

foreach ($files as $path) {
    $fh = fopen($path, 'r');
    if (!$fh) {
        continue;
    }
    $lineNo = 0;
    $rel = ltrim(str_replace($dir, '', $path), '/\\');

    while (($line = fgets($fh)) !== false) {
        $lineNo++;
        // Günstiger Vorfilter auf der Rohzeile
        if ($idPattern && !preg_match($idPattern, $line)) {
            continue;
        }
        if ($ipFilters && !ipLineMatches($line, $ipFilters)) {
            continue;
        }

        // Monolog-Zeile zerlegen: [Datum] channel.LEVEL: Nachricht {context} {extra}
        if (!preg_match('/^\[(.*?)\]\s+(\S+?)\.(\w+):\s+(.*)$/', $line, $m)) {
            // Zeile passt aufs Pattern, aber kein Standard-Monolog-Format -> roh ausgeben
            echo "  $rel:$lineNo  " . rtrim($line) . "\n";
            $totalMatches++;
            continue;
        }
        [$full, $date, $channel, $level, $rest] = $m;

        // JSON-Context herausziehen (erstes {...} nach der Nachricht)
        $context = null;
        $message = trim($rest);
        if (preg_match('/^(.*?)(\{.*\})\s*(\[\]|\{.*\})?\s*$/', $rest, $cm)) {
            $message = trim($cm[1]);
            $decoded = json_decode($cm[2], true);
            if (is_array($decoded)) {
                $context = $decoded;
            }
        }

        // Bestätigen, dass eine gesuchte ID tatsächlich als Reservierungsreferenz
        // vorkommt (nicht nur zufällig irgendwo in der Zeile).
        $matchedIds = [];
        if ($ids) {
            $matchedIds = matchedReservationIds($context, $line, $ids);
            if (empty($matchedIds)) {
                continue;
            }
        }

        // IP-Filter bestätigen (exakter Treffer im 'ip'-Feld bzw. auf der Rohzeile).
        $matchedIp = null;
        if ($ipFilters) {
            $lineIp = $context['ip'] ?? null;
            foreach ($ipFilters as $ipf) {
                if ($lineIp === $ipf || ipLineMatches($line, [$ipf])) {
                    $matchedIp = $ipf;
                    break;
                }
            }
            if ($matchedIp === null) {
                continue;
            }
            $ipCounts[$matchedIp]++;
        }
        foreach ($matchedIds as $mid) {
            $idCounts[$mid]++;
        }

        $totalMatches++;
        $labelParts = array_map(fn($i) => "#$i", $matchedIds);
        if ($matchedIp !== null) {
            $labelParts[] = "ip=$matchedIp";
        }
        $idsLabel = implode(', ', $labelParts);
        printf("%s  [%s]  %s.%s  %s\n", $idsLabel, $date, $channel, strtoupper($level), $message);
        echo "    $rel:$lineNo\n";

        if ($context) {
            if ($showContext) {
                echo "    " . str_replace("\n", "\n    ", json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n";
            } else {
                $keys = ['reservation_ids', 'failed_id', 'failed_reason', 'token_valid', 'ip', 'customer_name', 'referer', 'user_agent', 'exception', 'file'];
                $parts = [];
                foreach ($keys as $k) {
                    if (array_key_exists($k, $context) && $context[$k] !== '' && $context[$k] !== null) {
                        $v = $context[$k];
                        if (is_bool($v)) {
                            $v = $v ? 'true' : 'false';
                        } elseif (!is_scalar($v)) {
                            $v = json_encode($v);
                        }
                        $parts[] = "$k=$v";
                    }
                }
                if ($parts) {
                    echo "    " . implode('  ', $parts) . "\n";
                }
            }
        }
        echo "\n";
    }
    fclose($fh);
}

// Zusammenfassung
echo str_repeat('-', 60) . "\n";
echo "Treffer gesamt: $totalMatches\n";
foreach ($idCounts as $id => $cnt) {
    echo "  #$id: $cnt\n";
}
foreach ($ipCounts as $ip => $cnt) {
    echo "  $ip: $cnt\n";
}
echo "Durchsuchte Dateien: " . count($files) . "\n";

/**
 * Schneller Vorfilter: enthält die Rohzeile eine der IPs als ganze IP
 * (mit Nicht-Ziffer-Grenzen, damit 1.2.3.4 nicht in 1.2.3.40 matcht)?
 */
function ipLineMatches(string $rawLine, array $ipFilters): bool
{
    foreach ($ipFilters as $ip) {
        if (preg_match('/(?<![\d.])' . preg_quote($ip, '/') . '(?![\d.])/', $rawLine)) {
            return true;
        }
    }
    return false;
}

/**
 * Prüft, ob eine der gesuchten IDs als Reservierungsreferenz vorkommt.
 * Bevorzugt strukturierte Felder; fällt sonst auf Wortgrenzen-Match der Rohzeile zurück.
 */
function matchedReservationIds(?array $context, string $rawLine, array $ids): array
{
    $found = [];

    if ($context) {
        // Felder, die Reservierungs-IDs enthalten (einzeln oder als Komma-Liste)
        $idFields = ['reservation_ids', 'failed_id', 'reservation_id', 'rid', 'id'];
        $haystack = [];
        foreach ($idFields as $f) {
            if (isset($context[$f])) {
                foreach (explode(',', (string)$context[$f]) as $part) {
                    $part = trim($part);
                    if ($part !== '') {
                        $haystack[] = (int)$part;
                    }
                }
            }
        }
        if ($haystack) {
            foreach ($ids as $id) {
                if (in_array($id, $haystack, true)) {
                    $found[] = $id;
                }
            }
            return array_values(array_unique($found));
        }
    }

    // Kein strukturiertes Feld -> Wortgrenzen-Match auf der Rohzeile
    foreach ($ids as $id) {
        if (preg_match('/\b' . $id . '\b/', $rawLine)) {
            $found[] = $id;
        }
    }
    return array_values(array_unique($found));
}
