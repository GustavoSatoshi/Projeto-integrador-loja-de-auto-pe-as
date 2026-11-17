<?php
declare(strict_types=1);

// =====================================================================
// db.php - conexão MySQL (mysqli, o mesmo da biblioteca) + funções
// que usam PREPARED STATEMENTS.
//
// Na biblioteca o SQL era montado assim:
//     "SELECT * FROM livros WHERE id = $id"      <- permite SQL Injection
// Aqui os valores vão separados do comando:
//     db_um("SELECT * FROM produtos WHERE id = ?", [$id])
// =====================================================================

// Faz o mysqli lançar exceção em vez de devolver false silenciosamente
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function db(): mysqli
{
    static $conexao = null;
    if ($conexao === null) {
        $conexao = new mysqli(
            env('DB_HOST', '127.0.0.1'),
            env('DB_USER', 'root'),
            env('DB_PASS', ''),
            env('DB_NAME', 'speedzone'),
            (int) env('DB_PORT', '3306')
        );
        $conexao->set_charset('utf8mb4');
        $conexao->query("SET time_zone = '-03:00'"); // horário de Brasília
    }
    return $conexao;
}

/** Prepara e executa um comando SQL com parâmetros "?". */
function db_preparar(string $sql, array $params = []): mysqli_stmt
{
    $stmt = db()->prepare($sql);
    if ($params) {
        $tipos = '';
        $valores = [];
        foreach ($params as $p) {
            if (is_bool($p)) {
                $p = (int) $p;
            }
            $tipos .= is_int($p) ? 'i' : (is_float($p) ? 'd' : 's');
            $valores[] = $p;
        }
        $stmt->bind_param($tipos, ...$valores);
    }
    $stmt->execute();
    return $stmt;
}

/** SELECT que devolve várias linhas (array de arrays associativos). */
function db_todos(string $sql, array $params = []): array
{
    $stmt = db_preparar($sql, $params);
    $linhas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $linhas;
}

/** SELECT que devolve uma linha ou null. */
function db_um(string $sql, array $params = []): ?array
{
    $linhas = db_todos($sql, $params);
    return $linhas[0] ?? null;
}

/** SELECT que devolve só o valor da primeira coluna da primeira linha. */
function db_valor(string $sql, array $params = []): mixed
{
    $linha = db_um($sql, $params);
    return $linha === null ? null : array_values($linha)[0];
}

/** INSERT / UPDATE / DELETE. Devolve o nº de linhas afetadas. */
function db_executar(string $sql, array $params = []): int
{
    $stmt = db_preparar($sql, $params);
    $afetadas = $stmt->affected_rows;
    $stmt->close();
    return (int) $afetadas;
}

/** Executa um INSERT e devolve o id gerado. */
function db_inserir(string $sql, array $params = []): int
{
    db_executar($sql, $params);
    return (int) db()->insert_id;
}

/** Gera "?,?,?" para usar em IN (...). */
function db_marcadores(array $lista): string
{
    return implode(',', array_fill(0, count($lista), '?'));
}

/**
 * Executa várias operações como uma só: ou tudo dá certo, ou nada é gravado.
 * Essencial no checkout (pedido + itens + baixa de estoque).
 * Os "SELECT ... FOR UPDATE" travam as linhas até o fim da transação.
 */
function db_transacao(callable $funcao): mixed
{
    static $nivel = 0;
    if ($nivel > 0) {          // já dentro de uma transação
        return $funcao();
    }
    $conexao = db();
    $nivel++;
    $conexao->begin_transaction();
    try {
        $resultado = $funcao();
        $conexao->commit();
        return $resultado;
    } catch (Throwable $e) {
        $conexao->rollback();
        throw $e;
    } finally {
        $nivel--;
    }
}

/** true se a exceção é de "valor duplicado" (índice UNIQUE). */
function db_erro_duplicado(Throwable $e): bool
{
    return $e instanceof mysqli_sql_exception && (int) $e->getCode() === 1062;
}

/**
 * UPDATE montado a partir de um array [coluna => valor].
 * Os nomes de coluna vêm do código (regras de validação), nunca direto do usuário;
 * mesmo assim são conferidos.   Ex.: db_atualizar('produtos', ['nome' => 'X'], 'id = ?', [5])
 */
function db_atualizar(string $tabela, array $campos, string $onde, array $paramsOnde = []): int
{
    if (!$campos) {
        return 0;
    }
    if (!preg_match('/^[a-z_]+$/', $tabela)) {
        throw new InvalidArgumentException('Nome de tabela inválido.');
    }
    $sets = [];
    $params = [];
    foreach ($campos as $coluna => $valor) {
        if (!preg_match('/^[a-z_]+$/', (string) $coluna)) {
            throw new InvalidArgumentException('Nome de coluna inválido.');
        }
        $sets[] = "$coluna = ?";
        $params[] = $valor;
    }
    return db_executar("UPDATE $tabela SET " . implode(', ', $sets) . " WHERE $onde", array_merge($params, $paramsOnde));
}

/** INSERT montado a partir de [coluna => valor]. Devolve o id gerado. */
function db_inserir_campos(string $tabela, array $campos): int
{
    if (!$campos || !preg_match('/^[a-z_]+$/', $tabela)) {
        throw new InvalidArgumentException('Dados inválidos para INSERT.');
    }
    foreach (array_keys($campos) as $coluna) {
        if (!preg_match('/^[a-z_]+$/', (string) $coluna)) {
            throw new InvalidArgumentException('Nome de coluna inválido.');
        }
    }
    return db_inserir(
        "INSERT INTO $tabela (" . implode(', ', array_keys($campos)) . ') VALUES (' . db_marcadores($campos) . ')',
        array_values($campos)
    );
}
