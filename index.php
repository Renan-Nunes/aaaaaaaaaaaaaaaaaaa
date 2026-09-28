<?php
if (isset($_GET['ajax']) &&$_GET['ajax'] == '1') {
    header('Content-Type: application/json');
    $stations = [
        ["id" => 1, "name" => "Estação Gerenciada 1", "ip" => "10.2.168.242", "community" => "public"],
        ["id" => 2, "name" => "Estação Gerenciada 2", "ip" => "10.2.169.29", "community" => "public"],
        ["id" => 3, "name" => "Estação Gerenciada 3", "ip" => "10.2.169.37", "community" => "public"],
        ["id" => 4, "name" => "Estação Grupo 1", "ip" => "10.2.168.68", "community" => "public"],
        ["id" => 5, "name" => "Estação Grupo 2", "ip" => "10.2.168.52", "community" => "public"],
        ["id" => 6, "name" => "Estação Grupo 3", "ip" => "10.2.168.25", "community" => "public"]
    ];

    function getSnmpData($ip, $oid,$community = "public") {
        $cmd = "snmpget -v2c -c $community -t 1 -r 1 $ip$oid 2>&1";
        $output = shell_exec($cmd);
        if (!$output || strpos($output, "Timeout") !== false \vert{}\vert{} strpos($output, "No Response") !== false || strpos($output, "Usage:") !== false \vert{}\vert{} strpos($output, "value) x:") !== false) {
            return null;
        }
        $parts = explode("=", $output);
        if (isset($parts[1])) {
            return trim($parts[1]);
        }
        return null;
    }

    function formatBytes($bytes) {
        if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
        elseif ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
        elseif ($bytes >= 1024) return number_format($bytes / 1024, 2) . ' KB';
        else return $bytes . ' bytes';
    }

    $results = [];
    foreach ($stations as$st) {
        $ip =$st["ip"];
        $community =$st["community"];
        
        // a) Descrição com sanitização ajustada para remover o prefixo e as aspas
        $rawDescr = getSnmpData($ip, "1.3.6.1.2.1.1.1.0", $community);
        if ($rawDescr) {
            $sysDescr = preg_replace('/^(STRING\vert{}Hex-STRING\vert{}INTEGER\vert{}Counter32\vert{}Timeticks):\s*/i', '',$rawDescr);
            $sysDescr = trim($sysDescr, '"'); // Remove aspas do início e fim
            $isOnline = true;
        } else {
            $sysDescr = "Offline / Sem Resposta";
            $isOnline = false;
        }

        // b) CPU
        $rawCpu = getSnmpData($ip, "1.3.6.1.2.1.25.3.3.1.2.1", $community);$cpuLoad = $rawCpu ? intval(filter_var($rawCpu, FILTER_SANITIZE_NUMBER_INT)) : 0;

        // c) Memória
        $rawMemTotal = getSnmpData($ip, "1.3.6.1.4.1.2021.4.5.0", $community);
        $rawMemAvail = getSnmpData($ip, "1.3.6.1.4.1.2021.4.6.0", $community);$memTotal = $rawMemTotal ? intval(filter_var($rawMemTotal, FILTER_SANITIZE_NUMBER_INT)) * 1024 : 0;
        $memAvail = $rawMemAvail ? intval(filter_var($rawMemAvail, FILTER_SANITIZE_NUMBER_INT)) * 1024 : 0;
        $memUsed = $memTotal -$memAvail;
        $memPercent =$memTotal > 0 ? round(($memUsed / $memTotal) * 100, 1) : 0;

        // d) Rede
        $inOctets = getSnmpData($ip, "1.3.6.1.2.1.2.2.1.10.2", $community);$outOctets = getSnmpData($ip, "1.3.6.1.2.1.2.2.1.13.2", $community);
        $netIn =$inOctets ? formatBytes(filter_var($inOctets, FILTER_SANITIZE_NUMBER_INT)) : '0 bytes';$netOut = $outOctets ? formatBytes(filter_var($outOctets, FILTER_SANITIZE_NUMBER_INT)) : '0 bytes';

        // e) Disco
        $diskSizeRaw = getSnmpData($ip, "1.3.6.1.2.1.25.2.3.1.5.31", $community);$diskUsedRaw = getSnmpData($ip, "1.3.6.1.2.1.25.2.3.1.6.31", $community);
        $diskAlloc   = getSnmpData($ip, "1.3.6.1.2.1.25.2.3.1.4.31", $community);$unitSize = $diskAlloc ? intval(filter_var($diskAlloc, FILTER_SANITIZE_NUMBER_INT)) : 4096;
        $diskTotalBytes =$diskSizeRaw ? intval(filter_var($diskSizeRaw, FILTER_SANITIZE_NUMBER_INT)) *$unitSize : 0;
        $diskUsedBytes  =$diskUsedRaw ? intval(filter_var($diskUsedRaw, FILTER_SANITIZE_NUMBER_INT)) *$unitSize : 0;
        $diskFreeBytes  = $diskTotalBytes -$diskUsedBytes;
        $diskPercent =$diskTotalBytes > 0 ? round(($diskUsedBytes / $diskTotalBytes) * 100, 1) : 0;

        $results[] = [
            "id" => $st["id"], "name" => $st["name"], "ip" => $ip, "isOnline" => $isOnline,
            "sysDescr" => $sysDescr, "cpuLoad" => $cpuLoad, "memPercent" => $memPercent,
            "memUsed" => formatBytes($memUsed), "memTotal" => formatBytes($memTotal),
            "netIn" => $netIn, "netOut" => $netOut, "diskPercent" => $diskPercent,
            "diskFree" => formatBytes($diskFreeBytes), "diskTotal" => formatBytes($diskTotalBytes)
        ];
    }
    echo json_encode($results);
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>NMS Dashboard - Polling em Arquivo Único</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container py-4">
        <header class="pb-3 mb-4 border-bottom d-flex justify-content-between align-items-center">
            <div>
                <h1 class="h3 text-primary fw-bold">NMS Dashboard - Monitoramento em Tempo Real</h1>
                <p class="text-muted m-0">Atualização automática via Polling a cada 10s | Protocolo SNMP v2c</p>
            </div>
            <div id="status-sync" class="spinner-border text-primary spinner-border-sm" role="status" title="Atualizando..."></div>
        </header>
        <div class="row" id="dashboard-container">
            <div class="text-center py-5">
                <div class="spinner-border text-primary" role="status"></div>
                <p class="mt-2 text-muted">Carregando estações gerenciadas...</p>
            </div>
        </div>
    </div>
    <script>
        function fetchNmsData() {
            const spinner = document.getElementById('status-sync');
            spinner.style.display = 'inline-block';
            fetch('index.php?ajax=1')
                .then(response => response.json())
                .then(data => {
                    const container = document.getElementById('dashboard-container');
                    container.innerHTML = '';
                    data.forEach(st => {
                        const borderClass = st.isOnline ? 'success' : 'danger';
                        const badgeClass = st.isOnline ? 'success' : 'danger';
                        const cardHTML = `
                            <div class="col-md-4 mb-4">
                                <div class="card shadow-sm h-100 border-${borderClass}">
                                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                                        <h5 class="card-title m-0 text-dark fw-bold">${st.name}</h5>
                                        <span class="badge bg-${badgeClass}">${st.ip}</span>
                                    </div>
                                    <div class="card-body">
                                        <p class="card-text mb-2"><strong>Descrição:</strong><br><small class="text-muted">${st.sysDescr}</small></p>
                                        <hr>
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">Ocupação de CPU: <strong>${st.cpuLoad}%</strong></label>
                                            <div class="progress" style="height: 10px;">
                                                <div class="progress-bar bg-warning" role="progressbar" style="width: ${st.cpuLoad}%;"></div>
                                            </div>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">Memória (Uso: ${st.memPercent}%)</label>
                                            <div class="progress mb-1" style="height: 10px;">
                                                <div class="progress-bar bg-info" role="progressbar" style="width: ${st.memPercent}%;"></div>
                                            </div>
                                            <small class="text-muted">Usada: ${st.memUsed} / Total: ${st.memTotal}</small>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">Tráfego de Rede (Interfaces)</label>
                                            <ul class="list-unstyled small m-0 text-secondary">
                                                <li>📥 Entrada (In): ${st.netIn}</li>
                                                <li>📤 Saída (Out): ${st.netOut}</li>
                                            </ul>
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label fw-bold">Disco (Uso: ${st.diskPercent}%)</label>
                                            <div class="progress mb-1" style="height: 10px;">
                                                <div class="progress-bar bg-danger" role="progressbar" style="width: ${st.diskPercent}%;"></div>
                                            </div>
                                            <small class="text-muted">Livre: ${st.diskFree} / Total: ${st.diskTotal}</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        `;
                        container.innerHTML += cardHTML;
                    });
                    spinner.style.display = 'none';
                })
                .catch(error => {
                    console.error('Erro ao atualizar dados:', error);
                    spinner.style.display = 'none';
                });
        }
        fetchNmsData();
        setInterval(fetchNmsData, 10000);
    </script>
</body>
</html>
