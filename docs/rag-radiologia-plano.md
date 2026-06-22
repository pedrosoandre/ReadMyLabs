# Plano técnico — RAG de radiologia (caminho LAUDO)

> **Status:** plano de implementação. Nada implementado ainda — esse documento
> alinha decisões antes de codar. Veja [[radiologia-ia-roadmap]] para a trilha
> regulatória (CADx/teleradiologia); este plano é a frente **legal e segura**:
> melhorar a explicação do **laudo escrito**, sem cruzar a linha do diagnóstico.

## Objetivo

Hoje, no caminho `responderImagem()` → LAUDO, o Claude redige o resumo leigo **sem
contexto aterrado**: ele explica "consolidação no LSE" usando conhecimento próprio,
podendo inventar nuance, errar termo regional, ou perder precisão clínica. O RAG
de radiologia aterra essa redação: o Claude só usa **definições recuperadas de
fontes públicas** + o texto do laudo do paciente.

Resultado esperado: explicações **mais precisas, mais consistentes em pt-BR
e auditáveis** (cada definição usada vem de uma `kb_chunks.fonte_id` rastreável,
exatamente igual ao RAG laboratorial atual).

## Princípio (vem antes do código)

**Reuso de `rag/`, não subsistema novo.** Quase tudo já existe:
- Embeddings (`voyage.php`), busca vetorial (`vetor.php`), resolver (`resolver.php`)
- Tabelas `kb_fontes`, `kb_marcadores`, `kb_chunks`, `kb_vetores`
- Pipeline de ingestão (`rag/ingest/`)
- Falha-para-desligado (`ragAtivo()`)

Não duplicar nada — só **estender**. Mesma fronteira de módulo, mesmo princípio
"runtime vs offline", mesma interface única.

## Mudanças no schema (aditivo, idempotente)

Arquivo novo: `rag/schema_rag_radio.sql`. Roda **depois** de `schema_rag.sql`.

```sql
-- Distingue domínio do marcador. Default 'lab' preserva os registros existentes.
ALTER TABLE kb_marcadores
  ADD COLUMN IF NOT EXISTS dominio ENUM('lab','radio') NOT NULL DEFAULT 'lab',
  ADD INDEX IF NOT EXISTS idx_dominio (dominio);

-- Mesmo para chunks (alguns chunks são de tema geral, sem marcador vinculado).
ALTER TABLE kb_chunks
  ADD COLUMN IF NOT EXISTS dominio ENUM('lab','radio') NOT NULL DEFAULT 'lab',
  ADD INDEX IF NOT EXISTS idx_chunks_dominio (dominio);
```

**Por quê separar por domínio:** sem isso, a busca vetorial de "consolidação"
poderia recuperar trecho de "consolidação óssea pós-fratura" (laboratorial não
tem isso, mas estamos pensando à frente: glossários médicos genéricos compartilham
termos). O domínio é o filtro barato (índice MySQL) antes do cosseno.

## Mudança em `vetorBuscar()` e `resolverMarcador()`

`rag/lib/vetor.php::vetorBuscar()` ganha parâmetro opcional `dominio`:

```php
function vetorBuscar(PDO $db, array $vecQueryNorm, string $refTipo, int $k = 10,
                    ?string $dominio = null): array
```

Quando `$dominio !== null`, filtra a query SQL por `WHERE m.dominio = :dom` (join
em `kb_marcadores` ou `kb_chunks` conforme `$refTipo`). Default `null` = comportamento
atual (sem filtro), garante retrocompatibilidade.

Novas funções em `rag/lib/resolver.php`:

```php
function resolverTermoRadiologico(PDO $db, string $termo, float $limiar = 0.75): ?array
function recuperarContextoRadio(PDO $db, string $textoLaudo, int $topK = 5): array
```

A segunda é a chave: recebe o **texto inteiro do laudo**, extrai termos
candidatos (heurística simples: substantivos médicos comuns via lista
curada + regex), busca cada um na base, retorna os trechos mais relevantes
agrupados pra entrar no prompt do Claude.

## Integração com `responderImagem()` — caminho LAUDO

Adicionar **antes da chamada ao Claude**, dentro do `if ($laudoTexto !== '')`:

```php
$contextoRagBloco = '';
if (function_exists('recuperarContextoRadio')) {
    $trechos = recuperarContextoRadio($db, $laudoTexto, 6);
    if ($trechos) {
        $contextoRagBloco = "\n<glossario>\n"
            . implode("\n---\n", array_map(fn($t) =>
                ($t['titulo'] ? "[{$t['titulo']}] " : '') . $t['texto'], $trechos))
            . "\n</glossario>\n";
    }
}
$prompt = "Tipo de entrada: LAUDO ...\n<laudo>\n$laudoTexto\n</laudo>"
        . $contextoRagBloco . $ctxBloco
        . "Explique para a pessoa em linguagem simples ...";
```

E o **system prompt ganha regra adicional**:

> (8) Se houver um bloco `<glossario>`, use-o como fonte primária para definir
> termos técnicos. Não invente definições para termos do laudo que não estejam
> no glossário — explique apenas em termos gerais ("achado descrito pelo
> radiologista") e oriente a pessoa a perguntar ao médico.

**Falha-para-desligado:** sem `recuperarContextoRadio` (RAG desligado ou tabelas
vazias), `$contextoRagBloco === ''` e o Claude redige como hoje. Zero risco
de quebrar o que está em produção.

**Custo de token:** ~500–1500 tokens extras por chamada (6 chunks × 100–250
tokens cada). No `MODELO_IMAGEM` (Sonnet 4.6) cai em ~$0.003 a mais por análise
— irrelevante. Em troca, ganhamos aterramento e auditabilidade.

## Fontes públicas pt-BR (mapeadas)

| Fonte | Licença | O que extrair | Status |
|---|---|---|---|
| **RadLex** (RSNA, ontologia radiológica) | RSNA Open License (atribuição) | termos canônicos + sinônimos + descrições curtas | tradução pt-BR existe parcial via CBR — verificar |
| **CBR/SBR** (Colégio Brasileiro de Radiologia + Sociedade Brasileira) | público (atribuir) | glossários técnicos pt-BR, diretrizes de uso de exames | sites: cbr.org.br, sbradiologia.org.br |
| **Manuais SUS / Min. Saúde** (radiologia básica em UBS) | público (BR) | indicações + descrição de exames comuns (RX tórax, USG abdome, TC crânio) | bvsms.saude.gov.br |
| **MedlinePlus PT-BR** (NIH) | uso autorizado com atribuição | descrições leigas de exames de imagem | medlineplus.gov/spanish (PT parcial) |
| **Wikipedia médica pt-BR** | CC-BY-SA | descrições gerais (boa para "o que é uma RM?") | citar autoria |
| **Manuais Fleury/DASA/Hermes Pardini** | **texto protegido** | só **fatos** (tipos de exame, preparo) — NÃO copiar texto | já é a regra do RAG laboratorial |

**Regra preservada do RAG laboratorial:** descrições leigas próprias nossas em
`kb_marcadores.descricao_leiga`. Textos longos vão em `kb_chunks` **só de fontes
com licença explícita**.

## Termos curados de partida (~150 termos cobrem 80% dos laudos comuns)

Não vai dar pra ingerir o RadLex inteiro (40k+ termos) e seria contraprodutivo
(varredura linear O(N) — ver `rag/README.md`). Começamos pequeno e curado:

### Descritores de achado (termos do laudo)
consolidação, opacidade, infiltrado, atelectasia, derrame pleural, pneumotórax,
nódulo pulmonar, massa, cavitação, espessamento, fibrose, enfisema, bronquiectasia,
broncograma aéreo, hipertransparência, calcificação, cardiomegalia, congestão,
hipotransparência, velamento, sombra, contornos, limites, dimensões, sinal,
realce, hipodensidade, hiperdensidade, hipocaptante, hipercaptante, hipossinal,
hipersinal, restrição à difusão, edema, hemorragia, isquemia, lesão expansiva,
desvio de linha média, efeito de massa, dilatação ventricular, atrofia

### Estruturas anatômicas (orientação ao paciente)
lobo superior direito (LSD), lobo médio, lobo inferior direito (LID), lobo superior
esquerdo (LSE), lobo inferior esquerdo (LIE), língula, hilo pulmonar, mediastino,
seio costofrênico, ápice pulmonar, base pulmonar, traqueia, brônquios,
parênquima pulmonar, pleura, diafragma, área cardíaca, aorta, silhueta cardíaca,
substância branca, substância cinzenta, ventrículos cerebrais, cerebelo, tronco
cerebral, sulcos corticais, cisternas da base, hipocampo, fossa posterior,
fossa anterior, sela túrcica, fígado, baço, pâncreas, rins, vesícula biliar,
vias biliares, bexiga, próstata, útero, ovários

### Tipos de exame
raio-x de tórax, raio-x simples, tomografia computadorizada (TC), TC de tórax,
TC de crânio, TC de abdome, ressonância magnética (RM), RM de crânio, RM de
coluna, RM de joelho, RM de ombro, ultrassom (USG), USG abdominal, USG
transvaginal, USG obstétrico, USG morfológico, mamografia, densitometria óssea,
cintilografia, PET-CT, angiografia, urografia excretora, enema opaco

### Termos de magnitude/qualificação
discreto, leve, moderado, acentuado, importante, focal, difuso, multifocal,
bilateral, unilateral, simétrico, assimétrico, regular, irregular, bem delimitado,
mal delimitado, homogêneo, heterogêneo

### Achados qualitativos não-diagnósticos
calipers, réguas, setas, medidas, marcação, círculo (esses entram como "marcação
de profissional", reforçando a triagem que já existe — ver [[imagem-subsistema]])

## Ingestão (CLI offline, novos scripts)

Padrão idêntico ao laboratorial — `rag/ingest/` (nunca deploya, roda 1× na sua
máquina ou via SSH no servidor):

```
rag/ingest/
  04_seed_radio.php          # NOVO — semeia ~150 termos curados acima
  05_radlex_import.php       # NOVO — opcional, importa CSV do RadLex pt-BR
  06_radio_manuais.php       # NOVO — opcional, importa manuais públicos em texto
  03_embeddings.php          # já existe — gera vetores para o que ainda não tem
```

Ordem de execução:
```bash
# 1) schema aditivo
mysql -u U -p DB < rag/schema_rag_radio.sql

# 2) seed mínimo (suficiente pra ativar a feature)
php rag/ingest/04_seed_radio.php

# 3) (opcional) RadLex se baixado em ingest/fontes/radlex-ptbr.csv
php rag/ingest/05_radlex_import.php ingest/fontes/radlex-ptbr.csv

# 4) (opcional) manuais SUS/CBR em texto plano
php rag/ingest/06_radio_manuais.php ingest/fontes/cbr_glossario.txt "CBR Glossário" "público"

# 5) gera embeddings — já compatível com o domínio novo
php rag/ingest/03_embeddings.php
```

`03_embeddings.php` não precisa mudar: ele consulta `kb_marcadores` e `kb_chunks`
sem vetor — funciona pra qualquer `dominio`.

## Custos estimados

- **Voyage embeddings** (`voyage-3-large`): ~$0.18 por milhão de tokens. Os ~150
  termos + descrições curtas geram <50k tokens → **< $0.01** pra ativar a base mínima.
  RadLex completo (se importado): ~5M tokens → **~$0.90**.
- **Claude redação aterrada** (Sonnet 4.6): ~$3 input / $15 output por milhão.
  +1500 tokens por análise = **+~$0.005** por laudo. Em 1000 laudos/mês = +$5/mês.

Total operacional: trocado em miúdos.

## Próximos passos concretos (se aprovar este plano)

Ordem sugerida e tempo estimado:

1. **Schema aditivo** + funções de domínio em `vetorBuscar()` — 1h
2. **`recuperarContextoRadio()`** + extração de termos do laudo — 2h
3. **Integração em `responderImagem()`** (LAUDO path) + system prompt — 30 min
4. **`04_seed_radio.php`** com os ~150 termos curados — 1h
5. **Testar localmente** com um laudo de exemplo (pipeline.php) — 30 min
6. **Deploy + rodar ingestão no servidor** (mesmo SSH que o RAG lab usa) — 30 min
7. **Monitorar** qualidade das explicações: ler ~20 análises reais antes e depois
   (sem identificação — só ver a qualidade da redação) — manual

**Não-objetivos desta fase** (deliberados):
- Importar RadLex completo (depois, se valer a pena)
- Modelo próprio de extração de termos do laudo (Claude Haiku resolve)
- Cobertura de modalidades raras (PET, medicina nuclear) — primeiro fechar RX/TC/RM/USG comuns
- Mudar o caminho FILME — esse continua não-diagnóstico, sem RAG (RAG no filme
  seria material pra LLM "interpretar achados", o que cruzaria a linha)

## Decisões em aberto (você decide)

1. **RadLex pt-BR existe acessível?** Confirmar com CBR/RSNA antes de planejar
   importação. Se só inglês, ou traduzir (custo) ou ficar só na lista curada.
2. **Voyage ou alternativa?** Voyage já está integrado. Trocar (ex.: por modelo
   da OpenAI ou local) não faz sentido aqui — só se mudarmos o RAG laboratorial junto.
3. **Limiar de score** — começar em 0.72 (mais permissivo que o laboratorial 0.78,
   porque termos radiológicos têm mais sinônimos curtos) e ajustar lendo o output.
