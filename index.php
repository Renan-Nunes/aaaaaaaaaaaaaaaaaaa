<?php
// Configuração de todas as Estações Gerenciadas (Originais + Outros Grupos)
$stations = [
    ["id" => 1, "name" => "Estação Gerenciada 1", "ip" => "10.2.168.242", "community" => "public"],
    ["id" => 2, "name" => "Estação Gerenciada 2", "ip" => "10.2.169.29", "community" => "public"],
    ["id" => 3, "name" => "Estação Gerenciada 3", "ip" => "10.2.169.37", "community" => "public"],
    ["id" => 4, "name" => "Estação Grupo 1", "ip" => "10.2.168.68", "community" => "public"],
    ["id" => 5, "name" => "Estação Grupo 2", "ip" => "10.2.168.52", "community" => "public"],
    ["id" => 6, "name" => "Estação Grupo 3", "ip" => "10.2.168.25", "community" => "public"]
];

function getSnmpData($ip, $oid, $community = "public") {
    $cmd = "snmpget -v2c -c $community -t 1 -r 1 $ip $oid 2>&1";
    $output = shell_exec($cmd);
    if ($output && strpos($output, "Timeout") === false && strpos($output, "No Response") === false) {
        $parts = explode("=", $output);
        if (isset($parts[1])) {
            return trim($parts[1]);
        }
    }
    return null;
}

function formatBytes($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' bytes';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>NMS Dashboard - Gerenciamento de Redes</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <meta http-equiv="refresh" content="10"> <!-- Atualiza a cada 10 segundos -->
</head>
<body class="bg-light">
    <div class="container py-4">
        <header class="pb-3 mb-4 border-bottom">
            <h1 class="h3 text-primary fw-bold">NMS Dashboard - Monitoramento de Estações</h1>
            <p class="text-muted m-0">Atualização automática a cada 10 segundos | Protocolo SNMP v2c</p>
        </header>

        <div class="row">
            <?php foreach ($stations as $st): ?>
                <?php 
                    $ip = $st["ip"];
                    $community = $st["community"];
                    
                    // a) Descrição completa (sysDescr)
                    $rawDescr = getSnmpData($ip, "1.3.6.1.2.1.1.1.0", $community);
                    $sysDescr = $rawDescr ? str_replace("STRING: ", "", $rawDescr) : "Offline / Sem Resposta";

                    // b) Ocupação de CPU (Média simples via hrProcessorLoad)
                    $rawCpu = getSnmpData($ip, "1.3.6.1.2.1.25.3.3.1.2.1", $community);
                    $cpuLoad = $rawCpu ? intval(filter_var($rawCpu, FILTER_SANITIZE_NUMBER_INT)) : 0;

                    // c) Memória Total x Ocupada (UCD-SNMP MIB)
                    $rawMemTotal = getSnmpData($ip, "1.3.6.1.4.1.2021.4.5.0", $community); // memTotalReal
                    $rawMemAvail = getSnmpData($ip, "1.3.6.1.4.1.2021.4.6.0", $community); // memAvailReal
                    
                    $memTotal = $rawMemTotal ? intval(filter_var($rawMemTotal, FILTER_SANITIZE_NUMBER_INT)) * 1024 : 0;
                    $memAvail = $rawMemAvail ? intval(filter_var($rawMemAvail, FILTER_SANITIZE_NUMBER_INT)) * 1024 : 0;
                    $memUsed = $memTotal - $memAvail;
                    $memPercent = $memTotal > 0 ? round(($memUsed / $memTotal) * 100, 1) : 0;

                    // d) Tráfego de Rede (Interfaces ifInOctets e ifOutOctets para ifIndex 2)
                    $inOctets = getSnmpData($ip, "1.3.6.1.2.1.2.2.1.10.2", $community);
                    $outOctets = getSnmpData($ip, "1.3.6.1.2.1.2.2.1.13.2", $community);
                    $netIn = $inOctets ? filter_var($inOctets, FILTER_SANITIZE_NUMBER_INT) : 0;
                    $netOut = $outOctets ? filter_var($outOctets, FILTER_SANITIZE_NUMBER_INT) : 0;

                    // e) Disco (hrStorageSize e hrStorageUsed para índice 31)
                    $diskSizeRaw = getSnmpData($ip, "1.3.6.1.2.1.25.2.3.1.5.31", $community);
                    $diskUsedRaw = getSnmpData($ip, "1.3.6.1.2.1.25.2.3.1.6.31", $community);
                    $diskAlloc   = getSnmpData($ip, "1.3.6.1.2.1.25.2.3.1.4.31", $community);
                    
                    $unitSize = $diskAlloc ? intval(filter_var($diskAlloc, FILTER_SANITIZE_NUMBER_INT)) : 4096;
                    $diskTotalBytes = $diskSizeRaw ? intval(filter_var($diskSizeRaw, FILTER_SANITIZE_NUMBER_INT)) * $unitSize : 0;
                    $diskUsedBytes  = $diskUsedRaw ? intval(filter_var($diskUsedRaw, FILTER_SANITIZE_NUMBER_INT)) * $unitSize : 0;
                    $diskFreeBytes  = $diskTotalBytes - $diskUsedBytes;
                    $diskPercent = $diskTotalBytes > 0 ? round(($diskUsedBytes / $diskTotalBytes) * 100, 1) : 0;
                ?>

                <div class="col-md-4 mb-4">
                    <div class="card shadow-sm h-100 border-<?= ($sysDescr != "Offline / Sem Resposta") ? 'success' : 'danger' ?>">
                        <div class="card-header bg-white d-flex justify-content-between align-items-center">
                            <h5 class="card-title m-0 text-dark fw-bold"><?= $st["name"] ?></h5>
                            <span class="badge bg-<?= ($sysDescr != "Offline / Sem Resposta") ? 'success' : 'danger' ?>">
                                <?= $ip ?>
                            </span>
                        </div>
                        <div class="card-body">
                            <!-- a) Identificação -->
                            <p class="card-text mb-2"><strong>Descrição:</strong><br><small class="text-muted"><?= htmlspecialchars($sysDescr) ?></small></p>
                            <hr>

                            <!-- b) CPU -->
                            <div class="mb-3">
                                <label class="form-label fw-bold">Ocupação de CPU: <strong><?= $cpuLoad ?>%</strong></label>
                                <div class="progress" style="height: 10px;">
                                    <div class="progress-bar bg-warning" role="progressbar" style="width: <?= $cpuLoad ?>%;"></div>
                                </div>
                            </div>

                            <!-- c) Memória -->
                            <div class="mb-3">
                                <label class="form-label fw-bold">Memória (Uso: <?= $memPercent ?>%)</label>
                                <div class="progress mb-1" style="height: 10px;">
                                    <div class="progress-bar bg-info" role="progressbar" style="width: <?= $memPercent ?>%;"></div>
                                </div>
                                <small class="text-muted">Usada: <?= formatBytes($memUsed) ?> / Total: <?= formatBytes($memTotal) ?></small>
                            </div>

                            <!-- d) Rede -->
                            <div class="mb-3">
                                <label class="form-label fw-bold">Tráfego de Rede (Interfaces)</label>
                                <ul class="list-unstyled small m-0 text-secondary">
                                    <li>📥 Entrada (In): <?= formatBytes($netIn) ?></li>
                                    <li>📤 Saída (Out): <?= formatBytes($netOut) ?></li>
                                </ul>
                            </div>

                            <!-- e) Disco -->
                            <div class="mb-2">
                                <label class="form-label fw-bold">Disco (Uso: <?= $diskPercent ?>%)</label>
                                <div class="progress mb-1" style="height: 10px;">
                                    <div class="progress-bar bg-danger" role="progressbar" style="width: <?= $diskPercent ?>%;"></div>
                                </div>
                                <small class="text-muted">Livre: <?= formatBytes($diskFreeBytes) ?> / Total: <?= formatBytes($diskTotalBytes) ?></small>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</body>
</html>