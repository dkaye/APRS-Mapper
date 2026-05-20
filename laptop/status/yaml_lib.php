<?php
function yamlVal(string $s): string {
    $s = trim($s);
    if ($s === '' || $s === "''" || $s === '""') return '';
    if (strlen($s) >= 2) {
        if ($s[0] === '"' && $s[-1] === '"')
            return str_replace(['\\"', '\\\\'], ['"', '\\'], substr($s, 1, -1));
        if ($s[0] === "'" && $s[-1] === "'")
            return str_replace("''", "'", substr($s, 1, -1));
    }
    return $s;
}

function yamlStr(string $s): string {
    if ($s === '') return "''";
    if (preg_match('/[^a-zA-Z0-9._\-]/', $s))
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"';
    return $s;
}

function loadDevices(string $file): array {
    if (!file_exists($file)) return [];
    $fp = fopen($file, 'r');
    if (!$fp) return [];
    flock($fp, LOCK_SH);
    $raw = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    $devices = [];
    foreach (preg_split('/^(?=- )/m', $raw, -1, PREG_SPLIT_NO_EMPTY) as $block) {
        $dev = ['name' => '', 'host' => '', 'ip' => '', 'group' => '', 'enabled' => true, 'ssh_user' => '', 'ssh_pass' => ''];
        foreach (explode("\n", $block) as $line) {
            if (preg_match('/^[\s\-]*(\w+):\s*(.*)$/', $line, $m)) {
                $k = $m[1]; $v = yamlVal(trim($m[2]));
                if ($k === 'enabled') $dev['enabled'] = ($v === 'true' || $v === '1');
                elseif (array_key_exists($k, $dev)) $dev[$k] = $v;
            }
        }
        if ($dev['ip'] !== '') $devices[] = $dev;
    }
    return $devices;
}

function saveDevices(string $file, array $devices): bool {
    $out = '';
    foreach ($devices as $d) {
        $out .= '- name: '    . yamlStr((string)($d['name']    ?? '')) . "\n";
        $out .= '  host: '    . yamlStr((string)($d['host']    ?? '')) . "\n";
        $out .= '  ip: '      . yamlStr((string)($d['ip']      ?? '')) . "\n";
        $out .= '  group: '   . yamlStr((string)($d['group']   ?? '')) . "\n";
        $out .= '  enabled: ' . (($d['enabled'] ?? true) ? 'true' : 'false') . "\n";
        if (!empty($d['ssh_user'])) $out .= '  ssh_user: ' . yamlStr((string)$d['ssh_user']) . "\n";
        if (!empty($d['ssh_pass'])) $out .= '  ssh_pass: ' . yamlStr((string)$d['ssh_pass']) . "\n";
        $out .= "\n";
    }
    $fp = @fopen($file, 'c');
    if (!$fp) return false;
    if (!flock($fp, LOCK_EX)) { fclose($fp); return false; }
    ftruncate($fp, 0); rewind($fp);
    fwrite($fp, $out);
    fflush($fp); flock($fp, LOCK_UN); fclose($fp);
    @chmod($file, 0664);
    return true;
}
