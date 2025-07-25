<?php
session_start();

if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');

    // --- Sistem bilgisi fonksiyonları ---
    function getCpuUsage() {
        if (!is_readable('/proc/stat')) {
            return null;
        }
        $stat1 = file_get_contents('/proc/stat');
        usleep(500000);
        $stat2 = file_get_contents('/proc/stat');

        preg_match('/cpu\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/', $stat1, $m1);
        preg_match('/cpu\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/', $stat2, $m2);

        if (!$m1 || !$m2) return null;

        $idle1 = $m1[4] + $m1[5];
        $idle2 = $m2[4] + $m2[5];
        $total1 = array_sum(array_slice($m1, 1, 7));
        $total2 = array_sum(array_slice($m2, 1, 7));

        $totalDiff = $total2 - $total1;
        $idleDiff = $idle2 - $idle1;

        if ($totalDiff == 0) return null;

        $cpuUsage = (1 - $idleDiff / $totalDiff) * 100;
        return round($cpuUsage, 2);
    }

    function getRamUsage() {
        if (!is_readable('/proc/meminfo')) return null;
        $meminfo = file_get_contents('/proc/meminfo');
        preg_match('/MemTotal:\s+(\d+) kB/', $meminfo, $totalMatch);
        preg_match('/MemAvailable:\s+(\d+) kB/', $meminfo, $availMatch);
        if (!$totalMatch || !$availMatch) return null;

        $total = (int)$totalMatch[1];
        $available = (int)$availMatch[1];
        $used = $total - $available;
        $percent = ($used / $total) * 100;

        return [
            'total' => round($total / 1024, 2),
            'used' => round($used / 1024, 2),
            'percent' => round($percent, 2)
        ];
    }

    function getDiskUsage($path = '/') {
        $total = disk_total_space($path);
        $free = disk_free_space($path);
        $used = $total - $free;
        $percent = ($used / $total) * 100;

        return [
            'total' => round($total / 1024 / 1024 / 1024, 2),
            'used' => round($used / 1024 / 1024 / 1024, 2),
            'percent' => round($percent, 2)
        ];
    }

    // --- Sistem bilgileri ---
    $cpu = getCpuUsage();
    $ram = getRamUsage();
    $disk = getDiskUsage('/');

    // --- Ziyaretçi ve DDoS tespiti ---
    $visitorFile = __DIR__ . '/visitors.log';
    $ip = $_SERVER['REMOTE_ADDR'];
    $now = time();

    // Ziyaretçi kaydet
    $visitors = [];
    if (file_exists($visitorFile)) {
        $lines = file($visitorFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            list($vIp, $vTime) = explode('|', $line);
            if ($now - (int)$vTime <= 300) { // son 5 dakika
                $visitors[] = [$vIp, (int)$vTime];
            }
        }
    }
    $visitors[] = [$ip, $now];

    // Dosyaya yaz (sadece son 5 dakikadakiler)
    $linesToWrite = [];
    foreach ($visitors as $v) {
        $linesToWrite[] = $v[0] . '|' . $v[1];
    }
    file_put_contents($visitorFile, implode("\n", $linesToWrite));

    // Anlık ziyaretçi sayısı (farklı IP sayısı)
    $uniqueIps = [];
    foreach ($visitors as $v) {
        $uniqueIps[$v[0]] = true;
    }
    $visitorCount = count($uniqueIps);

    // DDoS tespiti: aynı IP'den son 10 saniyede kaç istek geldiğine bak
    $recentRequests = 0;
    foreach ($visitors as $v) {
        if ($v[0] === $ip && $now - $v[1] <= 10) {
            $recentRequests++;
        }
    }

    $ddosDetected = false;
    $ddosIps = [];

    if ($recentRequests > 20) {
        $ddosDetected = true;
        $ddosIps[] = $ip;

        // Mail gönder (sadece 10 dakikada bir gönder)
        if (empty($_SESSION['ddos_alert_sent']) || $_SESSION['ddos_alert_sent'] + 600 < $now) {
            $_SESSION['ddos_alert_sent'] = $now;

            $to = 'mailadresiniz@mail.com';
            $subject = 'DDoS Saldırısı Tespit Edildi';
            $message = "Sunucunuza DDoS saldırısı tespit edildi.\n\n"
                . "Saldırı yapan IP: $ip\n"
                . "Saldırı tipi: Çok sayıda istek (HTTP TCP/UDP olabilir)\n"
                . "Zaman: " . date('Y-m-d H:i:s') . "\n\n"
                . "Lütfen sunucunuzu kontrol edin.";
            $headers = 'From: no-reply@yourdomain.com' . "\r\n";

            @mail($to, $subject, $message, $headers);
        }
    }

    // --- JSON çıktısı ---
    echo json_encode([
        'cpu' => $cpu,
        'ram' => $ram,
        'disk' => $disk,
        'visitorCount' => $visitorCount,
        'ddosDetected' => $ddosDetected,
        'ddosIps' => $ddosIps
    ]);
    exit;
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Sunucu Durum Paneli - Hacker Style</title>
<style>
    @import url('https://fonts.googleapis.com/css2?family=Share+Tech+Mono&display=swap');
    body {
        background-color: #000000;
        color: #00ff00;
        font-family: 'Share Tech Mono', monospace;
        margin: 0;
        padding: 20px;
        user-select: none;
        min-height: 100vh;
        display: flex;
        flex-direction: column;
        align-items: center;
    }
    h1 {
        font-size: 3rem;
        margin-bottom: 20px;
        text-shadow: 0 0 10px #00ff00;
        letter-spacing: 3px;
    }
    .container {
        display: flex;
        gap: 20px;
        flex-wrap: wrap;
        justify-content: center;
        width: 100%;
        max-width: 1300px;
    }
    table {
        border-collapse: collapse;
        width: 380px;
        border: 2px solid #00ff00;
        border-radius: 8px;
        box-shadow: 0 0 15px #00ff00;
        background: #001100;
        transition: all 0.5s ease;
        color: #00ff00;
    }
    table.high-usage {
        border-color: #ff0000;
        box-shadow: 0 0 20px #ff0000;
        color: #ff0000;
        background: #330000;
    }
    caption {
        font-size: 1.8rem;
        font-weight: 700;
        padding: 12px 0;
        border-bottom: 1px solid currentColor;
        letter-spacing: 2px;
    }
    th, td {
        padding: 12px 20px;
        text-align: center;
        font-size: 1.3rem;
        border-bottom: 1px solid #003300;
        transition: color 0.5s ease;
    }
    table.high-usage th, table.high-usage td {
        border-color: #660000;
    }
    tr:last-child td {
        border-bottom: none;
    }
    .bar-container {
        background: #003300;
        border-radius: 10px;
        height: 16px;
        width: 100%;
        box-shadow: inset 0 0 5px currentColor;
        margin-top: 6px;
    }
    .bar-fill {
        height: 100%;
        background: currentColor;
        border-radius: 10px 0 0 10px;
        transition: width 0.4s ease;
    }
    .unit {
        font-size: 1rem;
        font-weight: 600;
        color: inherit;
        margin-left: 5px;
    }
    .note {
        margin-top: 20px;
        font-size: 1rem;
        color: #007700;
        font-style: italic;
        letter-spacing: 1.5px;
    }
    footer {
        margin-top: auto;
        font-size: 0.9rem;
        color: #004400;
        font-family: monospace;
        padding: 10px 0;
    }
    @media (max-width: 1280px) {
        .container {
            justify-content: center;
        }
    }
    @media (max-width: 1200px) {
        .container {
            flex-wrap: wrap;
        }
        table {
            width: 320px;
        }
    }
    @media (max-width: 700px) {
        .container {
            flex-direction: column;
            align-items: center;
        }
        table {
            width: 90%;
            max-width: 400px;
        }
    }
</style>
</head>
<body>

<h1>SERVER STATUS</h1>

<div class="container">
    <table id="cpu-table" aria-label="CPU Usage">
        <caption>CPU KULLANIMI</caption>
        <tr><th>Yüzde</th></tr>
        <tr>
            <td id="cpu-value">
                <div style="font-size:2.8rem; font-weight:900; text-shadow: 0 0 10px currentColor;">
                    -- <span class="unit">%</span>
                </div>
                <div class="bar-container" aria-label="CPU kullanım çubuğu">
                    <div class="bar-fill" style="width: 0%;"></div>
                </div>
            </td>
        </tr>
    </table>

    <table id="ram-table" aria-label="RAM Usage">
        <caption>RAM KULLANIMI</caption>
        <tr>
            <th>Kullanılan</th>
            <th>Toplam</th>
            <th>Yüzde</th>
        </tr>
        <tr>
            <td id="ram-used">-- MB</td>
            <td id="ram-total">-- MB</td>
            <td id="ram-percent">
                -- <span class="unit">%</span>
                <div class="bar-container" aria-label="RAM kullanım çubuğu">
                    <div class="bar-fill" style="width: 0%;"></div>
                </div>
            </td>
        </tr>
    </table>

    <table id="disk-table" aria-label="Disk Usage">
        <caption>DİSK KULLANIMI (/)</caption>
        <tr>
            <th>Kullanılan</th>
            <th>Toplam</th>
            <th>Yüzde</th>
        </tr>
        <tr>
            <td id="disk-used">-- GB</td>
            <td id="disk-total">-- GB</td>
            <td id="disk-percent">
                -- <span class="unit">%</span>
                <div class="bar-container" aria-label="Disk kullanım çubuğu">
                    <div class="bar-fill" style="width: 0%;"></div>
                </div>
            </td>
        </tr>
    </table>
</div>

<div class="note">Veriler 3 saniyede bir güncellenmektedir.</div>

<footer>
    © <?php echo date('Y'); ?> - Server Status Panel | 
    <span id="visitor-count">Ziyaretçi: --</span>
    <span id="ddos-alert" style="color:#ff4444; font-weight:bold; margin-left:20px;"></span>
</footer>

<script>
function updateStatus() {
    fetch('?ajax=1')
        .then(response => response.json())
        .then(data => {
            // CPU
            const cpuVal = data.cpu !== null ? data.cpu.toFixed(2) : '--';
            const cpuTable = document.getElementById('cpu-table');
            document.querySelector('#cpu-value > div:first-child').innerHTML = cpuVal + ' <span class="unit">%</span>';
            document.querySelector('#cpu-value .bar-fill').style.width = (data.cpu !== null ? data.cpu : 0) + '%';

            if (data.cpu !== null && data.cpu >= 70) {
                cpuTable.classList.add('high-usage');
            } else {
                cpuTable.classList.remove('high-usage');
            }

            // RAM
            const ramTable = document.getElementById('ram-table');
            if (data.ram !== null) {
                document.getElementById('ram-used').textContent = data.ram.used + ' MB';
                document.getElementById('ram-total').textContent = data.ram.total + ' MB';
                document.getElementById('ram-percent').childNodes[0].nodeValue = data.ram.percent + ' ';
                document.querySelector('#ram-percent .bar-fill').style.width = data.ram.percent + '%';

                if (data.ram.percent >= 70) {
                    ramTable.classList.add('high-usage');
                } else {
                    ramTable.classList.remove('high-usage');
                }
            } else {
                document.getElementById('ram-used').textContent = 'Desteklenmiyor';
                document.getElementById('ram-total').textContent = 'Desteklenmiyor';
                document.getElementById('ram-percent').childNodes[0].nodeValue = 'Desteklenmiyor ';
                document.querySelector('#ram-percent .bar-fill').style.width = '0%';
                ramTable.classList.remove('high-usage');
            }

            // Disk
            const diskTable = document.getElementById('disk-table');
            document.getElementById('disk-used').textContent = data.disk.used + ' GB';
            document.getElementById('disk-total').textContent = data.disk.total + ' GB';
            document.getElementById('disk-percent').childNodes[0].nodeValue = data.disk.percent + ' ';
            document.querySelector('#disk-percent .bar-fill').style.width = data.disk.percent + '%';

            if (data.disk.percent >= 70) {
                diskTable.classList.add('high-usage');
            } else {
                diskTable.classList.remove('high-usage');
            }

            // Ziyaretçi sayısı güncelle
            document.getElementById('visitor-count').textContent = 'Ziyaretçi: ' + data.visitorCount;

            // DDoS uyarısı göster
            const ddosAlert = document.getElementById('ddos-alert');
            if (data.ddosDetected && data.ddosIps.length > 0) {
                ddosAlert.textContent = 'DDoS saldırısı tespit edildi! IP: ' + data.ddosIps.join(', ');
            } else {
                ddosAlert.textContent = '';
            }
        })
        .catch(err => {
            console.error('Veri alınamadı:', err);
        });
}

updateStatus();
setInterval(updateStatus, 3000);
</script>

</body>
</html>
