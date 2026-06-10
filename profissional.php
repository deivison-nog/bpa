<?php
declare(strict_types=1);

session_start();

function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function normalizeCns(string $cns): string
{
    $normalized = preg_replace('/\D+/', '', trim($cns));
    return is_string($normalized) ? $normalized : '';
}

function profissionaisPath(): string
{
    return __DIR__ . '/profissionais.json';
}

function loadProfissionais(): array
{
    $path = profissionaisPath();
    if (!is_file($path)) {
        return [];
    }

    $content = file_get_contents($path);
    if ($content === false || trim($content) === '') {
        return [];
    }

    $data = json_decode($content, true);
    if (!is_array($data)) {
        return [];
    }

    $normalized = [];
    foreach ($data as $item) {
        if (!is_array($item)) {
            continue;
        }
        $nome = trim((string)($item['nome'] ?? ''));
        $cns = normalizeCns((string)($item['cns'] ?? ''));
        if ($nome === '' || $cns === '') {
            continue;
        }
        $normalized[] = [
            'nome' => $nome,
            'cns' => $cns,
        ];
    }

    usort($normalized, static fn(array $a, array $b): int => strcasecmp($a['nome'], $b['nome']));
    return $normalized;
}

function saveProfissionais(array $profissionais): bool
{
    $path = profissionaisPath();
    $json = json_encode(array_values($profissionais), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }
    return file_put_contents($path, $json . PHP_EOL, LOCK_EX) !== false;
}

$error = null;
$success = null;
$editCns = '';
$editNome = '';

$profissionais = loadProfissionais();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $nome = trim((string)($_POST['nome'] ?? ''));
    $cns = normalizeCns((string)($_POST['cns'] ?? ''));
    $oldCns = normalizeCns((string)($_POST['old_cns'] ?? ''));

    if ($action === 'save') {
        if ($nome === '' || $cns === '') {
            $error = 'Informe nome e CNS.';
            $editCns = $oldCns !== '' ? $oldCns : $cns;
            $editNome = $nome;
        } else {
            $updated = false;

            if ($oldCns !== '') {
                foreach ($profissionais as $idx => $prof) {
                    if ($prof['cns'] === $oldCns) {
                        $profissionais[$idx] = ['nome' => $nome, 'cns' => $cns];
                        $updated = true;
                        break;
                    }
                }
            }

            if (!$updated) {
                $profissionais[] = ['nome' => $nome, 'cns' => $cns];
            }

            $unique = [];
            foreach ($profissionais as $prof) {
                $unique[$prof['cns']] = $prof;
            }
            $profissionais = array_values($unique);
            usort($profissionais, static fn(array $a, array $b): int => strcasecmp($a['nome'], $b['nome']));

            if (saveProfissionais($profissionais)) {
                $success = $updated ? 'Profissional atualizado com sucesso.' : 'Profissional cadastrado com sucesso.';
                $editCns = '';
                $editNome = '';
            } else {
                $error = 'Não foi possível salvar o arquivo JSON.';
                $editCns = $oldCns !== '' ? $oldCns : $cns;
                $editNome = $nome;
            }
        }
    }

    if ($action === 'delete') {
        if ($cns === '') {
            $error = 'CNS inválido para exclusão.';
        } else {
            $before = count($profissionais);
            $profissionais = array_values(array_filter(
                $profissionais,
                static fn(array $prof): bool => $prof['cns'] !== $cns
            ));

            if ($before === count($profissionais)) {
                $error = 'Profissional não encontrado.';
            } elseif (saveProfissionais($profissionais)) {
                $success = 'Profissional excluído com sucesso.';
            } else {
                $error = 'Não foi possível salvar o arquivo JSON.';
            }
        }
    }

    $profissionais = loadProfissionais();
}

if (isset($_GET['edit'])) {
    $targetCns = normalizeCns((string)$_GET['edit']);
    foreach ($profissionais as $prof) {
        if ($prof['cns'] === $targetCns) {
            $editCns = $prof['cns'];
            $editNome = $prof['nome'];
            break;
        }
    }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cadastro de Profissionais</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body style="background:#f5f7fb;">
<div class="container py-4">
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h1 class="h3 mb-1">Cadastro de Profissionais</h1>
                <div class="text-muted">Gerencie profissionais de saúde (nome e CNS) em arquivo JSON.</div>
            </div>
            <div class="d-flex gap-2">
                <a href="index.php" class="btn btn-outline-secondary">← Visualizador</a>
                <a href="grafico.php" class="btn btn-outline-success">📊 Gráficos</a>
            </div>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= h($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?= h($success) ?></div>
    <?php endif; ?>

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white"><strong><?= $editCns !== '' ? 'Editar profissional' : 'Novo profissional' ?></strong></div>
        <div class="card-body">
            <form method="post" class="row g-3">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="old_cns" value="<?= h($editCns) ?>">
                <div class="col-md-7">
                    <label class="form-label">Nome</label>
                    <input type="text" class="form-control" name="nome" value="<?= h($editNome) ?>" required>
                </div>
                <div class="col-md-5">
                    <label class="form-label">CNS</label>
                    <input type="text" class="form-control" name="cns" value="<?= h($editCns) ?>" required>
                </div>
                <div class="col-12 d-flex gap-2">
                    <button class="btn btn-primary" type="submit"><?= $editCns !== '' ? 'Salvar alterações' : 'Cadastrar' ?></button>
                    <?php if ($editCns !== ''): ?>
                        <a href="profissional.php" class="btn btn-outline-secondary">Cancelar edição</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-header bg-white d-flex justify-content-between">
            <strong>Profissionais cadastrados</strong>
            <span class="text-muted"><?= count($profissionais) ?> registro(s)</span>
        </div>
        <div class="table-responsive">
            <table class="table table-striped table-hover mb-0">
                <thead class="table-dark">
                <tr>
                    <th>Nome</th>
                    <th>CNS</th>
                    <th class="text-end">Ações</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($profissionais)): ?>
                    <tr><td colspan="3" class="text-center text-muted py-4">Nenhum profissional cadastrado.</td></tr>
                <?php else: ?>
                    <?php foreach ($profissionais as $prof): ?>
                        <tr>
                            <td><?= h($prof['nome']) ?></td>
                            <td><code><?= h($prof['cns']) ?></code></td>
                            <td class="text-end">
                                <a href="profissional.php?edit=<?= urlencode($prof['cns']) ?>" class="btn btn-sm btn-outline-primary">Editar</a>
                                <form method="post" class="d-inline" onsubmit="return confirm('Tem certeza que deseja excluir este profissional?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="cns" value="<?= h($prof['cns']) ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Excluir</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
