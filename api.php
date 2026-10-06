<?php

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}


/*
|--------------------------------------------------------------------------
| CONFIGURAÇÕES
|--------------------------------------------------------------------------
*/

const MAX_BASES_UPLOAD = 5;
const MAX_UPLOAD_BYTES = 2097152; // 2 MB

$arquivoPadrao = __DIR__ . '/dados.json';
$diretorioBases = __DIR__ . '/bases';


/*
|--------------------------------------------------------------------------
| FUNÇÕES AUXILIARES
|--------------------------------------------------------------------------
*/

function textoTamanho($valor)
{
    $valor = (string) $valor;

    return function_exists('mb_strlen')
        ? mb_strlen($valor, 'UTF-8')
        : strlen($valor);
}


function textoMinusculo($valor)
{
    $valor = (string) $valor;

    return function_exists('mb_strtolower')
        ? mb_strtolower($valor, 'UTF-8')
        : strtolower($valor);
}


/*
|--------------------------------------------------------------------------
| Verifica se o array é uma lista
|--------------------------------------------------------------------------
*/

function ehLista($dados)
{
    if (!is_array($dados)) {
        return false;
    }

    $indice = 0;

    foreach ($dados as $chave => $_) {

        if ($chave !== $indice++) {
            return false;
        }

    }

    return true;
}


/*
|--------------------------------------------------------------------------
| Respostas
|--------------------------------------------------------------------------
*/

function responder($dados, $status = 200)
{
    http_response_code($status);

    echo json_encode(
        $dados,
        JSON_UNESCAPED_UNICODE |
        JSON_PRETTY_PRINT
    );

    exit;
}


function erro($message, $status = 400, $errors = null)
{
    $payload = [

        'error' => true,

        'status' => $status,

        'message' => $message

    ];


    if ($errors !== null) {

        $payload['errors'] = $errors;

    }


    responder(
        $payload,
        $status
    );
}


function erroValidacao($erros)
{
    erro(
        'Dados inválidos',
        422,
        $erros
    );
}


/*
|--------------------------------------------------------------------------
| Criar pasta de bases
|--------------------------------------------------------------------------
*/

if (
    !is_dir($diretorioBases)
    &&
    !mkdir($diretorioBases, 0775, true)
    &&
    !is_dir($diretorioBases)
) {

    erro(
        'Não foi possível criar a pasta de bases. Verifique as permissões do servidor.',
        500
    );

}


/*
|--------------------------------------------------------------------------
| LISTAR BASES
|--------------------------------------------------------------------------
*/

function listarBases($diretorioBases)
{
    $bases = [

        [
            'id' => 'dados.json',
            'nome' => 'Base padrão',
            'arquivo' => 'dados.json',
            'padrao' => true
        ]

    ];


    $arquivos =
        glob($diretorioBases . '/*.json')
        ?: [];


    sort(
        $arquivos,
        SORT_NATURAL | SORT_FLAG_CASE
    );


    foreach ($arquivos as $arquivo) {

        $nome =
            basename($arquivo);


        $bases[] = [

            'id' => $nome,

            'nome' =>
                pathinfo(
                    $nome,
                    PATHINFO_FILENAME
                ),

            'arquivo' => $nome,

            'padrao' => false

        ];

    }


    return $bases;
}


/*
|--------------------------------------------------------------------------
| LOCALIZAR BASE SELECIONADA
|--------------------------------------------------------------------------
*/

function caminhoBaseSelecionada(
    $base,
    $arquivoPadrao,
    $diretorioBases
) {

    if (
        $base === null
        ||
        $base === ''
        ||
        $base === 'dados.json'
    ) {

        return $arquivoPadrao;

    }


    $nomeSeguro =
        basename($base);


    if (
        $nomeSeguro !== $base
        ||
        strtolower(
            pathinfo(
                $nomeSeguro,
                PATHINFO_EXTENSION
            )
        ) !== 'json'
    ) {

        erro(
            'Base de dados inválida.',
            400
        );

    }


    $caminho =
        $diretorioBases
        . '/'
        . $nomeSeguro;


    if (!is_file($caminho)) {

        erro(
            'Base de dados não encontrada.',
            404
        );

    }


    return $caminho;
}


/*
|--------------------------------------------------------------------------
| CARREGAR JSON
|--------------------------------------------------------------------------
*/

function carregarObjetos($arquivo)
{
    if (!file_exists($arquivo)) {

        file_put_contents(
            $arquivo,
            '[]'
        );

    }


    $conteudo =
        file_get_contents($arquivo);


    if ($conteudo === false) {

        erro(
            'Não foi possível ler a base de dados.',
            500
        );

    }


    // Remove BOM UTF-8
    if (
        substr(
            $conteudo,
            0,
            3
        ) === "\xEF\xBB\xBF"
    ) {

        $conteudo =
            substr(
                $conteudo,
                3
            );

    }


    $dados =
        json_decode(
            $conteudo,
            true
        );


    if (
        json_last_error()
        !== JSON_ERROR_NONE
    ) {

        erro(
            'A base selecionada contém JSON inválido.',
            500
        );

    }


    if (
        !is_array($dados)
        ||
        !ehLista($dados)
    ) {

        erro(
            'A base selecionada deve conter um array JSON.',
            500
        );

    }


    /*
     * Corrige automaticamente "Tipo"
     * para "tipo" caso exista.
     */
    foreach ($dados as &$objeto) {

        if (
            isset($objeto['Tipo'])
            &&
            !isset($objeto['tipo'])
        ) {

            $objeto['tipo'] =
                $objeto['Tipo'];

            unset(
                $objeto['Tipo']
            );

        }

    }

    unset($objeto);


    return $dados;
}


/*
|--------------------------------------------------------------------------
| SALVAR JSON
|--------------------------------------------------------------------------
*/

function salvarObjetos(
    $arquivo,
    $objetos
) {

    $json =
        json_encode(
            array_values($objetos),
            JSON_PRETTY_PRINT |
            JSON_UNESCAPED_UNICODE
        );


    if ($json === false) {

        erro(
            'Não foi possível converter os dados para JSON.',
            500
        );

    }


    $resultado =
        @file_put_contents(
            $arquivo,
            $json,
            LOCK_EX
        );


    if ($resultado === false) {

        erro(
            'Não foi possível salvar a base de dados. Verifique as permissões de escrita.',
            500
        );

    }

}


/*
|--------------------------------------------------------------------------
| LER JSON DO BODY
|--------------------------------------------------------------------------
*/

function lerJson()
{
    $conteudo =
        file_get_contents(
            'php://input'
        );


    if (
        $conteudo === ''
        ||
        $conteudo === false
    ) {

        return [];

    }


    $dados =
        json_decode(
            $conteudo,
            true
        );


    if (
        json_last_error()
        !== JSON_ERROR_NONE
    ) {

        erro(
            'JSON inválido.',
            400
        );

    }


    if (!is_array($dados)) {

        erro(
            'O corpo da requisição deve ser um objeto JSON.',
            400
        );

    }


    return $dados;
}


/*
|--------------------------------------------------------------------------
| VALIDAR OBJETO
|--------------------------------------------------------------------------
|
| Campos:
| id
| nomeObjeto
| setor
| tipo
|
*/

function validarObjeto(
    $dados,
    $parcial = false
) {

    $erros = [];


    /*
     * nomeObjeto
     */

    if (
        !$parcial
        ||
        array_key_exists(
            'nomeObjeto',
            $dados
        )
    ) {

        $nome =
            trim(
                (string) (
                    $dados['nomeObjeto']
                    ?? ''
                )
            );


        if ($nome === '') {

            $erros['nomeObjeto'] =
                'O nome do objeto é obrigatório.';

        }

        elseif (
            textoTamanho($nome) < 2
        ) {

            $erros['nomeObjeto'] =
                'O nome do objeto deve ter pelo menos 2 caracteres.';

        }

        elseif (
            textoTamanho($nome) > 100
        ) {

            $erros['nomeObjeto'] =
                'O nome do objeto deve ter no máximo 100 caracteres.';

        }

    }


    /*
     * setor
     */

    if (
        !$parcial
        ||
        array_key_exists(
            'setor',
            $dados
        )
    ) {

        $setor =
            trim(
                (string) (
                    $dados['setor']
                    ?? ''
                )
            );


        if ($setor === '') {

            $erros['setor'] =
                'O setor é obrigatório.';

        }

        elseif (
            textoTamanho($setor) < 2
        ) {

            $erros['setor'] =
                'O setor deve ter pelo menos 2 caracteres.';

        }

        elseif (
            textoTamanho($setor) > 100
        ) {

            $erros['setor'] =
                'O setor deve ter no máximo 100 caracteres.';

        }

    }


    /*
     * tipo
     */

    if (
        !$parcial
        ||
        array_key_exists(
            'tipo',
            $dados
        )
    ) {

        $tipo =
            trim(
                (string) (
                    $dados['tipo']
                    ?? ''
                )
            );


        if ($tipo === '') {

            $erros['tipo'] =
                'O tipo é obrigatório.';

        }

        elseif (
            textoTamanho($tipo) < 2
        ) {

            $erros['tipo'] =
                'O tipo deve ter pelo menos 2 caracteres.';

        }

        elseif (
            textoTamanho($tipo) > 100
        ) {

            $erros['tipo'] =
                'O tipo deve ter no máximo 100 caracteres.';

        }

    }


    return $erros;
}


/*
|--------------------------------------------------------------------------
| VALIDAR BASE IMPORTADA
|--------------------------------------------------------------------------
*/

function validarBaseImportada($dados)
{
    if (
        !is_array($dados)
        ||
        !ehLista($dados)
    ) {

        return [

            'base' =>
                'O JSON deve ter um array de objetos na raiz.'

        ];

    }


    $erros = [];
    $ids = [];


    foreach (
        $dados
        as $indice => $objeto
    ) {

        if (!is_array($objeto)) {

            $erros[
                "registro_$indice"
            ] =
                'Cada item da base deve ser um objeto JSON.';

            continue;

        }


        /*
         * ID
         */

        if (
            !array_key_exists(
                'id',
                $objeto
            )
            ||
            filter_var(
                $objeto['id'],
                FILTER_VALIDATE_INT
            ) === false
            ||
            (int) $objeto['id'] < 1
        ) {

            $erros[
                "registro_$indice.id"
            ] =
                'O ID deve ser um inteiro positivo.';

        }

        else {

            $id =
                (int) $objeto['id'];


            if (
                isset(
                    $ids[$id]
                )
            ) {

                $erros[
                    "registro_$indice.id"
                ] =
                    'O ID está duplicado na base.';

            }


            $ids[$id] = true;

        }


        /*
         * Corrigir "Tipo" para "tipo"
         */

        if (
            isset($objeto['Tipo'])
            &&
            !isset($objeto['tipo'])
        ) {

            $objeto['tipo'] =
                $objeto['Tipo'];

        }


        /*
         * Validar campos
         */

        $validacao =
            validarObjeto(
                $objeto
            );


        foreach (
            $validacao
            as $campo => $mensagem
        ) {

            $erros[
                "registro_$indice.$campo"
            ] =
                $mensagem;

        }


        if (
            count($erros) >= 20
        ) {

            $erros['base'] =
                'A validação foi interrompida após muitos erros.';

            break;

        }

    }


    return $erros;
}


/*
|--------------------------------------------------------------------------
| NORMALIZAR BASE
|--------------------------------------------------------------------------
*/

function normalizarBaseImportada(
    $dados
) {

    return array_map(
        function ($objeto) {

            $tipo =
                $objeto['tipo']
                ?? $objeto['Tipo']
                ?? '';


            return [

                'id' =>
                    (int) $objeto['id'],

                'nomeObjeto' =>
                    trim(
                        $objeto['nomeObjeto']
                    ),

                'setor' =>
                    trim(
                        $objeto['setor']
                    ),

                'tipo' =>
                    trim($tipo)

            ];

        },
        $dados
    );

}


/*
|--------------------------------------------------------------------------
| GARANTIR ID ÚNICO
|--------------------------------------------------------------------------
*/

function idEmUso(
    $objetos,
    $id,
    $ignorarId = null
) {

    foreach (
        $objetos
        as $objeto
    ) {

        if (
            isset($objeto['id'])
            &&
            (int) $objeto['id'] === (int) $id
            &&
            (
                $ignorarId === null
                ||
                (int) $objeto['id']
                !==
                (int) $ignorarId
            )
        ) {

            return true;

        }

    }


    return false;
}


/*
|--------------------------------------------------------------------------
| PRÓXIMO ID
|--------------------------------------------------------------------------
*/

function proximoId($objetos)
{
    $maiorId = 0;


    foreach (
        $objetos
        as $objeto
    ) {

        if (
            isset($objeto['id'])
            &&
            is_numeric($objeto['id'])
        ) {

            $maiorId =
                max(
                    $maiorId,
                    (int) $objeto['id']
                );

        }

    }


    return $maiorId + 1;
}


/*
|--------------------------------------------------------------------------
| CONFIGURAÇÃO DA REQUISIÇÃO
|--------------------------------------------------------------------------
*/

$metodo =
    $_SERVER['REQUEST_METHOD'];


$action =
    $_GET['action']
    ?? null;


/*
|--------------------------------------------------------------------------
| LISTAR BASES
|--------------------------------------------------------------------------
*/

if ($action === 'bases') {

    if ($metodo !== 'GET') {

        erro(
            'Método não permitido para esta ação.',
            405
        );

    }


    $bases =
        listarBases(
            $diretorioBases
        );


    responder([

        'max_uploads' =>
            MAX_BASES_UPLOAD,

        'uploads_utilizados' =>
            max(
                0,
                count($bases) - 1
            ),

        'bases' =>
            $bases

    ]);

}


/*
|--------------------------------------------------------------------------
| UPLOAD DE BASE
|--------------------------------------------------------------------------
*/

if ($action === 'upload-base') {

    if ($metodo !== 'POST') {

        erro(
            'Método não permitido para esta ação.',
            405
        );

    }


    $bases =
        listarBases(
            $diretorioBases
        );


    $uploadsUtilizados =
        max(
            0,
            count($bases) - 1
        );


    if (
        $uploadsUtilizados
        >= MAX_BASES_UPLOAD
    ) {

        erro(
            'O limite de 5 bases JSON anexadas foi atingido.',
            409
        );

    }


    if (
        !isset(
            $_FILES['base']
        )
    ) {

        erro(
            'Selecione um arquivo JSON para enviar.',
            400
        );

    }


    $upload =
        $_FILES['base'];


    if (
        $upload['error']
        !== UPLOAD_ERR_OK
    ) {

        erro(
            'Falha no upload do arquivo.',
            400
        );

    }


    if (
        $upload['size']
        > MAX_UPLOAD_BYTES
    ) {

        erro(
            'O arquivo deve ter no máximo 2 MB.',
            413
        );

    }


    $nomeOriginal =
        basename(
            $upload['name']
        );


    $extensao =
        strtolower(
            pathinfo(
                $nomeOriginal,
                PATHINFO_EXTENSION
            )
        );


    if ($extensao !== 'json') {

        erro(
            'Apenas arquivos .json são permitidos.',
            415
        );

    }


    $nomeBase =
        pathinfo(
            $nomeOriginal,
            PATHINFO_FILENAME
        );


    $nomeSeguro =
        preg_replace(
            '/[^a-zA-Z0-9_-]+/',
            '-',
            $nomeBase
        );


    $nomeSeguro =
        trim(
            $nomeSeguro,
            '-_'
        );


    if ($nomeSeguro === '') {

        $nomeSeguro = 'base';

    }


    $nomeFinal =
        $nomeSeguro
        . '.json';


    $destino =
        $diretorioBases
        . '/'
        . $nomeFinal;


    if (file_exists($destino)) {

        erro(
            'Já existe uma base com esse nome. Renomeie o arquivo e tente novamente.',
            409
        );

    }


    $conteudo =
        @file_get_contents(
            $upload['tmp_name']
        );


    if ($conteudo === false) {

        erro(
            'Não foi possível ler o arquivo enviado.',
            400
        );

    }


    if (
        substr(
            $conteudo,
            0,
            3
        ) === "\xEF\xBB\xBF"
    ) {

        $conteudo =
            substr(
                $conteudo,
                3
            );

    }


    $dados =
        json_decode(
            $conteudo,
            true
        );


    if (
        json_last_error()
        !== JSON_ERROR_NONE
    ) {

        erro(
            'O arquivo enviado não contém JSON válido.',
            422
        );

    }


    /*
     * Corrige automaticamente
     * "Tipo" -> "tipo"
     */

    foreach (
        $dados
        as &$objeto
    ) {

        if (
            isset($objeto['Tipo'])
            &&
            !isset($objeto['tipo'])
        ) {

            $objeto['tipo'] =
                $objeto['Tipo'];

            unset(
                $objeto['Tipo']
            );

        }

    }

    unset($objeto);


    $erros =
        validarBaseImportada(
            $dados
        );


    if ($erros) {

        erro(
            'A base JSON não é compatível com o formato esperado.',
            422,
            $erros
        );

    }


    $dadosNormalizados =
        normalizarBaseImportada(
            $dados
        );


    $jsonNormalizado =
        json_encode(
            $dadosNormalizados,
            JSON_PRETTY_PRINT |
            JSON_UNESCAPED_UNICODE
        );


    if ($jsonNormalizado === false) {

        erro(
            'Não foi possível converter a base para JSON.',
            500
        );

    }


    $salvou =
        @file_put_contents(
            $destino,
            $jsonNormalizado,
            LOCK_EX
        );


    if ($salvou === false) {

        erro(
            'Não foi possível armazenar a base. Verifique as permissões da pasta bases/.',
            500
        );

    }


    responder([

        'message' =>
            'Base adicionada com sucesso.',

        'base' => [

            'id' =>
                $nomeFinal,

            'nome' =>
                $nomeSeguro,

            'arquivo' =>
                $nomeFinal,

            'padrao' =>
                false

        ],

        'registros' =>
            count(
                $dadosNormalizados
            )

    ], 201);

}


/*
|--------------------------------------------------------------------------
| DELETE BASE
|--------------------------------------------------------------------------
*/

if ($action === 'delete-base') {

    if ($metodo !== 'DELETE') {

        erro(
            'Método não permitido para esta ação.',
            405
        );

    }


    $base =
        $_GET['base']
        ?? '';


    if (
        $base === ''
        ||
        $base === 'dados.json'
    ) {

        erro(
            'A base padrão não pode ser removida.',
            400
        );

    }


    $nomeSeguro =
        basename($base);


    if (
        $nomeSeguro !== $base
        ||
        strtolower(
            pathinfo(
                $nomeSeguro,
                PATHINFO_EXTENSION
            )
        ) !== 'json'
    ) {

        erro(
            'Base de dados inválida.',
            400
        );

    }


    $caminho =
        $diretorioBases
        . '/'
        . $nomeSeguro;


    if (!is_file($caminho)) {

        erro(
            'Base de dados não encontrada.',
            404
        );

    }


    if (!unlink($caminho)) {

        erro(
            'Não foi possível remover a base.',
            500
        );

    }


    responder([

        'message' =>
            'Base removida com sucesso.'

    ]);

}


/*
|--------------------------------------------------------------------------
| BASE ATUAL
|--------------------------------------------------------------------------
*/

$baseSelecionada =
    $_GET['base']
    ?? 'dados.json';


$arquivo =
    caminhoBaseSelecionada(
        $baseSelecionada,
        $arquivoPadrao,
        $diretorioBases
    );


$objetos =
    carregarObjetos(
        $arquivo
    );


$id =
    isset($_GET['id'])
    && $_GET['id'] !== ''
        ? (int) $_GET['id']
        : null;


/*
|--------------------------------------------------------------------------
| GET
|--------------------------------------------------------------------------
|
| Exemplos:
|
| api.php?nomeObjeto=Mesa
|
| api.php?setor=Mobiliária
|
| api.php?tipo=Ipê
|
| api.php?nomeObjeto=Mesa&tipo=Ipê
|
| api.php?id=14
|
|--------------------------------------------------------------------------
*/

if ($metodo === 'GET') {


    /*
     * GET POR ID
     */

    if ($id !== null) {

        foreach (
            $objetos
            as $objeto
        ) {

            if (
                (int) $objeto['id']
                === $id
            ) {

                responder([

                    'base' =>
                        $baseSelecionada,

                    'dados' =>
                        $objeto

                ]);

            }

        }


        erro(
            'Objeto não encontrado.',
            404
        );

    }


    /*
     * Começa com todos os objetos
     */

    $resultado =
        $objetos;


    /*
     * FILTRO POR NOME DO OBJETO
     */

    if (
        isset($_GET['nomeObjeto'])
        &&
        trim($_GET['nomeObjeto']) !== ''
    ) {

        $nomeObjeto =
            textoMinusculo(
                trim(
                    $_GET['nomeObjeto']
                )
            );


        $resultado =
            array_filter(
                $resultado,
                function ($objeto)
                use ($nomeObjeto) {

                    return isset(
                        $objeto['nomeObjeto']
                    )
                    &&
                    strpos(
                        textoMinusculo(
                            $objeto['nomeObjeto']
                        ),
                        $nomeObjeto
                    ) !== false;

                }
            );

    }


    /*
     * FILTRO POR SETOR
     */

    if (
        isset($_GET['setor'])
        &&
        trim($_GET['setor']) !== ''
    ) {

        $setor =
            textoMinusculo(
                trim(
                    $_GET['setor']
                )
            );


        $resultado =
            array_filter(
                $resultado,
                function ($objeto)
                use ($setor) {

                    return isset(
                        $objeto['setor']
                    )
                    &&
                    strpos(
                        textoMinusculo(
                            $objeto['setor']
                        ),
                        $setor
                    ) !== false;

                }
            );

    }


    /*
     * FILTRO POR TIPO
     */

    if (
        isset($_GET['tipo'])
        &&
        trim($_GET['tipo']) !== ''
    ) {

        $tipo =
            textoMinusculo(
                trim(
                    $_GET['tipo']
                )
            );


        $resultado =
            array_filter(
                $resultado,
                function ($objeto)
                use ($tipo) {

                    return isset(
                        $objeto['tipo']
                    )
                    &&
                    strpos(
                        textoMinusculo(
                            $objeto['tipo']
                        ),
                        $tipo
                    ) !== false;

                }
            );

    }


    /*
     * ORDENAÇÃO
     */

    $camposOrdenacao = [

        'id',
        'nomeObjeto',
        'setor',
        'tipo'

    ];


    $sort =
        $_GET['sort']
        ?? 'id';


    $order =
        strtolower(
            $_GET['order']
            ?? 'asc'
        );


    if (
        !in_array(
            $sort,
            $camposOrdenacao,
            true
        )
    ) {

        erroValidacao([

            'sort' =>
                'Campo de ordenação inválido. Use id, nomeObjeto, setor ou tipo.'

        ]);

    }


    if (
        !in_array(
            $order,
            [
                'asc',
                'desc'
            ],
            true
        )
    ) {

        erroValidacao([

            'order' =>
                'Direção inválida. Use asc ou desc.'

        ]);

    }


    usort(
        $resultado,
        function ($a, $b)
        use ($sort, $order) {

            $valorA =
                $a[$sort]
                ?? '';

            $valorB =
                $b[$sort]
                ?? '';


            if (
                $sort === 'id'
            ) {

                $comparacao =
                    (int) $valorA
                    <=>
                    (int) $valorB;

            }

            else {

                $comparacao =
                    strcasecmp(
                        (string) $valorA,
                        (string) $valorB
                    );

            }


            return
                $order === 'desc'
                    ? -$comparacao
                    : $comparacao;

        }
    );


    /*
     * RESPOSTA
     */

    responder([

        'base' =>
            $baseSelecionada,

        'total' =>
            count($resultado),

        'filtros' => [

            'nomeObjeto' =>
                $_GET['nomeObjeto']
                ?? null,

            'setor' =>
                $_GET['setor']
                ?? null,

            'tipo' =>
                $_GET['tipo']
                ?? null

        ],

        'ordenacao' => [

            'campo' =>
                $sort,

            'direcao' =>
                $order

        ],

        'dados' =>
            array_values(
                $resultado
            )

    ]);

}


/*
|--------------------------------------------------------------------------
| POST
|--------------------------------------------------------------------------
*/

if ($metodo === 'POST') {

    $dados =
        lerJson();


    $erros =
        validarObjeto(
            $dados
        );


    if ($erros) {

        erroValidacao(
            $erros
        );

    }


    $novoId =
        proximoId(
            $objetos
        );


    $novoObjeto = [

        'id' =>
            $novoId,

        'nomeObjeto' =>
            trim(
                $dados['nomeObjeto']
            ),

        'setor' =>
            trim(
                $dados['setor']
            ),

        'tipo' =>
            trim(
                $dados['tipo']
            )

    ];


    $objetos[] =
        $novoObjeto;


    salvarObjetos(
        $arquivo,
        $objetos
    );


    responder([

        'message' =>
            'Objeto criado com sucesso.',

        'base' =>
            $baseSelecionada,

        'dados' =>
            $novoObjeto

    ], 201);

}


/*
|--------------------------------------------------------------------------
| PUT
|--------------------------------------------------------------------------
*/

if ($metodo === 'PUT') {

    if ($id === null) {

        erro(
            'Informe o ID do objeto.',
            400
        );

    }


    $dados =
        lerJson();


    $erros =
        validarObjeto(
            $dados
        );


    if ($erros) {

        erroValidacao(
            $erros
        );

    }


    foreach (
        $objetos
        as $indice => $objeto
    ) {

        if (
            (int) $objeto['id']
            === $id
        ) {

            $objetos[$indice] = [

                'id' =>
                    $id,

                'nomeObjeto' =>
                    trim(
                        $dados['nomeObjeto']
                    ),

                'setor' =>
                    trim(
                        $dados['setor']
                    ),

                'tipo' =>
                    trim(
                        $dados['tipo']
                    )

            ];


            salvarObjetos(
                $arquivo,
                $objetos
            );


            responder([

                'message' =>
                    'Objeto atualizado com PUT.',

                'base' =>
                    $baseSelecionada,

                'dados' =>
                    $objetos[$indice]

            ]);

        }

    }


    erro(
        'Objeto não encontrado.',
        404
    );

}


/*
|--------------------------------------------------------------------------
| PATCH
|--------------------------------------------------------------------------
*/

if ($metodo === 'PATCH') {

    if ($id === null) {

        erro(
            'Informe o ID do objeto.',
            400
        );

    }


    $dados =
        lerJson();


    if (empty($dados)) {

        erroValidacao([

            'body' =>
                'Envie pelo menos um campo para atualizar.'

        ]);

    }


    $camposPermitidos = [

        'nomeObjeto',
        'setor',
        'tipo'

    ];


    foreach (
        $dados
        as $campo => $_
    ) {

        if (
            !in_array(
                $campo,
                $camposPermitidos,
                true
            )
        ) {

            erroValidacao([

                $campo =>
                    'Campo não permitido.'

            ]);

        }

    }


    $erros =
        validarObjeto(
            $dados,
            true
        );


    if ($erros) {

        erroValidacao(
            $erros
        );

    }


    foreach (
        $objetos
        as $indice => $objeto
    ) {

        if (
            (int) $objeto['id']
            === $id
        ) {


            if (
                array_key_exists(
                    'nomeObjeto',
                    $dados
                )
            ) {

                $objetos[$indice]['nomeObjeto'] =
                    trim(
                        $dados['nomeObjeto']
                    );

            }


            if (
                array_key_exists(
                    'setor',
                    $dados
                )
            ) {

                $objetos[$indice]['setor'] =
                    trim(
                        $dados['setor']
                    );

            }


            if (
                array_key_exists(
                    'tipo',
                    $dados
                )
            ) {

                $objetos[$indice]['tipo'] =
                    trim(
                        $dados['tipo']
                    );

            }


            salvarObjetos(
                $arquivo,
                $objetos
            );


            responder([

                'message' =>
                    'Objeto atualizado com PATCH.',

                'base' =>
                    $baseSelecionada,

                'dados' =>
                    $objetos[$indice]

            ]);

        }

    }


    erro(
        'Objeto não encontrado.',
        404
    );

}


/*
|--------------------------------------------------------------------------
| DELETE
|--------------------------------------------------------------------------
*/

if ($metodo === 'DELETE') {

    if ($id === null) {

        erro(
            'Informe o ID do objeto.',
            400
        );

    }


    foreach (
        $objetos
        as $indice => $objeto
    ) {

        if (
            (int) $objeto['id']
            === $id
        ) {

            $objetoExcluido =
                $objeto;


            array_splice(
                $objetos,
                $indice,
                1
            );


            salvarObjetos(
                $arquivo,
                $objetos
            );


            responder([

                'message' =>
                    'Objeto excluído com sucesso.',

                'base' =>
                    $baseSelecionada,

                'dados' =>
                    $objetoExcluido

            ]);

        }

    }


    erro(
        'Objeto não encontrado.',
        404
    );

}


/*
|--------------------------------------------------------------------------
| MÉTODO NÃO PERMITIDO
|--------------------------------------------------------------------------
*/

erro(
    'Método não permitido.',
    405
);
