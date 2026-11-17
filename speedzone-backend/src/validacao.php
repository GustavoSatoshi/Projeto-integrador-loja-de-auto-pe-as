<?php
declare(strict_types=1);

// =====================================================================
// validacao.php - valida e limpa os dados que chegam do cliente.
//
// Uso:
//   $v = validar(entrada(), [
//       'nome'  => 'obrigatorio|texto:2:120',
//       'email' => 'obrigatorio|email',
//       'idade' => 'inteiro:18:120',
//   ]);
//
// Regras disponíveis:
//   obrigatorio        o campo precisa vir preenchido
//   anulavel           aceita null/"" para LIMPAR o valor (devolve null)
//   texto:min:max      tamanho do texto
//   email              e-mail válido (vira minúsculo)
//   inteiro:min:max    número inteiro
//   decimal:min:max    número com até 2 casas (aceita "19,90")
//   bool               true/false/1/0
//   enum:a,b,c         um dos valores listados
//   cep                8 dígitos
//   uf                 sigla de estado
//   telefone           10 ou 11 dígitos
//
// Só os campos que vieram na requisição aparecem no resultado
// (isso permite atualizações parciais com PUT/PATCH).
// =====================================================================

function validar(array $dados, array $regras): array
{
    $limpo = [];
    $erros = [];

    foreach ($regras as $campo => $definicao) {
        $partes      = explode('|', $definicao);
        $obrigatorio = in_array('obrigatorio', $partes, true);
        $anulavel    = in_array('anulavel', $partes, true);
        $valor       = $dados[$campo] ?? null;

        if (is_string($valor)) {
            $valor = trim($valor);
        }

        // Campo ausente ou vazio
        if ($valor === null || $valor === '') {
            if ($obrigatorio) {
                $erros[$campo] = 'Campo obrigatório.';
            } elseif ($anulavel && array_key_exists($campo, $dados)) {
                $limpo[$campo] = null;
            }
            continue;
        }

        foreach ($partes as $regra) {
            if ($regra === 'obrigatorio' || $regra === 'anulavel') {
                continue;
            }
            $args = explode(':', $regra);
            $nome = array_shift($args);
            $erro = null;

            switch ($nome) {
                case 'texto':
                    if (!is_string($valor)) {
                        $erro = 'Deve ser um texto.';
                        break;
                    }
                    $min = isset($args[0]) && $args[0] !== '' ? (int) $args[0] : 0;
                    $max = isset($args[1]) ? (int) $args[1] : 65535;
                    $tam = mb_strlen($valor);
                    if ($tam < $min) {
                        $erro = "Deve ter pelo menos $min caracteres.";
                    } elseif ($tam > $max) {
                        $erro = "Deve ter no máximo $max caracteres.";
                    }
                    break;

                case 'email':
                    $email = is_string($valor) ? mb_strtolower($valor) : '';
                    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 160) {
                        $erro = 'E-mail inválido.';
                    } else {
                        $valor = $email;
                    }
                    break;

                case 'inteiro':
                    $n = (is_int($valor) || is_string($valor) || is_float($valor))
                        ? filter_var($valor, FILTER_VALIDATE_INT) : false;
                    if ($n === false) {
                        $erro = 'Deve ser um número inteiro.';
                        break;
                    }
                    $min = isset($args[0]) && $args[0] !== '' ? (int) $args[0] : null;
                    $max = isset($args[1]) && $args[1] !== '' ? (int) $args[1] : null;
                    if ($min !== null && $n < $min) {
                        $erro = "Deve ser no mínimo $min.";
                    } elseif ($max !== null && $n > $max) {
                        $erro = "Deve ser no máximo $max.";
                    } else {
                        $valor = $n;
                    }
                    break;

                case 'decimal':
                    $txt = is_string($valor) ? str_replace(',', '.', $valor) : $valor;
                    if (is_bool($txt) || !is_numeric($txt)) {
                        $erro = 'Deve ser um número.';
                        break;
                    }
                    $n   = round((float) $txt, 3);
                    $min = isset($args[0]) && $args[0] !== '' ? (float) $args[0] : null;
                    $max = isset($args[1]) && $args[1] !== '' ? (float) $args[1] : null;
                    if ($min !== null && $n < $min) {
                        $erro = "Deve ser no mínimo $min.";
                    } elseif ($max !== null && $n > $max) {
                        $erro = "Deve ser no máximo $max.";
                    } else {
                        $valor = $n;
                    }
                    break;

                case 'bool':
                    $b = filter_var($valor, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    if ($b === null) {
                        $erro = 'Deve ser verdadeiro ou falso.';
                    } else {
                        $valor = $b;
                    }
                    break;

                case 'enum':
                    $opcoes = explode(',', implode(':', $args));
                    if (!is_string($valor) || !in_array($valor, $opcoes, true)) {
                        $erro = 'Valor inválido. Use: ' . implode(', ', $opcoes) . '.';
                    }
                    break;

                case 'cep':
                    $d = is_string($valor) ? somente_digitos($valor) : '';
                    if (strlen($d) !== 8) {
                        $erro = 'CEP inválido (use 8 dígitos).';
                    } else {
                        $valor = $d;
                    }
                    break;

                case 'uf':
                    $uf = is_string($valor) ? strtoupper($valor) : '';
                    if (!in_array($uf, UFS_BRASIL, true)) {
                        $erro = 'UF inválida.';
                    } else {
                        $valor = $uf;
                    }
                    break;

                case 'telefone':
                    $d = is_string($valor) ? somente_digitos($valor) : '';
                    if (strlen($d) < 10 || strlen($d) > 11) {
                        $erro = 'Telefone inválido (use DDD + número).';
                    } else {
                        $valor = $d;
                    }
                    break;

                default:
                    throw new InvalidArgumentException("Regra de validação desconhecida: $nome");
            }

            if ($erro !== null) {
                $erros[$campo] = $erro;
                continue 2; // próximo campo
            }
        }

        $limpo[$campo] = $valor;
    }

    if ($erros) {
        abortar(422, 'Dados inválidos.', $erros);
    }
    return $limpo;
}

/** Garante que $valor é uma lista de inteiros positivos (ex.: ids de veículos). */
function validar_lista_ids(mixed $valor, string $campo): array
{
    if (!is_array($valor)) {
        abortar(422, 'Dados inválidos.', [$campo => 'Deve ser uma lista de números.']);
    }
    $ids = [];
    foreach ($valor as $item) {
        $n = filter_var($item, FILTER_VALIDATE_INT);
        if ($n === false || $n < 1) {
            abortar(422, 'Dados inválidos.', [$campo => 'A lista deve conter apenas ids válidos.']);
        }
        $ids[$n] = $n;
    }
    return array_values($ids);
}
