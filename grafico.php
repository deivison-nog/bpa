<?php
declare(strict_types=1);

session_start();

function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function normalizeLines(string $content): array
{
    $lines = preg_split('/\r\n|\r|\n/', $content);
    $lines = array_map(fn($l) => rtrim((string)$l, "\r"), $lines);
    return array_values(array_filter($lines, fn($l) => trim($l) !== ''));
}

/**
 * Extrai apenas os campos necessários para os gráficos a partir de uma linha tipo 03.
 */
function extractRecord03Chart(string $line): ?array
{
    $line = trim($line);
    if (substr($line, 0, 2) !== '03') {
        return null;
    }

    $racaMap = [
        '1' => 'Branco',
        '2' => 'Preto',
        '3' => 'Pardo',
        '4' => 'Amarelo',
        '5' => 'Indígena',
    ];

    $sexoMap = [
        'M' => 'Masculino',
        'F' => 'Feminino',
        'I' => 'Ignorado',
    ];

    $cnsProfissional = substr($line, 15, 15);
    $procedimento    = substr($line, 49, 10);
    $sexoCodigo      = substr($line, 74, 1);
    $racaRaw         = substr($line, 150, 2);
    $racaCodigo      = ltrim($racaRaw, '0');

    return [
        'cns_profissional' => trim($cnsProfissional),
        'procedimento'     => $procedimento,
        'sexo'             => $sexoMap[$sexoCodigo] ?? 'Ignorado',
        'raca_cor'         => ($racaCodigo !== '') ? ($racaMap[$racaCodigo] ?? "Código $racaCodigo") : 'Não informado',
    ];
}

$content  = (string)($_SESSION['bpa_content'] ?? '');
$records  = [];

if ($content !== '') {
    foreach (normalizeLines($content) as $line) {
        $r = extractRecord03Chart($line);
        if ($r !== null) {
            $records[] = $r;
        }
    }
}

// ── Gráfico 1: Sexo ─────────────────────────────────────────────────────────
$sexoCounts = [];
foreach ($records as $r) {
    $sexoCounts[$r['sexo']] = ($sexoCounts[$r['sexo']] ?? 0) + 1;
}
arsort($sexoCounts);

// ── Gráfico 2: Procedimentos por código ─────────────────────────────────────
$procCounts = [];
foreach ($records as $r) {
    $procCounts[$r['procedimento']] = ($procCounts[$r['procedimento']] ?? 0) + 1;
}
arsort($procCounts);

// ── Gráfico 3: Procedimentos por profissional ────────────────────────────────
$profCounts = [];
foreach ($records as $r) {
    $cns = $r['cns_profissional'];
    $label = 'CNS ...' . substr($cns, -6);
    $profCounts[$label] = ($profCounts[$label] ?? 0) + 1;
}
arsort($profCounts);

// ── Gráfico 4: Raça/Cor ──────────────────────────────────────────────────────
$racaCounts = [];
foreach ($records as $r) {
    $racaCounts[$r['raca_cor']] = ($racaCounts[$r['raca_cor']] ?? 0) + 1;
}
arsort($racaCounts);

$total = count($records);

// Helper: encode array for Chart.js JSON
function jsonLabels(array $data): string
{
    return json_encode(array_keys($data), JSON_UNESCAPED_UNICODE);
}
function jsonValues(array $data): string
{
    return json_encode(array_values($data));
}

$pieColors = [
    '#4e79a7','#f28e2b','#e15759','#76b7b2','#59a14f',
    '#edc948','#b07aa1','#ff9da7','#9c755f','#bab0ac',
];
function jsonColors(int $count, array $palette): string
{
    $colors = [];
    for ($i = 0; $i < $count; $i++) {
        $colors[] = $palette[$i % count($palette)];
    }
    return json_encode($colors);
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gráficos BPA-I</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f5f7fb; }
        .chart-card { background: #fff; border-radius: .75rem; box-shadow: 0 2px 8px rgba(0,0,0,.08); padding: 1.5rem; }
        .chart-title { font-weight: 600; font-size: 1rem; margin-bottom: 1rem; color: #374151; }
        canvas { max-height: 340px; }
    </style>
</head>
<body>
<div class="container-fluid py-4">

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h1 class="h3 mb-1">Gráficos — Produção BPA-I</h1>
                <div class="text-muted">
                    Análise dos registros individualizados (tipo 03) do arquivo carregado.
                    <?php if ($total > 0): ?>
                        Total de atendimentos: <strong><?= $total ?></strong>
                    <?php endif; ?>
                </div>
            </div>
            <a href="index.php" class="btn btn-outline-secondary">← Voltar ao Visualizador</a>
        </div>
    </div>

    <?php if ($total === 0): ?>
        <div class="alert alert-warning">
            Nenhum registro BPA-I (tipo 03) encontrado.
            <a href="index.php" class="alert-link">Carregue um arquivo na página principal</a> para visualizar os gráficos.
        </div>
    <?php else: ?>

    <div class="row g-4">

        <!-- Gráfico 1: Sexo (Pizza) -->
        <div class="col-xl-6">
            <div class="chart-card h-100">
                <div class="chart-title">👥 Pacientes por Sexo</div>
                <canvas id="chartSexo"></canvas>
            </div>
        </div>

        <!-- Gráfico 4: Raça/Cor (Pizza) -->
        <div class="col-xl-6">
            <div class="chart-card h-100">
                <div class="chart-title">🎨 Atendimentos por Raça/Cor</div>
                <canvas id="chartRaca"></canvas>
            </div>
        </div>

        <!-- Gráfico 2: Procedimentos por código (Pizza) -->
        <div class="col-xl-6">
            <div class="chart-card h-100">
                <div class="chart-title">🔢 Procedimentos por Código SIGTAP</div>
                <canvas id="chartProc"></canvas>
            </div>
        </div>

        <!-- Gráfico 3: Procedimentos por profissional (Coluna) -->
        <div class="col-xl-6">
            <div class="chart-card h-100">
                <div class="chart-title">🩺 Procedimentos por Profissional</div>
                <canvas id="chartProf"></canvas>
            </div>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
    <script>
    Chart.defaults.font.family = "'Segoe UI', system-ui, sans-serif";
    Chart.defaults.plugins.legend.position = 'right';

    const pieOptions = (title) => ({
        responsive: true,
        plugins: {
            legend: { position: 'right' },
            tooltip: {
                callbacks: {
                    label: (ctx) => {
                        const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                        const pct = total > 0 ? ((ctx.parsed / total) * 100).toFixed(1) : 0;
                        return ` ${ctx.label}: ${ctx.parsed} (${pct}%)`;
                    }
                }
            }
        }
    });

    // Sexo
    new Chart(document.getElementById('chartSexo'), {
        type: 'pie',
        data: {
            labels: <?= jsonLabels($sexoCounts) ?>,
            datasets: [{
                data: <?= jsonValues($sexoCounts) ?>,
                backgroundColor: <?= jsonColors(count($sexoCounts), $pieColors) ?>,
                borderWidth: 2,
                borderColor: '#fff'
            }]
        },
        options: pieOptions('Pacientes por Sexo')
    });

    // Raça/Cor
    new Chart(document.getElementById('chartRaca'), {
        type: 'pie',
        data: {
            labels: <?= jsonLabels($racaCounts) ?>,
            datasets: [{
                data: <?= jsonValues($racaCounts) ?>,
                backgroundColor: <?= jsonColors(count($racaCounts), $pieColors) ?>,
                borderWidth: 2,
                borderColor: '#fff'
            }]
        },
        options: pieOptions('Atendimentos por Raça/Cor')
    });

    // Procedimentos por código
    new Chart(document.getElementById('chartProc'), {
        type: 'pie',
        data: {
            labels: <?= jsonLabels($procCounts) ?>,
            datasets: [{
                data: <?= jsonValues($procCounts) ?>,
                backgroundColor: <?= jsonColors(count($procCounts), $pieColors) ?>,
                borderWidth: 2,
                borderColor: '#fff'
            }]
        },
        options: pieOptions('Procedimentos por Código')
    });

    // Procedimentos por profissional (coluna/barra)
    new Chart(document.getElementById('chartProf'), {
        type: 'bar',
        data: {
            labels: <?= jsonLabels($profCounts) ?>,
            datasets: [{
                label: 'Procedimentos',
                data: <?= jsonValues($profCounts) ?>,
                backgroundColor: <?= jsonColors(count($profCounts), $pieColors) ?>,
                borderRadius: 4,
                borderSkipped: false
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { precision: 0 },
                    grid: { color: '#e5e7eb' }
                },
                x: {
                    grid: { display: false }
                }
            }
        }
    });
    </script>

    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
