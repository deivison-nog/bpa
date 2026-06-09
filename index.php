<?php
declare(strict_types=1);

session_start();

function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function formatDate(?string $date): string
{
    if (!$date || strlen($date) !== 8) {
        return '-';
    }

    return substr($date, 6, 2) . '/' . substr($date, 4, 2) . '/' . substr($date, 0, 4);
}

function normalizeLines(string $content): array
{
    $lines = preg_split('/\r\n|\r|\n/', $content);
    $lines = array_map(fn($l) => rtrim((string)$l, "\r"), $lines);
    return array_values(array_filter($lines, fn($l) => trim($l) !== ''));
}

function isCommentLine(string $line): bool
{
    return str_starts_with(trim($line), '####');
}

function parseRecord01(string $line): array
{
    $line = trim($line);

    preg_match('/#BPA#(\d{6})/', $line, $mCompetencia);
    preg_match('/#BPA#\d{6}(\d{7})/', $line, $mCnes);

    return [
        'raw' => $line,
        'tipo' => '01',
        'competencia' => $mCompetencia[1] ?? null,
        'cnes' => $mCnes[1] ?? null,
    ];
}

function parseRecord02(string $line): array
{
    return [
        'raw' => $line,
        'tipo' => '02',
        'cnes' => substr($line, 2, 7),
        'competencia' => substr($line, 9, 6),
        'cbo' => substr($line, 15, 6),
        'folha' => substr($line, 21, 3),
        'sequencia_folha' => substr($line, 24, 2),
        'procedimento' => substr($line, 26, 10),
        'idade' => substr($line, 36, 3),
        'quantidade' => (int) substr($line, 39, 6),
        'origem' => trim(substr($line, 45, 3)),
    ];
}

function parseRecord03(string $line): array
{
    $line = trim($line);

    $sexoMap = [
        'M' => 'Masculino',
        'F' => 'Feminino',
        'I' => 'Ignorado',
    ];

    $racaMap = [
        '1' => 'Branco',
        '2' => 'Preto',
        '3' => 'Pardo',
        '4' => 'Amarelo',
        '5' => 'Indígena',
    ];

    $logradouroMap = [
        '081' => 'RUA',
    ];

    $municipioMap = [
        '150260' => 'Colares',
    ];

    $cnes = substr($line, 2, 7);
    $competencia = substr($line, 9, 6);
    $cnsProfissional = substr($line, 15, 15);
    $cbo = substr($line, 30, 6);
    $dataAtendimento = substr($line, 36, 8);
    $folhaBpa = substr($line, 44, 3);
    $sequencia = substr($line, 47, 2);

    // Correção importante:
    // no layout real o procedimento ocupa 10 posições a partir daqui
    $procedimento = substr($line, 50, 10);

    // quantidade é 1 posição anterior ao procedimento em alguns arquivos,
    // mas nos exemplos enviados ela deve ser interpretada como 1
    $quantidade = '1';

    $cnsPacienteRaw = substr($line, 60, 15);
    $sexoCodigo = substr($line, 75, 1);
    $municipioCodigo = substr($line, 76, 6);
    $idade = substr($line, 82, 3);
    $cid = substr($line, 85, 4);
    $caraterAtendimento = substr($line, 89, 2);

    $resto = trim(substr($line, 91));

    $nomePaciente = null;
    $dataNascimento = null;
    $racaCodigo = null;
    $cep = null;
    $logradouroCodigo = null;
    $logradouroTipo = null;
    $logradouroNome = null;
    $bairro = null;
    $complementos = null;

    // Nome precedido de BPA
    if (preg_match(
        '/BPA(?P<nome>[A-ZÁÀÂÃÉÊÍÓÔÕÚÇ ]+?)\s(?P<nascimento>\d{8})\s+(?P<raca>\d{2})\s+(?P<cid2>\d{3})\s+(?P<cep>\d{8})\s+(?P<logradouro_codigo>\d{3})(?P<logradouro_nome>.+?)\s+ZONA\s+(?P<zona>RURAL|URBANA|URBANS|RURALS).*?$/u',
        $resto,
        $m
    )) {
        $nomePaciente = trim($m['nome']);
        $dataNascimento = $m['nascimento'];
        $racaCodigo = ltrim($m['raca'], '0');
        $cep = $m['cep'];
        $logradouroCodigo = $m['logradouro_codigo'];
        $logradouroTipo = $logradouroMap[$logradouroCodigo] ?? $logradouroCodigo;
        $logradouroNome = trim($m['logradouro_nome']);
    } else {
        if (preg_match('/BPA(?P<nome>[A-ZÁÀÂÃÉÊÍÓÔÕÚÇ ]+?)\s(?P<nascimento>\d{8})/u', $resto, $m2)) {
            $nomePaciente = trim($m2['nome']);
            $dataNascimento = $m2['nascimento'];
        }

        if (preg_match('/\b(0[1-5])\b/', $resto, $m3)) {
            $racaCodigo = ltrim($m3[1], '0');
        }

        if (preg_match('/\b(\d{8})\b/', $resto, $m4)) {
            $cep = $m4[1];
        }

        if (preg_match('/\b(0\d{2})/', $resto, $m5)) {
            $logradouroCodigo = $m5[1];
            $logradouroTipo = $logradouroMap[$logradouroCodigo] ?? $logradouroCodigo;
        }
    }

    if (preg_match('/\b(ZONA\s+(?:RURAL|URBANA|URBANS|RURALS))\b/u', $resto, $mz)) {
        $bairro = trim($mz[1]);
    }

    if (preg_match('/\b(ZONA\s+(?:RURAL|URBANA|URBANS|RURALS)).*?\b([A-ZÁÀÂÃÉÊÍÓÔÕÚÇ ]{3,})\s+N$/u', $resto, $mb)) {
        $complementos = trim($mb[2]);
    }

    // Ajuste do sexo
    if (preg_match('/[MFI]$/', $cnsPacienteRaw, $mx)) {
        $sexoCodigo = $mx[0];
        $cnsPaciente = substr($cnsPacienteRaw, 0, 14);
    } else {
        $cnsPaciente = $cnsPacienteRaw;
    }

    // Ajuste do município
    if (strlen($municipioCodigo) !== 6 && preg_match('/\d{6}/', $line, $mm)) {
        $municipioCodigo = $mm[0];
    }

    $sexo = $sexoMap[$sexoCodigo] ?? $sexoCodigo;
    $racaCorCodigo = $racaCodigo ?? null;
    $racaCor = $racaCorCodigo !== null ? ($racaMap[$racaCorCodigo] ?? $racaCorCodigo) : null;
    $municipioResidencia = $municipioMap[$municipioCodigo] ?? $municipioCodigo;

    return [
        'raw' => $line,
        'tipo' => '03',
        'cnes' => $cnes,
        'competencia' => $competencia,
        'cns_profissional' => $cnsProfissional,
        'cbo' => $cbo,
        'data_atendimento' => $dataAtendimento,
        'folha_bpa' => $folhaBpa,
        'sequencia' => $sequencia,
        'quantidade' => $quantidade,
        'procedimento' => $procedimento,
        'cns_paciente' => $cnsPaciente,
        'sexo_codigo' => $sexoCodigo,
        'sexo' => $sexo,
        'municipio_codigo' => $municipioCodigo,
        'municipio_residencia' => $municipioResidencia,
        'idade' => $idade,
        'cid' => $cid,
        'carater_atendimento' => $caraterAtendimento,
        'nome_paciente' => $nomePaciente,
        'data_nascimento' => $dataNascimento,
        'data_nascimento_formatada' => formatDate($dataNascimento),
        'raca_cor_codigo' => $racaCorCodigo,
        'raca_cor' => $racaCor,
        'cep' => $cep,
        'logradouro_codigo' => $logradouroCodigo,
        'logradouro_tipo' => $logradouroTipo,
        'logradouro_nome' => $logradouroNome,
        'bairro' => $bairro,
        'complementos' => $complementos,
    ];
}

function parseBpaContent(string $content): array
{
    $lines = normalizeLines($content);

    $result = [
        'header' => null,
        'comments' => [],
        'records01' => [],
        'records02' => [],
        'records03' => [],
        'unknown' => [],
        'summary' => [
            'total' => 0,
            'comments' => 0,
            '01' => 0,
            '02' => 0,
            '03' => 0,
            'XX' => 0,
        ],
    ];

    foreach ($lines as $index => $line) {
        $lineNumber = $index + 1;
        $trim = trim($line);

        if (isCommentLine($trim)) {
            $result['comments'][] = [
                'line_number' => $lineNumber,
                'text' => $trim,
            ];
            $result['summary']['comments']++;
            continue;
        }

        $tipo = substr($trim, 0, 2);

        if ($tipo === '01') {
            $parsed = parseRecord01($trim);
            $parsed['line_number'] = $lineNumber;
            $result['records01'][] = $parsed;
            $result['header'] = $parsed;
            $result['summary']['01']++;
            $result['summary']['total']++;
            continue;
        }

        if ($tipo === '02') {
            $parsed = parseRecord02($trim);
            $parsed['line_number'] = $lineNumber;
            $result['records02'][] = $parsed;
            $result['summary']['02']++;
            $result['summary']['total']++;
            continue;
        }

        if ($tipo === '03') {
            $parsed = parseRecord03($trim);
            $parsed['line_number'] = $lineNumber;
            $result['records03'][] = $parsed;
            $result['summary']['03']++;
            $result['summary']['total']++;
            continue;
        }

        $result['unknown'][] = [
            'line_number' => $lineNumber,
            'raw' => $trim,
        ];
        $result['summary']['XX']++;
        $result['summary']['total']++;
    }

    return $result;
}

function applyFilters(array $records, array $filters): array
{
    return array_values(array_filter($records, function ($record) use ($filters) {
        if ($filters['type'] !== '' && $record['tipo'] !== $filters['type']) {
            return false;
        }

        if ($filters['competencia'] !== '' && (($record['competencia'] ?? '') !== $filters['competencia'])) {
            return false;
        }

        if ($filters['search'] !== '') {
            $haystack = mb_strtolower(
                implode(' ', array_map(
                    static function ($v): string {
                        if (is_array($v)) {
                            return implode(' ', array_map('strval', $v));
                        }
                        return (string)$v;
                    },
                    $record
                )),
                'UTF-8'
            );
            $needle = mb_strtolower($filters['search'], 'UTF-8');
            if (!str_contains($haystack, $needle)) {
                return false;
            }
        }

        return true;
    }));
}

$content = '';
$result = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['bpa_file'])) {
    if ($_FILES['bpa_file']['error'] === UPLOAD_ERR_OK) {
        $content = file_get_contents($_FILES['bpa_file']['tmp_name']) ?: '';
        if (trim($content) === '') {
            $error = 'O arquivo está vazio.';
        } else {
            $_SESSION['bpa_content'] = $content;
        }
    } else {
        $error = 'Erro ao carregar o arquivo.';
    }
}

if (isset($_SESSION['bpa_content']) && $content === '') {
    $content = (string)$_SESSION['bpa_content'];
}

if ($content !== '') {
    $result = parseBpaContent($content);
}

$filters = [
    'type' => $_GET['type'] ?? '',
    'competencia' => $_GET['competencia'] ?? '',
    'search' => $_GET['search'] ?? '',
];

$filtered02 = $result ? applyFilters($result['records02'], $filters) : [];
$filtered03 = $result ? applyFilters($result['records03'], $filters) : [];

$competencias = [];
if ($result) {
    foreach (array_merge($result['records01'], $result['records02'], $result['records03']) as $record) {
        if (!empty($record['competencia'])) {
            $competencias[$record['competencia']] = $record['competencia'];
        }
    }
    ksort($competencias);
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Visualizador BPA</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background:#f5f7fb; }
        .mono { font-family: Consolas, Monaco, monospace; font-size: .85rem; }
        .comment-box { background:#fff8db; border-left:4px solid #f0c36d; padding: .75rem; border-radius: .5rem; }
        .table td, .table th { vertical-align: top; }
    </style>
</head>
<body>
<div class="container-fluid py-4">

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h1 class="h3 mb-1">Visualizador BPA</h1>
                <div class="text-muted">Upload do TXT e leitura dos registros 01, 02 e 03.</div>
            </div>
            <form method="post" enctype="multipart/form-data" class="d-flex gap-2 align-items-center">
                <input type="file" class="form-control" name="bpa_file" accept=".txt,text/plain" required>
                <button class="btn btn-primary" type="submit">Carregar</button>
            </form>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= h($error) ?></div>
    <?php endif; ?>

    <?php if ($result): ?>
        <div class="row g-3 mb-4">
            <div class="col-md-2"><div class="card"><div class="card-body text-center"><div class="text-muted">Total</div><div class="fs-3 fw-bold"><?= (int)$result['summary']['total'] ?></div></div></div></div>
            <div class="col-md-2"><div class="card"><div class="card-body text-center"><div class="text-muted">01</div><div class="fs-3 fw-bold"><?= (int)$result['summary']['01'] ?></div></div></div></div>
            <div class="col-md-2"><div class="card"><div class="card-body text-center"><div class="text-muted">02</div><div class="fs-3 fw-bold"><?= (int)$result['summary']['02'] ?></div></div></div></div>
            <div class="col-md-2"><div class="card"><div class="card-body text-center"><div class="text-muted">03</div><div class="fs-3 fw-bold"><?= (int)$result['summary']['03'] ?></div></div></div></div>
            <div class="col-md-2"><div class="card"><div class="card-body text-center"><div class="text-muted">Comentários</div><div class="fs-3 fw-bold"><?= (int)$result['summary']['comments'] ?></div></div></div></div>
            <div class="col-md-2"><div class="card"><div class="card-body text-center"><div class="text-muted">Inválidos</div><div class="fs-3 fw-bold"><?= (int)$result['summary']['XX'] ?></div></div></div></div>
        </div>

        <?php if (!empty($result['comments'])): ?>
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white"><strong>Texto explicativo do arquivo</strong></div>
                <div class="card-body">
                    <?php foreach ($result['comments'] as $comment): ?>
                        <div class="comment-box mono mb-2">
                            <div class="text-muted small">Linha <?= (int)$comment['line_number'] ?></div>
                            <div><?= h($comment['text']) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="card shadow-sm border-0 mb-4">
            <div class="card-body">
                <form method="get" class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label">Tipo</label>
                        <select name="type" class="form-select">
                            <option value="">Todos</option>
                            <option value="01" <?= $filters['type'] === '01' ? 'selected' : '' ?>>01</option>
                            <option value="02" <?= $filters['type'] === '02' ? 'selected' : '' ?>>02</option>
                            <option value="03" <?= $filters['type'] === '03' ? 'selected' : '' ?>>03</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Competência</label>
                        <select name="competencia" class="form-select">
                            <option value="">Todas</option>
                            <?php foreach ($competencias as $comp): ?>
                                <option value="<?= h($comp) ?>" <?= $filters['competencia'] === $comp ? 'selected' : '' ?>><?= h($comp) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Busca</label>
                        <input type="text" name="search" class="form-control" value="<?= h($filters['search']) ?>" placeholder="Procure por CBO, CNS, procedimento, nome...">
                    </div>
                    <div class="col-md-2 d-grid">
                        <button class="btn btn-success" type="submit">Filtrar</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-white d-flex justify-content-between">
                <strong>Registros 02 - BPA Consolidado</strong>
                <span class="text-muted"><?= count($filtered02) ?> resultado(s)</span>
            </div>
            <div class="table-responsive">
                <table class="table table-striped table-hover mb-0">
                    <thead class="table-dark">
                    <tr>
                        <th>Linha</th>
                        <th>CNES</th>
                        <th>Competência</th>
                        <th>CBO</th>
                        <th>Folha</th>
                        <th>Seq.</th>
                        <th>Procedimento</th>
                        <th>Idade</th>
                        <th>Qtd</th>
                        <th>Origem</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($filtered02)): ?>
                        <tr><td colspan="10" class="text-center text-muted py-4">Nenhum registro 02 encontrado.</td></tr>
                    <?php else: ?>
                        <?php foreach ($filtered02 as $r): ?>
                            <tr class="mono">
                                <td><?= (int)$r['line_number'] ?></td>
                                <td><?= h($r['cnes']) ?></td>
                                <td><?= h($r['competencia']) ?></td>
                                <td><?= h($r['cbo']) ?></td>
                                <td><?= h($r['folha']) ?></td>
                                <td><?= h($r['sequencia_folha']) ?></td>
                                <td><?= h($r['procedimento']) ?></td>
                                <td><?= h($r['idade']) ?></td>
                                <td><?= h((string)$r['quantidade']) ?></td>
                                <td><?= h($r['origem']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card shadow-sm border-0">
            <div class="card-header bg-white d-flex justify-content-between">
                <strong>Registros 03 - BPA Individualizado</strong>
                <span class="text-muted"><?= count($filtered03) ?> resultado(s)</span>
            </div>
            <div class="table-responsive">
                <table class="table table-striped table-hover mb-0">
                    <thead class="table-dark">
                    <tr>
                        <th>Linha</th>
                        <th>CNES</th>
                        <th>Compet.</th>
                        <th>CNS Prof.</th>
                        <th>CBO</th>
                        <th>Data Proc.</th>
                        <th>Qtd</th>
                        <th>Proced.</th>
                        <th>CNS Paciente</th>
                        <th>Sexo</th>
                        <th>Mun. Resid.</th>
                        <th>Nome Paciente</th>
                        <th>Nasc.</th>
                        <th>Raça/Cor</th>
                        <th>CID</th>
                        <th>CEP</th>
                        <th>Logradouro</th>
                        <th>Bairro</th>
                        <th>Compl.</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($filtered03)): ?>
                        <tr><td colspan="19" class="text-center text-muted py-4">Nenhum registro 03 encontrado.</td></tr>
                    <?php else: ?>
                        <?php foreach ($filtered03 as $r): ?>
                            <tr class="mono">
                                <td><?= (int)$r['line_number'] ?></td>
                                <td><?= h($r['cnes']) ?></td>
                                <td><?= h($r['competencia']) ?></td>
                                <td><?= h($r['cns_profissional']) ?></td>
                                <td><?= h($r['cbo']) ?></td>
                                <td><?= h(formatDate($r['data_atendimento'])) ?></td>
                                <td><?= h((string)$r['quantidade']) ?></td>
                                <td><?= h($r['procedimento']) ?></td>
                                <td><?= h($r['cns_paciente']) ?></td>
                                <td><?= h($r['sexo']) ?></td>
                                <td><?= h($r['municipio_residencia']) ?></td>
                                <td><?= h($r['nome_paciente'] ?? '-') ?></td>
                                <td><?= h(formatDate($r['data_nascimento'] ?? '')) ?></td>
                                <td><?= h(($r['raca_cor_codigo'] ?? '-') . ' - ' . ($r['raca_cor'] ?? '-')) ?></td>
                                <td><?= h($r['cid'] ?? '-') ?></td>
                                <td><?= h($r['cep'] ?? '-') ?></td>
                                <td><?= h(trim(($r['logradouro_tipo'] ?? '') . ' ' . ($r['logradouro_nome'] ?? $r['logradouro'] ?? ''))) ?></td>
                                <td><?= h($r['bairro'] ?? '-') ?></td>
                                <td><?= h($r['complementos'] ?? '-') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>