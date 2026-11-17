<?php
declare(strict_types=1);

// =====================================================================
// Vincula as imagens de exemplo (public/uploads/produtos/sz-*) aos
// produtos de exemplo, pelo SKU (SZ-FRE-0001 -> sz-fre-0001.png).
// Só mexe em produto que ainda NÃO tem nenhuma imagem: o que você já
// enviou pelo painel não é alterado.
//
//   php bin/imagens_exemplo.php
//
// O iniciar.bat já faz isso sozinho na primeira vez.
// =====================================================================

/** Procura public/uploads/produtos/<sku>.<ext> em qualquer formato aceito. */
function achar_imagem_do_sku(string $pasta, string $sku): ?string
{
    foreach (['webp', 'jpg', 'jpeg', 'png'] as $ext) {
        $arquivo = strtolower($sku) . '.' . $ext;
        if (is_file("$pasta/$arquivo")) {
            return $arquivo;
        }
    }
    return null;
}

/**
 * Liga a foto de cada produto pelo SKU (SZ-FRE-0001 -> sz-fre-0001.webp/.jpg/.jpeg/.png).
 *  - produto SEM imagem no banco: cria o vínculo;
 *  - produto cuja imagem principal aponta para um arquivo que NÃO existe: corrige o nome;
 *  - o que já está certo (inclusive fotos enviadas pelo painel) não é alterado.
 * Devolve quantos produtos foram ligados ou corrigidos.
 */
function vincular_imagens_exemplo(mysqli $db, string $raiz): int
{
    $pasta = $raiz . '/public/uploads/produtos';
    $res = $db->query(
        'SELECT p.id, p.sku,
                (SELECT i.id FROM produto_imagens i WHERE i.produto_id = p.id
                  ORDER BY i.principal DESC, i.ordem, i.id LIMIT 1) AS imagem_id,
                (SELECT i.arquivo FROM produto_imagens i WHERE i.produto_id = p.id
                  ORDER BY i.principal DESC, i.ordem, i.id LIMIT 1) AS arquivo_atual
           FROM produtos p'
    );
    if ($res === false) {
        return 0;
    }
    $novo  = $db->prepare('INSERT INTO produto_imagens (produto_id, arquivo, principal, ordem) VALUES (?, ?, 1, 0)');
    $troca = $db->prepare('UPDATE produto_imagens SET arquivo = ? WHERE id = ?');
    $qtd = 0;
    foreach ($res->fetch_all(MYSQLI_ASSOC) as $p) {
        $arquivo = achar_imagem_do_sku($pasta, (string) $p['sku']);
        if ($arquivo === null) {
            continue;
        }
        if ($p['imagem_id'] === null) {
            $id = (int) $p['id'];
            $novo->bind_param('is', $id, $arquivo);
            if ($novo->execute()) {
                $qtd++;
            }
        } elseif (!is_file($pasta . '/' . $p['arquivo_atual']) && $p['arquivo_atual'] !== $arquivo) {
            $imgId = (int) $p['imagem_id'];
            $troca->bind_param('si', $arquivo, $imgId);
            if ($troca->execute()) {
                $qtd++;
            }
        }
    }
    return $qtd;
}

// Rodando direto pelo terminal (e não incluído pelo instalar.php)
if (PHP_SAPI === 'cli' && basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    $raiz = dirname(__DIR__);
    require $raiz . '/src/app.php';
    echo 'Imagens vinculadas a ' . vincular_imagens_exemplo(db(), $raiz) . " produto(s).\n";
}
