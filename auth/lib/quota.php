<?php
// ReadMyLabs — cota diária por usuário (substitui o limite por IP quando logado).
// Plano determina a cota; default conservador para plano `free`.

require_once __DIR__ . '/../../db.php';

function cotaDiariaDoPlano(string $plano): ?int {
    // null => sem limite
    switch ($plano) {
        case 'ilimitado': return null;
        case 'avulsa':    return 5;
        case 'free':
        default:          return (int) (getenv('LIMITE_DIARIO') ?: 1);
    }
}

/**
 * Tenta consumir 1 análise hoje para o usuário. Retorna ['ok'=>bool, 'restante'=>?int, 'limite'=>?int].
 * `ok=false` => limite atingido.
 * Operação atômica via INSERT ... ON DUPLICATE KEY UPDATE com WHERE no UPDATE
 * (MySQL não suporta WHERE em ON DUPLICATE; fazemos check-then-act dentro de transação).
 */
function consumirCotaUsuario(int $usuarioId, string $plano): array {
    $limite = cotaDiariaDoPlano($plano);
    if ($limite === null) {
        // sem limite — apenas registra o uso (auditoria)
        $db = db();
        $db->prepare(
            'INSERT INTO usuario_uso_diario (usuario_id, dia, contagem) VALUES (?, CURDATE(), 1)
             ON DUPLICATE KEY UPDATE contagem = contagem + 1'
        )->execute([$usuarioId]);
        return ['ok' => true, 'restante' => null, 'limite' => null];
    }

    $db = db();
    $db->beginTransaction();
    try {
        $stmt = $db->prepare(
            'SELECT contagem FROM usuario_uso_diario WHERE usuario_id = ? AND dia = CURDATE() FOR UPDATE'
        );
        $stmt->execute([$usuarioId]);
        $atual = (int) ($stmt->fetchColumn() ?: 0);

        if ($atual >= $limite) {
            $db->rollBack();
            return ['ok' => false, 'restante' => 0, 'limite' => $limite];
        }

        $novo = $atual + 1;
        if ($atual === 0) {
            $db->prepare('INSERT INTO usuario_uso_diario (usuario_id, dia, contagem) VALUES (?, CURDATE(), 1)')
                ->execute([$usuarioId]);
        } else {
            $db->prepare('UPDATE usuario_uso_diario SET contagem = ? WHERE usuario_id = ? AND dia = CURDATE()')
                ->execute([$novo, $usuarioId]);
        }
        $db->commit();
        return ['ok' => true, 'restante' => max(0, $limite - $novo), 'limite' => $limite];
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}
