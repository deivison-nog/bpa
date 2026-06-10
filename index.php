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

    return [
        'raw' => $line,
        'tipo' => '01',
        'competencia' => substr($line, 2, 6),
        'cnes' => substr($line, 8, 7),
        'versao_bpa' => substr($line, 15, 2),
        'origem' => substr($line, 17, 3),
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
        '004' => 'ÁREA',
        '008' => 'AVENIDA',
        '011' => 'CAMPO',
        '020' => 'COMUNIDADE',
        '031' => 'ESTRADA',
        '074' => 'RAMAL',
        '081' => 'RUA',
        '082' => 'RUA DE PEDESTRE',
        '090' => 'TRAVESSA',
        '091' => 'TRECHO',
        '092' => 'TREVO',
        '100' => 'VILA',
        '746' => 'SÍTIO',
    ];

    $municipioMap = [
        '150260' => 'Colares',
        '150290' => 'Muaná',
        '150820' => 'Viseu',
    ];

    // ─── BLOCO 1: Cabeçalho fixo (offsets 0–48) ──────────────────────────
    $cnes            = substr($line, 2,  7);   // Código CNES
    $competencia     = substr($line, 9,  6);   // Competência AAAAMM
    $cnsProfissional = substr($line, 15, 15);  // CNS do profissional
    $cbo             = substr($line, 30, 6);   // CBO do profissional
    $dataAtendimento = substr($line, 36, 8);   // Data AAAAMMDD
    $folhaBpa        = substr($line, 44, 3);   // Número da folha
    $sequencia       = substr($line, 47, 2);   // Sequência na folha

    // ─── BLOCO 2: Procedimento (offset 49, 10 dígitos SIGTAP) ────────────
    // BPA-I tipo 03 é individualizado: quantidade implícita = 1 por registro.
    // O layout real não possui campo "quantidade" separado nesta posição;
    // o código SIGTAP de 10 dígitos começa diretamente no offset 49.
    $procedimento = substr($line, 49, 10);

    // ─── BLOCO 3: Identificação do paciente (offsets 59–108) ─────────────
    $cnsPaciente     = trim(substr($line, 59, 15));  // CNS do paciente (15 dígitos)
    $sexoCodigo      = substr($line, 74, 1);         // M / F / I
    $municipioCodigo = substr($line, 75, 6);         // Código IBGE do município
    $caraterAtend    = trim(substr($line, 81, 4));   // Caráter de atendimento / cód. extra
    $idade           = substr($line, 85, 3);          // Idade em anos (3 dígitos)
    // [88,94): código equipe/autorização (6 chars — não exibido)
    $nacionalidade   = substr($line, 94, 2);          // 02 = Brasileiro, etc.
    // [96,109): 13 chars de padding

    // ─── BLOCO 4: Dados BPA — marcador "BPA" sempre em offset 109 ────────
    // [109,112) = literal "BPA"
    $nomePaciente   = trim(substr($line, 112, 30)); // Nome (30 chars, padded com espaços)
    $dataNascimento = substr($line, 142, 8);         // Nascimento AAAAMMDD
    $racaRaw        = substr($line, 150, 2);         // Raça/cor "01".."05"
    $racaCodigo     = ltrim($racaRaw, '0');          // Remove zero à esquerda → "1".."5"
    $cid            = trim(substr($line, 152, 4));   // CID-10 (4 chars, pode ser vazio)
    // [156,159): código "010" (campo de saúde — não exibido diretamente)
    // [159,165): código equipe de saúde (6 chars)
    // [165,191): padding (26 chars)

    // ─── BLOCO 5: Endereço — offsets fixos a partir de 191 ───────────────
    $cep              = substr($line, 191, 8);         // CEP (8 dígitos)
    $logradouroCodigo = substr($line, 199, 3);         // Código tipo de logradouro
    $logradouroNome   = trim(substr($line, 202, 30));  // Nome do logradouro (30 chars)

    // ─── BLOCO 6: ZONA + Número + Bairro (offset 232) ────────────────────
    $zonaTipo = null;
    $numero   = null;
    $bairro   = null;

    if (strlen($line) > 232) {
        $addrBlock = substr($line, 232);

        // Normaliza typo recorrente "ZOAN" → "ZONA"
        $addrBlock = (string) preg_replace('/^ZOAN\s/u', 'ZONA ', $addrBlock);

        if (str_starts_with($addrBlock, 'ZONA ')) {
            // Tipo de zona: "RURAL" ou "URBAN" (5 chars após "ZONA ")
            $zonaTipo  = trim(substr($addrBlock, 5, 5));
            $afterZona = substr($addrBlock, 10); // "S/N  bairro...N" ou "151  bairro...N"
        } else {
            // Sem marcador ZONA (registros sem informação de zona)
            $afterZona = ltrim($addrBlock);
        }

        // Extrai número e bairro:  <numero><espaços><bairro><espaços>[N]
        // O marcador "N" final pode estar ausente em alguns registros; usa-se trim()
        // como fallback. O placeholder "-" é normalizado para null.
        if (preg_match(
            '/^(?P<numero>S\/N|SN|\d+)\s{1,6}(?P<bairro>.+?)\s*(?:N\s*)?$/u',
            $afterZona,
            $am
        )) {
            $numero = $am['numero'];
            $bairro = trim($am['bairro']);
        } elseif (preg_match('/^(?P<bairro>[^-].+?)\s*(?:N\s*)?$/u', $afterZona, $am)) {
            // Fallback: sem número explícito
            $bairro = trim($am['bairro']);
        }
        // Normaliza placeholders "-"
        if ($bairro === '-' || $bairro === '') $bairro = null;
        if ($numero === '-' || $numero === '') $numero = null;
    }

    // ─── Lookups e derivações ─────────────────────────────────────────────
    $sexo                = $sexoMap[$sexoCodigo] ?? $sexoCodigo;
    $racaCorCodigo       = ($racaCodigo !== '') ? $racaCodigo : null;
    $racaCor             = ($racaCorCodigo !== null) ? ($racaMap[$racaCorCodigo] ?? $racaCorCodigo) : null;
    $municipioResidencia = $municipioMap[$municipioCodigo] ?? $municipioCodigo;
    $logradouroTipo      = $logradouroMap[$logradouroCodigo] ?? $logradouroCodigo;

    return [
        'raw'                       => $line,
        'tipo'                      => '03',
        'cnes'                      => $cnes,
        'competencia'               => $competencia,
        'cns_profissional'          => $cnsProfissional,
        'cbo'                       => $cbo,
        'data_atendimento'          => $dataAtendimento,
        'folha_bpa'                 => $folhaBpa,
        'sequencia'                 => $sequencia,
        'quantidade'                => 1,   // BPA-I individualizado: 1 atendimento por registro
        'procedimento'              => $procedimento,
        'cns_paciente'              => ($cnsPaciente !== '') ? $cnsPaciente : null,
        'sexo_codigo'               => $sexoCodigo,
        'sexo'                      => $sexo,
        'municipio_codigo'          => $municipioCodigo,
        'municipio_residencia'      => $municipioResidencia,
        'idade'                     => $idade,
        'carater_atendimento'       => ($caraterAtend !== '') ? $caraterAtend : null,
        'nacionalidade'             => $nacionalidade,
        'cid'                       => ($cid !== '') ? $cid : null,
        'nome_paciente'             => ($nomePaciente !== '') ? $nomePaciente : null,
        'data_nascimento'           => $dataNascimento,
        'data_nascimento_formatada' => formatDate($dataNascimento),
        'raca_cor_codigo'           => $racaCorCodigo,
        'raca_cor'                  => $racaCor,
        'cep'                       => $cep,
        'logradouro_codigo'         => $logradouroCodigo,
        'logradouro_tipo'           => $logradouroTipo,
        'logradouro_nome'           => $logradouroNome,
        'zona'                      => $zonaTipo,
        'numero'                    => $numero,
        'bairro'                    => $bairro,
        'complementos'              => trim(implode(' ', array_filter([$zonaTipo, $numero]))),
    ];
}

/**
 * Exibe informações de depuração para um registro 03 já parseado.
 * Útil para identificar campos suspeitos ou nulos.
 */
function debugRecord03(array $parsed): string
{
    $line   = $parsed['raw'];
    $issues = [];

    if (empty($parsed['cns_paciente']))    $issues[] = 'CNS paciente vazio';
    if (empty($parsed['nome_paciente']))   $issues[] = 'Nome do paciente vazio';
    if (strlen((string)($parsed['data_nascimento'] ?? '')) !== 8) $issues[] = 'Data de nascimento inválida';
    if (empty($parsed['sexo_codigo']))     $issues[] = 'Sexo não identificado';
    if (empty($parsed['cep']))             $issues[] = 'CEP vazio';
    if (empty($parsed['bairro']))          $issues[] = 'Bairro não identificado';
    if (empty($parsed['logradouro_nome'])) $issues[] = 'Logradouro vazio';
    if (empty($parsed['procedimento']) || strlen($parsed['procedimento']) !== 10) $issues[] = 'Procedimento inválido';

    $fields = [
        '[2,9)'    => ['CNES',              $parsed['cnes']],
        '[9,15)'   => ['Competência',        $parsed['competencia']],
        '[15,30)'  => ['CNS Profissional',   $parsed['cns_profissional']],
        '[30,36)'  => ['CBO',                $parsed['cbo']],
        '[36,44)'  => ['Data Atendimento',   $parsed['data_atendimento']],
        '[44,47)'  => ['Folha',              $parsed['folha_bpa']],
        '[47,49)'  => ['Sequência',          $parsed['sequencia']],
        '[49,59)'  => ['Procedimento',       $parsed['procedimento']],
        '[59,74)'  => ['CNS Paciente',       $parsed['cns_paciente']],
        '[74]'     => ['Sexo',               ($parsed['sexo_codigo'] ?? '') . ' → ' . ($parsed['sexo'] ?? '')],
        '[75,81)'  => ['Município',          ($parsed['municipio_codigo'] ?? '') . ' → ' . ($parsed['municipio_residencia'] ?? '')],
        '[81,85)'  => ['Caráter/Cód. extra', $parsed['carater_atendimento']],
        '[85,88)'  => ['Idade',              $parsed['idade']],
        '[94,96)'  => ['Nacionalidade',      $parsed['nacionalidade']],
        '[112,142)'=> ['Nome Paciente',      $parsed['nome_paciente']],
        '[142,150)'=> ['Data Nascimento',    $parsed['data_nascimento']],
        '[150,152)'=> ['Raça/Cor (cód.)',    $parsed['raca_cor_codigo']],
        '[152,156)'=> ['CID',                $parsed['cid']],
        '[191,199)'=> ['CEP',                $parsed['cep']],
        '[199,202)'=> ['Logr. Código',       $parsed['logradouro_codigo']],
        '[202,232)'=> ['Logr. Nome',         $parsed['logradouro_nome']],
        'Zona'     => ['Zona',               $parsed['zona']],
        'Número'   => ['Número',             $parsed['numero']],
        'Bairro'   => ['Bairro',             $parsed['bairro']],
    ];

    $out  = "=== DEBUG parseRecord03 ===\n";
    $out .= 'RAW [' . strlen($line) . " chars]: " . $line . "\n\n";

    foreach ($fields as $offset => [$label, $val]) {
        $flag = ($val === null || $val === '') ? '  ⚠ VAZIO' : '';
        $out .= sprintf("  %-12s  %-22s = %s%s\n", $offset, $label, (string)$val, $flag);
    }

    if ($issues) {
        $out .= "\n⚠️  CAMPOS SUSPEITOS: " . implode(', ', $issues) . "\n";
    } else {
        $out .= "\n✅  Todos os campos principais preenchidos.\n";
    }

    return $out;
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
                <div class="text-muted">Upload do TXT e leitura dos registros 01, 02 e 03 com offsets oficiais.</div>
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
                        <th>Nacionalidade</th>
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
                        <tr><td colspan="20" class="text-center text-muted py-4">Nenhum registro 03 encontrado.</td></tr>
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
                                <td><?= h($r['nacionalidade'] ?? '-') ?></td>
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
