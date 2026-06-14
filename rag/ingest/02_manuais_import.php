<?php
// Importa trechos de texto de fontes de referência PÚBLICAS para kb_chunks
// (a base do "o que é este exame?"). Recebe um arquivo .txt já extraído do
// PDF público (use pdftotext ou copie/cole) e o fatia em trechos.
//
// IMPORTANTE (licença): use só fontes públicas (Manual SUS-BH, artigos
// abertos, LOINC). NÃO cole texto explicativo de laboratórios privados
// (Fleury/Hermes Pardini) — o texto deles tem direito autoral; as FAIXAS
// (fatos) podem ser usadas, o texto não.
//
// Uso:
//   php rag/ingest/02_manuais_import.php arquivo.txt "Nome da fonte" "licença" [url]

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

require_once __DIR__ . '/../../loads_env.php';
require_once __DIR__ . '/../../db.php';
loadEnv(__DIR__ . '/../../.env');

$arquivo = $argv[1] ?? null;
$nomeFonte = $argv[2] ?? null;
$licenca   = $argv[3] ?? 'público';
$url       = $argv[4] ?? null;

if (!$arquivo || !is_file($arquivo) || !$nomeFonte) {
    fwrite(STDERR, "Uso: php rag/ingest/02_manuais_import.php arquivo.txt \"Nome da fonte\" \"licença\" [url]\n");
    exit(1);
}

$db = db();
$db->prepare('INSERT IGNORE INTO kb_fontes (nome, url, licenca) VALUES (:n, :u, :l)')
   ->execute([':n' => $nomeFonte, ':u' => $url, ':l' => $licenca]);
$fonteId = (int) $db->query('SELECT id FROM kb_fontes WHERE nome=' . $db->quote($nomeFonte))->fetchColumn();

$texto = file_get_contents($arquivo);
$chunks = fatiarTexto($texto, 1200, 150); // ~1200 chars, 150 de sobreposição

$ins = $db->prepare('INSERT INTO kb_chunks (titulo, texto, fonte_id) VALUES (:t, :x, :f)');
$n = 0;
foreach ($chunks as $i => $c) {
    $c = trim($c);
    if (mb_strlen($c) < 80) continue; // descarta fragmentos curtos
    $ins->execute([':t' => "$nomeFonte #" . ($i + 1), ':x' => $c, ':f' => $fonteId]);
    $n++;
}

echo "kb_chunks: $n trechos importados de \"$nomeFonte\".\n";
echo "Próximo: php rag/ingest/03_embeddings.php\n";

/** Fatia texto em janelas com sobreposição, respeitando limites de palavra. */
function fatiarTexto(string $texto, int $tam, int $overlap): array {
    $texto = preg_replace('/\s+/u', ' ', trim($texto));
    $len = mb_strlen($texto);
    if ($len <= $tam) return [$texto];
    $out = [];
    $i = 0;
    while ($i < $len) {
        $pedaco = mb_substr($texto, $i, $tam);
        // tenta cortar no último espaço para não partir palavra
        if ($i + $tam < $len) {
            $ult = mb_strrpos($pedaco, ' ');
            if ($ult !== false && $ult > $tam * 0.6) $pedaco = mb_substr($pedaco, 0, $ult);
        }
        $out[] = $pedaco;
        $i += max(1, mb_strlen($pedaco) - $overlap);
    }
    return $out;
}
