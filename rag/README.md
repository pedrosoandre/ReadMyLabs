# ReadMyLabs — RAG de Exames (`rag/`)

Base de conhecimento curada para **identificar e contextualizar exames laboratoriais**.
Não é um repositório de PDFs de pacientes — é um RAG de **definições de referência**
(o que cada marcador é, sinônimos, faixas, significado clínico), construído sobre fontes
públicas e licenciadas.

## Arquitetura e organização (princípios — ler antes de codar)

Arquitetura vem **primeiro**: pense nas camadas e fronteiras antes de escrever código.
Nada de arquivo solto ou lógica espalhada — código organizado é requisito, não enfeite.

1. **Modularidade com fronteira clara.** `rag/` é um módulo autocontido: tem o próprio
   `lib/` (runtime), `ingest/` (offline), `schema_rag.sql` e este README. Não misturar
   responsabilidades do RAG com o núcleo do app.
2. **Camadas de responsabilidade única.** O fluxo é uma pipeline —
   extração → classificação local → recuperação (RAG) → redação (IA). Cada camada faz
   UMA coisa e não conhece o interior da seguinte.
3. **Dependência externa sempre atrás de uma interface.** Busca vetorial → `vetorBuscar()`;
   embeddings → `voyage.php`; IA → `chamarClaude()`. Trocar a implementação (ex.: MariaDB
   → pgvector/Qdrant) NÃO pode obrigar a refatorar o resto.
4. **Falha para desligado.** Toda feature nova degrada sem quebrar o núcleo: sem
   `VOYAGE_API_KEY`, `ragAtivo()` é `false` e o app roda como antes.
5. **Runtime vs offline separados.** `rag/lib/` deploya; `rag/ingest/` nunca vai ao
   servidor (roda 1x na sua máquina).
6. **Economia de token é decisão de arquitetura.** Local antes de IA; cache antes de
   chamada; a recuperação **aterra** o Claude (ele só usa o contexto fornecido).
7. **LGPD por design.** Nunca persistir dado sensível de paciente — a base é de
   *definições*, não de exames reais.

## Por que assim (e não "raspar PDFs de pacientes")

Coletar exames reais de pacientes — de qualquer base, pública ou privada — é **dado
pessoal sensível de saúde** (LGPD, Art. 11). Indexar isso num RAG nos tornaria
controladores desses dados, com todo o passivo. Para o objetivo real ("o que é este
exame?"), **não precisamos disso**: precisamos de uma base de *definições*. É mais
barato, melhor e legal.

## Arquitetura

```
INGESTÃO (offline, roda 1x na sua máquina — pasta ingest/)
  LOINC PT-BR + manuais públicos
      → kb_marcadores  (marcador canônico + sinônimos + código LOINC + unidade)
      → kb_chunks      (trechos de texto de referência, com fonte e licença)
      → Voyage embeddings → kb_vetores  (vetor normalizado, JSON em MariaDB)

CONSULTA (no servidor — lib/)
  exame do cliente → classificarExame() (regex local, como hoje)
      → termo não casou? → resolver.php  (embedding + cosseno → marcador canônico)
      → "o que é este exame?" → vetor.php (busca em kb_chunks) → Claude redige
                                  ↑ zero token Claude até a redação final
```

### Embeddings: Voyage AI
A **Anthropic não tem API de embeddings**; o caminho recomendado é a Voyage.
- `voyage-3-large` — lidera em recuperação **médica**.
- `voyage-4-large` — melhor **multilíngue** (PT-BR), 32k de contexto, 1024 dims.
- Reranker `rerank-2` — refina os resultados antes do Claude.
- Confirme os nomes atuais em https://docs.voyageai.com/docs/embeddings

### Vetores em MariaDB (sem pgvector) — trade-off consciente
`pgvector` é exclusivo do PostgreSQL; MariaDB 10.x não tem tipo `VECTOR`. **pgvector é
mais rápido** (índice HNSW/ANN — não varre tudo), mas exige Postgres. Aqui guardamos o
embedding como **JSON normalizado** e calculamos **cosseno = produto escalar** em PHP
(`lib/vetor.php`). Isso é uma **varredura linear O(N)**: ótima para a nossa escala
(milhares de marcadores curados — NÃO os 100 mil do LOINC inteiro), na casa dos
milissegundos; ruim para centenas de milhares.

Notas que importam:
- **JSON ≠ velocidade.** JSON é só a serialização do vetor (poderia ser BLOB binário, mesma
  velocidade). O que define a velocidade é *varredura linear vs índice ANN*, não o formato.
- **Markdown não serve para o vetor** (cosseno precisa de números) — só para o **texto de
  referência** em `kb_chunks.texto`.
- **Migração sem refatorar.** A busca fica atrás de `vetorBuscar()`. Quando a escala exigir
  índice ANN, troque só o interior por **Supabase (Postgres + pgvector, free tier, via
  cURL)** ou **Qdrant** — resolver, endpoint e ingestão ficam intactos.
- **Regra:** manter a base **curada** para o MariaDB seguir suficiente o máximo de tempo.

## Pré-requisitos (você precisa providenciar 2 coisas)

1. **Chave Voyage** → `VOYAGE_API_KEY` no `.env` (https://dash.voyageai.com).
2. **Pacote LOINC** (grátis, exige cadastro) → https://loinc.org/downloads/
   - Baixe a tabela LOINC (`Loinc.csv`) e a variante linguística **pt-BR**.
   - Coloque em `rag/ingest/fontes/`.

Sem esses dois, o app continua funcionando normalmente — o RAG fica **desligado**
(`ragAtivo()` retorna `false`) e nada quebra.

## Como popular (ordem)

```bash
# 1) cria as tabelas
mysql -u USER -p DBNAME < rag/schema_rag.sql

# 2) semeia kb_marcadores com os 46 marcadores que já existem (testável já)
php rag/ingest/00_seed_kb_from_marcadores.php

# 3) popular kb_marcadores — escolha A (rápido, sem download/Voyage) ou B (completo):
#    A) lista curada de ~90 exames comuns no Brasil
php rag/ingest/01_seed_curado.php
#    B) LOINC PT-BR (superset; exige os arquivos em ingest/fontes/)
php rag/ingest/01_loinc_import.php ingest/fontes/Loinc.csv ingest/fontes/ptBR.csv

# 4) (opcional) importa trechos de manuais públicos já convertidos p/ texto
php rag/ingest/02_manuais_import.php ingest/fontes/sus_bh.txt "Manual SUS-BH" "público"

# 5) gera os embeddings de tudo que ainda não tem vetor (precisa de VOYAGE_API_KEY)
php rag/ingest/03_embeddings.php
```

## Integração com o app (já feita)

- `lib/referencia.php` → `expandirTermosComKb()`: a classificação local passa a
  reconhecer **sinônimos do LOINC** dos marcadores que já temos. Zero token, zero
  embeddings — só precisa do `kb_marcadores` populado. **No-op seguro** se a tabela
  não existir.
- `analisar.php` → modo `tipo=explicar`: recebe um termo/exame e responde "o que é".
  Usa busca vetorial + Claude. Degrada com elegância se o RAG estiver desligado.

## Fontes e licenças (ver tabela `kb_fontes`)

| Fonte | Licença | Uso |
|---|---|---|
| LOINC + tradução PT-BR (HL7 Brasil) | LOINC License (grátis) | marcador canônico + sinônimos |
| Manual de Exames SUS-BH (PMBH) | documento público | faixas + indicação |
| Valores de referência pop. BR (SciELO/PNS) | artigo aberto (citar) | faixas BR |
| Manuais Fleury/Hermes Pardini | **texto protegido** | só fatos (faixas); **não copiar texto** |

> Regra: faixas de referência são **fatos** (sem direito autoral); o texto explicativo
> dos laboratórios **tem** direito autoral — escrevemos a explicação leiga nós mesmos.
